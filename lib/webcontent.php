<?php
/**
 * From a website to a week of social posts.
 *
 * The job here is not summarising. A summary of a company's website is a
 * paragraph nobody wants to read on LinkedIn. What a marketing team does is
 * decide what to say, to whom, from which angle, towards what — and then
 * write it. So the pipeline is: read the site, work out the brand and its
 * audience, PLAN a spread of posts that do not all say the same thing, and
 * only then write each one.
 *
 * The rule that shapes all of it: the AI may not make anything up. Not a
 * statistic, a customer, an award, a price or a result. Every page crawled
 * is stored with the sentences that carry its facts, and every generated
 * post is checked back against them — see fabo_claims_check(). A post whose
 * numbers are not in the evidence is held for a human rather than published.
 * The alternative is a portal that invents a client testimonial and posts it
 * to LinkedIn under somebody's real brand.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crawler.php';
require_once __DIR__ . '/google.php';
require_once __DIR__ . '/jobs.php';

/** The sheet the generated rows land on. */
const FABO_WC_SHEET = 'webcontent';
const FABO_WC_TABLE = 'web_content';

/** How many posts one crawl produces, unless the site is too thin for that. */
const FABO_WC_POSTS = 8;

/* ------------------------------------------------------------ the states */

/** The life of a row, in order. */
function fabo_wc_states(): array
{
    return ['DISCOVERED', 'EXTRACTED', 'READY_FOR_GENERATION', 'GENERATING', 'GENERATED',
            'REVIEW_REQUIRED', 'APPROVED', 'SCHEDULED', 'PUBLISHING', 'PUBLISHED',
            'FAILED', 'CANCELLED'];
}

/* -------------------------------------------------------- asking the model */

/**
 * Ask Gemini a question whose answer must be JSON.
 *
 * A model asked for JSON returns JSON wrapped in a code fence about a third
 * of the time, and prose around it more rarely. Both are recoverable and
 * neither is worth failing a job over, so the braces are found rather than
 * trusted. Returns [ok, array|reason].
 */
/**
 * Where fabo_ai_json() sends its prompts.
 *
 * Gemini, unless something has been put in its place. The seam exists so the
 * pipeline around the model can be exercised without a live API key and
 * without spending quota — the planning, the claim checking, the row
 * building and the scheduling are all ordinary code, and none of them should
 * need a paid call to prove they work.
 *
 * Passing a callable swaps it; passing false puts Gemini back.
 */
function fabo_ai_transport($set = null)
{
    static $fn = null;
    if ($set === false) {
        $fn = null;
    } elseif (is_callable($set)) {
        $fn = $set;
    }
    return $fn;
}

function fabo_ai_json(string $prompt, int $maxTokens = 4096): array
{
    $swap = fabo_ai_transport();
    if ($swap !== null) {
        return $swap($prompt, $maxTokens);
    }
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    if ($key === '') {
        return [false, 'No Gemini API key is set. Add GEMINI_API_KEY to config.local.php.'];
    }
    /* The configured model first, then fast ones. Two things made websites
       stall here: the main model's daily quota running out, and the fallback
       after it being a slow model that the host cut off mid-answer. So the
       fallbacks are the "lite" models, which answer a whole website in a few
       seconds; a model out of quota is skipped at once and remembered for an
       hour; and no call is allowed to run long enough to be cut off. */
    $models = array_values(array_unique(array_filter(array_map('trim', array_merge(
        [fabo_env_or('GEMINI_MODEL', 'gemini-flash-latest')],
        explode(',', fabo_env_or('GEMINI_FALLBACK_MODELS',
                'gemini-flash-lite-latest,gemini-3.5-flash-lite,gemini-3.1-flash-lite')))))));

    $spent = json_decode(fabo_setting('gemini_spent_models', '{}'), true) ?: [];
    $spent = array_filter($spent, static fn($until) => (int) $until > time());
    $usable = array_values(array_filter($models, static fn($m) => !isset($spent[$m])));
    if (!$usable) {
        $usable = $models;                  // all marked spent: try anyway, quotas reset
    }

    $busy     = [429, 500, 502, 503, 504];
    $deadline = time() + 75;                // well inside what a host lets one request run
    $ok = false; $res = 'not attempted'; $status = 0;
    foreach ($usable as $model) {
        if (time() >= $deadline - 5) {
            break;
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        /* Room to spare. The newer models reason before they answer and that
           reasoning is paid out of this same allowance; at 3,072 a third of
           the posts came back cut off half way and were lost. */
        $gen = ['temperature'      => 0.35,
                'maxOutputTokens'  => max($maxTokens, 8192),
                'responseMimeType' => 'application/json'];
        /* 2.5 models think before answering unless told not to, and the
           thinking is spent out of maxOutputTokens -- enough of it and the
           JSON is cut off half way. This work needs no thinking. */
        if (str_contains($model, '2.5')) {
            $gen['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $payload = ['contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => $gen];
        for ($try = 1; $try <= 2; $try++) {
            $wait = max(10, min(40, $deadline - time()));
            [$ok, $res, $status] = fabo_http('POST', $url, $payload,
                                             ['Content-Type: application/json'], $wait);
            if ($status === 404) {
                break;                      // that model is not offered: try the next
            }
            if ($ok) {
                [$good, $data] = fabo_ai_parse($res);
                if ($good) {
                    return [true, $data];
                }
                $res = $data;               // cut off or not JSON: the next model may do better
                $ok  = false;
                break;
            }
            if ($status === 429 && is_string($res) && stripos($res, 'quota') !== false) {
                /* Out of quota is not "busy": asking again will not help today. */
                $spent[$model] = time() + 3600;
                try {
                    fabo_setting_put('gemini_spent_models', json_encode($spent));
                } catch (Throwable $e) {
                    // Remembering is a saving, not a requirement.
                }
                break;
            }
            if ($status === 0) {
                break;                      // timed out: a slow model, so move on
            }
            if (!in_array($status, $busy, true)) {
                break 2;                    // a refusal no wait will fix
            }
            if ($try < 2) {
                sleep(2);
            }
        }
    }
    return [false, is_string($res) && str_starts_with($res, 'Gemini')
                   ? $res : 'Gemini said: ' . (is_string($res) ? $res : json_encode($res))];
}

/** A Gemini answer as the JSON it was asked for. Returns [ok, array|reason]. */
function fabo_ai_parse(array $res): array
{
    $text = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        $text .= (string) ($part['text'] ?? '');
    }
    $text = trim($text);
    if ($text === '') {
        return [false, 'Gemini returned nothing ('
                     . ($res['candidates'][0]['finishReason'] ?? 'no reason given') . ').'];
    }
    $data = json_decode($text, true);
    if (!is_array($data)) {
        // Fenced, or with a sentence in front of it. Take the outermost braces.
        if (preg_match('~[\{\[].*[\}\]]~s', $text, $m)) {
            $data = json_decode($m[0], true);
        }
    }
    if (!is_array($data)) {
        return [false, 'Gemini did not answer with usable JSON ('
                     . ($res['candidates'][0]['finishReason'] ?? 'no reason given') . ').'];
    }
    return [true, $data];
}

/** The instruction every prompt carries, because it is the whole safety story. */
function fabo_ai_rules(): string
{
    return "RULES — these override everything else:\n"
         . "- Use ONLY what the SOURCE below says. You have no other knowledge of this company.\n"
         . "- Use the website's own wording wherever you can: its headings, service and product\n"
         . "  names, programme names and phrases, copied exactly as the site writes them.\n"
         . "- Fill every field you can from the source. Most fields can be answered from what\n"
         . "  the site says about itself; leave one empty only when the site truly says nothing.\n"
         . "- Never invent a statistic, customer, client name, testimonial, award, certification,\n"
         . "  partnership, price, result, product feature or claim.\n"
         . "- If a field cannot be filled from the source, return \"\" for it. An empty field is\n"
         . "  correct and useful; a plausible guess is a lie that gets published.\n"
         . "- Quote numbers only if the exact number appears in the source.\n"
         . "- Do not compare the company to named competitors.\n"
         . "- Write British English. No emoji in LinkedIn copy; at most two in Instagram copy.\n"
         . "- Return JSON only, matching the shape asked for.\n";
}

/** The crawled pages, trimmed to what fits in one prompt. */
function fabo_wc_source_digest(int $siteId, int $maxChars = 26000): array
{
    $pages = fabo_all('SELECT source_url, source_page_type, source_title, source_description,
                              source_content, source_evidence
                         FROM crawled_pages WHERE site_id = ?
                        ORDER BY FIELD(source_page_type,
                                       "Provided",
                                       "Homepage","About","Service","Product",
                                       "Feature","Pricing","Case Study","Customer Story",
                                       "Resource","Blog"), id', 'i', [$siteId]);
    $out   = [];
    $text  = '';
    $facts = [];
    foreach ($pages as $p) {
        $ev = json_decode((string) $p['source_evidence'], true) ?: [];
        foreach ($ev as $e) {
            $facts[] = $e;
        }
        /* Each page gets a slice, not the whole thing: twelve pages at two
           thousand characters tells the model far more about a company than
           one page at twenty-four thousand. */
        $chunk = "\n=== PAGE: " . $p['source_page_type'] . " — " . $p['source_title'] . "\n"
               . "URL: " . $p['source_url'] . "\n"
               . ($p['source_description'] !== '' ? "META: " . $p['source_description'] . "\n" : '')
               . mb_substr((string) $p['source_content'], 0, 2200) . "\n";
        if (mb_strlen($text) + mb_strlen($chunk) > $maxChars) {
            break;
        }
        $text .= $chunk;
        $out[] = $p;
    }
    return ['text' => $text, 'pages' => $out, 'facts' => array_slice(array_unique($facts), 0, 120)];
}

/* ------------------------------------------------------- 1. the brand */

/**
 * Work out who this company is, from its own website.
 *
 * Stored once per site and reused for every post, because the brand does not
 * change between Tuesday and Thursday and asking twelve times would give
 * twelve slightly different answers.
 */
function fabo_wc_extract_brand(int $siteId): array
{
    $site = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website record was not found.');
    }
    $d = fabo_wc_source_digest($siteId);
    if (trim($d['text']) === '') {
        throw new RuntimeException('Nothing was read from that site to work from.');
    }

    $prompt = "You are a brand strategist reading a company's website for the first time.\n\n"
        . fabo_ai_rules()
        . "\nReturn this exact JSON shape:\n"
        . '{"brand_name":"","brand_tagline":"","brand_description":"","brand_story":"",'
        . '"brand_mission":"","brand_values":"","brand_positioning":"","brand_personality":"",'
        . '"brand_voice":"","brand_tone":"","brand_keywords":"","brand_hashtags":"",'
        . '"business_category":"","industry":"","business_model":"","business_problem":"",'
        . '"business_solution":"","value_proposition":"","usp":"","differentiators":"",'
        . '"target_audience":"","audience_segment":"","audience_pain_points":"",'
        . '"audience_needs":"","audience_goals":"","social_proof":"","primary_keyword":""}'
        . "\n\nNotes on particular fields:\n"
        . "- brand_personality and brand_tone: two or three words, chosen to match how the\n"
        . "  site actually writes, not how a company like this usually writes.\n"
        . "- business_model: one of B2B, B2C, B2B2C, SaaS, Marketplace, Agency, Services,\n"
        . "  E-commerce, Other — or \"\" if the site does not make it clear.\n"
        . "- brand_hashtags: 3-6, drawn from the company's own words. No generic filler.\n"
        . "- social_proof: only things the site actually shows. \"\" if it shows none.\n"
        . "\nSOURCE — " . $site['website_url'] . "\n" . $d['text'];

    [$ok, $data] = fabo_ai_json($prompt, 3072);
    if (!$ok) {
        throw new RuntimeException((string) $data);
    }
    $data = array_map(static fn($v) => is_array($v) ? implode(', ', $v) : trim((string) $v), $data);
    $data['evidence'] = $d['facts'];

    fabo_exec('UPDATE website_sources SET brand_name = ?, brand_profile = ?, state = "EXTRACTED"
                WHERE id = ?', 'ssi',
              [mb_substr((string) ($data['brand_name'] ?? ''), 0, 190),
               json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $siteId]);
    return $data;
}

/** The stored brand profile, or an empty one. */
function fabo_wc_brand(int $siteId): array
{
    $row = fabo_one('SELECT brand_profile FROM website_sources WHERE id = ?', 'i', [$siteId]);
    $d   = json_decode((string) ($row['brand_profile'] ?? ''), true);
    return is_array($d) ? $d : [];
}

/* --------------------------------------------------- 2. the content plan */

/**
 * Decide what the posts should be, before writing any of them.
 *
 * Generating eight posts in one pass gives eight versions of the homepage.
 * Planning first — a spread of pillars, angles, funnel stages and source
 * pages — is what stops the calendar being the same post eight times with
 * different adjectives.
 */
function fabo_wc_plan(int $siteId, int $want = FABO_WC_POSTS): array
{
    $brand = fabo_wc_brand($siteId);
    $d     = fabo_wc_source_digest($siteId, 16000);
    if (!$d['pages']) {
        throw new RuntimeException('There are no crawled pages to plan from.');
    }

    /* Posts already on the calendar for this site -- the ones a person kept
       through a rewrite. The plan works around them rather than writing them
       a second time, and only fills the places that are left. */
    $kept = fabo_all('SELECT content_topic, linkedin_headline FROM `' . FABO_WC_TABLE . '`
                       WHERE site_id = ?', 'i', [$siteId]);
    $want = max(3, $want - count($kept));
    $keptNote = '';
    foreach ($kept as $k) {
        $keptNote .= '- ' . trim((string) $k['content_topic']) . ' / '
                   . trim((string) $k['linkedin_headline']) . "\n";
    }

    $menu = '';
    foreach ($d['pages'] as $i => $p) {
        $menu .= ($i + 1) . '. [' . $p['source_page_type'] . '] ' . $p['source_title']
               . ' — ' . $p['source_url'] . "\n";
    }

    $prompt = "You are a social media strategist planning a content calendar for one brand.\n\n"
        . fabo_ai_rules()
        . "\nPlan exactly " . $want . " posts. Each must say something DIFFERENT — a different\n"
        . "pillar, a different angle, and a different page of the site behind it. Do not plan\n"
        . "two posts that make the same point. If the site is too thin to support " . $want . "\n"
        . "genuinely distinct posts, plan fewer. Fewer good posts beats padding.\n\n"
        . "Spread them across the funnel: mostly TOFU and MOFU, at most two BOFU.\n"
        . "Alternate LinkedIn and Instagram, leaning LinkedIn for B2B.\n\n"
        . "Return JSON: {\"posts\":[{"
        . '"focus":"","content_pillar":"","content_topic":"","content_angle":"",'
        . '"content_objective":"","funnel_stage":"","content_type":"","platform":"",'
        . '"source_url":"","primary_message":"","key_benefit":"","pain_point":"",'
        . '"cta":"","cta_url":"","audience_segment":"","linkedin_type":""}]}' . "\n\n"
        . "Allowed values:\n"
        . "- focus: Brand Awareness, Product Awareness, Lead Generation, Education,\n"
        . "  Thought Leadership, Customer Success, Product Feature, Engagement, Trust Building,\n"
        . "  Conversion, Community, Event Promotion\n"
        . "- content_pillar: Educational, Product, Industry Insights, Thought Leadership,\n"
        . "  Customer Stories, Brand Story, Tips & Advice, Case Studies, Resources\n"
        . "- content_angle: Problem to Solution, How-to, Myth vs Reality, Before vs After,\n"
        . "  Data/Insight, Customer Story, Expert Advice, Product Demonstration, Checklist,\n"
        . "  Mistakes to Avoid, Industry Trend\n"
        . "- content_objective: Awareness, Engagement, Education, Consideration, Conversion\n"
        . "- funnel_stage: TOFU, MOFU, BOFU\n"
        . "- content_type: Single Image, Carousel, Text Post, Infographic, Quote, Announcement\n"
        . "- platform: LinkedIn or Instagram\n"
        . "- linkedin_type: the kind of LinkedIn post -- Text, Image, Multi-image, Carousel,\n"
        . "  Poll, Article, Event or Job. Use a mix across the calendar:\n"
        . "  Carousel for a guide, checklist, framework or step-by-step (a swipeable PDF);\n"
        . "  Image for an announcement, statistic or programme; Text for a story or opinion;\n"
        . "  Poll for a question the audience would want to answer (one at most);\n"
        . "  Article to send people to a page on the site; Event only for a real event,\n"
        . "  workshop or webinar the site mentions; Job only if the site lists a vacancy.\n"
        . "  Never Video -- there is no video to post.\n"
        . "- cta: Learn More, Read the Guide, Book a Demo, Contact Us, Start Free, Download,\n"
        . "  Register, Visit Website, Try Now\n"
        . "- source_url and cta_url must be addresses from the list below, copied exactly.\n\n"
        . "BRAND\n" . json_encode($brand, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\n"
        . ($keptNote !== ''
            ? "ALREADY ON THE CALENDAR -- do not plan these again, or anything saying the same:\n"
              . $keptNote . "\n"
            : '')
        . "PAGES AVAILABLE\n" . $menu . "\n"
        . "SOURCE\n" . $d['text'];

    [$ok, $data] = fabo_ai_json($prompt, 6144);
    if (!$ok) {
        throw new RuntimeException((string) $data);
    }
    $posts = $data['posts'] ?? (isset($data[0]) ? $data : []);
    if (!is_array($posts) || !$posts) {
        throw new RuntimeException('The plan came back empty.');
    }

    /* A URL the model invented is the commonest failure here, and it would
       become a broken call-to-action on a live post. Anything not in the
       crawl is replaced with the page the plan is about, or the homepage. */
    $known = array_column($d['pages'], 'source_url');
    $home  = $known[0] ?? '';
    foreach ($posts as $i => $p) {
        $src = fabo_url_normalise((string) ($p['source_url'] ?? ''));
        $posts[$i]['source_url'] = in_array($src, $known, true) ? $src : $home;
        $cta = fabo_url_normalise((string) ($p['cta_url'] ?? ''));
        $posts[$i]['cta_url'] = in_array($cta, $known, true) ? $cta : $posts[$i]['source_url'];
    }

    /* The model sometimes plans the same post twice under one topic, and a
       kept post must not come back either. One post per topic, compared
       without case or punctuation. */
    $seen = [];
    foreach ($kept as $k) {
        $seen[fabo_wc_same((string) $k['content_topic'])] = true;
    }
    $unique = [];
    foreach ($posts as $p) {
        $key = fabo_wc_same((string) ($p['content_topic'] ?? ''));
        if ($key !== '' && isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[]   = $p;
    }
    return fabo_wc_mix_kinds(array_slice($unique, 0, $want));
}

/**
 * Every post a LinkedIn kind, and a calendar that uses more than one.
 *
 * The model leaves the kind out about half the time and, left to itself,
 * plans eight image posts. So a missing kind is read from the content type,
 * and a calendar of four or more gets at least one carousel (the post most
 * like a guide) and one poll (the post most about engagement).
 */
function fabo_wc_mix_kinds(array $plan): array
{
    $kinds = ['Text', 'Image', 'Multi-image', 'Carousel', 'Document', 'Poll', 'Article',
              'Newsletter', 'Event', 'Job'];
    $have  = [];
    foreach ($plan as $i => $p) {
        $k = '';
        foreach ($kinds as $kind) {
            if (strcasecmp(trim((string) ($p['linkedin_type'] ?? '')), $kind) === 0) {
                $k = $kind;
            }
        }
        if ($k === '') {
            $ct = strtolower((string) ($p['content_type'] ?? ''));
            $k  = str_contains($ct, 'carousel') ? 'Carousel' : (str_contains($ct, 'text') ? 'Text' : 'Image');
        }
        $plan[$i]['linkedin_type'] = $k;
        $have[$k] = true;
    }
    if (count($plan) < 4) {
        return $plan;
    }
    $score = static function (array $p, array $words): int {
        $hay = strtolower(implode(' ', [$p['content_angle'] ?? '', $p['content_pillar'] ?? '',
                                        $p['content_objective'] ?? '', $p['focus'] ?? '',
                                        $p['content_topic'] ?? '']));
        $n = 0;
        foreach ($words as $w) {
            $n += substr_count($hay, $w);
        }
        return $n;
    };
    $pick = static function (array $plan, array $words, array $not) use ($score): ?int {
        $best = null;
        $top  = -1;
        foreach ($plan as $i => $p) {
            if (in_array($p['linkedin_type'], $not, true)) {
                continue;
            }
            $s = $score($p, $words);
            if ($s > $top) {
                [$best, $top] = [$i, $s];
            }
        }
        return $best;
    };
    $keep = ['Carousel', 'Poll', 'Event', 'Job'];
    if (empty($have['Carousel'])) {
        $i = $pick($plan, ['how-to', 'checklist', 'mistakes', 'myth', 'tips', 'educational',
                           'guide', 'steps', 'education', 'resources'], $keep);
        if ($i !== null) {
            $plan[$i]['linkedin_type'] = 'Carousel';
        }
    }
    if (empty($have['Poll'])) {
        $i = $pick($plan, ['engagement', 'community', 'trend', 'insight', 'awareness',
                           'tofu'], $keep);
        if ($i !== null) {
            $plan[$i]['linkedin_type'] = 'Poll';
        }
    }
    return $plan;
}

/** At most $max characters, cut between words rather than inside one. */
function fabo_wc_short(string $s, int $max): string
{
    if (mb_strlen($s) <= $max) {
        return $s;
    }
    $cut = mb_substr($s, 0, $max + 1);
    $at  = mb_strrpos($cut, ' ');
    return rtrim($at !== false && $at > $max * 0.5 ? mb_substr($cut, 0, $at) : mb_substr($s, 0, $max), ' ,;:-&');
}

/** Text reduced to what makes two topics or headlines "the same". */
function fabo_wc_same(string $s): string
{
    return trim(preg_replace('~[^\p{L}\p{N}]+~u', ' ', mb_strtolower($s)) ?? '');
}

/* ------------------------------------------------------- 3. the writing */

/** Write one post, with its creative brief, from its plan entry. */
function fabo_wc_write(int $siteId, array $plan): array
{
    $brand = fabo_wc_brand($siteId);
    $page  = fabo_one('SELECT * FROM crawled_pages WHERE site_id = ? AND source_url = ?',
                      'is', [$siteId, (string) ($plan['source_url'] ?? '')]);
    if (!$page) {
        $page = fabo_one('SELECT * FROM crawled_pages WHERE site_id = ? ORDER BY id LIMIT 1',
                         'i', [$siteId]);
    }
    $evidence = json_decode((string) ($page['source_evidence'] ?? ''), true) ?: [];
    $platform = (string) ($plan['platform'] ?? 'LinkedIn');

    $prompt = "You are a senior copywriter for this brand, writing ONE social post.\n\n"
        . fabo_ai_rules()
        . "\nThe post must stand on its own. No \"in today's fast-paced world\", no rhetorical\n"
        . "question opening, no \"we are excited to announce\" unless the source announces\n"
        . "something. Lead with the thing worth reading.\n\n"
        . "linkedin_copy: 120-220 words, written the way the best company pages post:\n"
        . "  open with one line that makes the reader stop -- a sharp question or a bold\n"
        . "  statement about THEIR problem; then short paragraphs of one to three lines; a run\n"
        . "  of short punchy lines where it helps (\"See a problem. Understand it. Build a\n"
        . "  solution.\"); a plain call to action on its own line with the link, such as\n"
        . "  \"Registration Link: <url>\" or \"Learn more: <url>\", using the CTA link; then\n"
        . "  6-10 relevant hashtags on the last line, including the brand's own.\n"
        . "  (The rule against a rhetorical opening question does not apply to LinkedIn.)\n"
        . "instagram_copy: 60-150 words, warmer, line breaks, 5-10 hashtags.\n"
        . "Write BOTH, whichever platform is named — the reviewer may switch platform.\n\n"
        . "Return JSON:\n"
        . '{"linkedin_headline":"","linkedin_copy":"","instagram_hook":"","instagram_copy":"",'
        . '"hashtags":"","primary_message":"","secondary_message":"","key_benefit":"",'
        . '"pain_point":"","solution":"","proof_point":"","statistic":"","objection_handled":"",'
        . '"audience_pain_points":"","audience_awareness_stage":"","search_intent":"",'
        . '"secondary_keywords":"","creative_direction":"","visual_concept":"","headline":"",'
        . '"supporting_text":"","visual_elements":"","design_style":"","image_prompt":"",'
        . '"alt_text":"","poll_question":"","poll_options":[],"carousel_slides":[],"claims":[]}' . "\n\n"
        . "The LinkedIn post kind is " . ($plan['linkedin_type'] ?? 'Image') . ".\n"
        . "poll_question and poll_options: only for a Poll -- a question of at most 140\n"
        . "  characters and 2 to 4 options of at most 30 characters each. Otherwise \"\" and [].\n"
        . "carousel_slides: only for a Carousel -- 5 to 8 slides, each {\"title\":\"\",\"body\":\"\"}:\n"
        . "  title at most 7 words, body at most 30 words; slide 1 is the cover (a promise),\n"
        . "  the middle slides one point each, the last a call to action. Otherwise [].\n"
        . "For an Event or a Job, put the date, place and how to register or apply in the\n"
        . "  copy -- it goes out as a link post.\n\n"
        . "claims: list every factual assertion you made that a reader could check — numbers,\n"
        . "customers, awards, results, prices. If you made none, return []. This is checked\n"
        . "against the source, so listing them honestly is in your interest.\n"
        . "statistic: only a number that appears verbatim in the evidence. Otherwise \"\".\n"
        . "headline: 6-10 words, for the graphic. supporting_text: 0-12 words.\n\n"
        . "THE BRIEF\n" . json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\n"
        . "BRAND\n" . json_encode($brand, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n\n"
        . "CHECKABLE FACTS FROM THE SITE — the only numbers and claims you may use:\n"
        . ($evidence ? "- " . implode("\n- ", array_slice($evidence, 0, 40))
                     : "(none — so make no factual claims at all)") . "\n\n"
        . "THE PAGE THIS IS ABOUT — " . ($page['source_url'] ?? '') . "\n"
        . mb_substr((string) ($page['source_content'] ?? ''), 0, 6000);

    [$ok, $data] = fabo_ai_json($prompt, 3072);
    if (!$ok) {
        throw new RuntimeException((string) $data);
    }
    $claims = $data['claims'] ?? [];
    unset($data['claims']);
    /* Poll choices and carousel slides are lists, and would be flattened to
       nonsense below; they are stored in the forms the grid shows instead:
       choices separated by " | ", one slide per line as "Title — body". */
    $options = array_values(array_filter(array_map(
        static fn($o) => fabo_wc_short(trim(is_array($o) ? implode(' ', $o) : (string) $o), 30),
        (array) ($data['poll_options'] ?? [])), 'strlen'));
    $slides = [];
    foreach ((array) ($data['carousel_slides'] ?? []) as $sl) {
        $t = trim((string) (is_array($sl) ? ($sl['title'] ?? '') : $sl));
        $b = trim((string) (is_array($sl) ? ($sl['body'] ?? '') : ''));
        if ($t !== '') {
            $slides[] = str_replace(["\n", ' — '], [' ', ' - '], $t)
                      . ($b !== '' ? ' — ' . str_replace("\n", ' ', $b) : '');
        }
    }
    unset($data['poll_options'], $data['carousel_slides']);
    $data['poll_options']    = implode(' | ', array_slice($options, 0, 4));
    $data['carousel_slides'] = implode("\n", array_slice($slides, 0, 10));

    /* The kind's own parts, asked for again on their own when the writer
       left them out -- a long answer is where a model drops fields, and a
       carousel without slides or a poll without a question is not one. */
    $kind = (string) ($plan['linkedin_type'] ?? '');
    if (($kind === 'Carousel' && count($slides) < 3) || ($kind === 'Poll' && count($options) < 2)) {
        foreach (fabo_wc_write_parts($kind, $plan, (string) ($data['linkedin_copy'] ?? ''), $evidence)
                 as $f => $v) {
            $data[$f] = $v;
        }
    }
    if (trim((string) ($data['linkedin_headline'] ?? '')) === '') {
        $data['linkedin_headline'] = trim((string) ($data['headline'] ?? ''))
                                  ?: trim((string) ($plan['content_topic'] ?? ''));
    }
    $data = array_map(static fn($v) => is_array($v) ? implode(', ', $v) : trim((string) $v), $data);

    /* The check that makes this safe to publish. */
    [$verdict, $notes] = fabo_claims_check(
        $claims,
        (string) ($data['linkedin_copy'] ?? '') . "\n" . (string) ($data['instagram_copy'] ?? ''),
        $evidence);

    $data['hallucination_check']   = $verdict;
    $data['human_review_required'] = $verdict === 'clean' ? 0 : 1;
    $data['generation_notes']      = $notes;
    $data['source_verified']       = $evidence ? 1 : 0;
    $data['platform']              = $platform;
    return $data;
}

/**
 * Just the slides of a carousel, or just the question and choices of a poll.
 *
 * A short, focused request for the one part the full write left out. Returns
 * the fields in the forms stored on the row, or [] if it still came back
 * without them. Held to the same rules: the slides may only say what the
 * post and the site's facts say.
 */
function fabo_wc_write_parts(string $kind, array $plan, string $copy, array $evidence): array
{
    $ask = $kind === 'Carousel'
        ? "Turn this LinkedIn post into a carousel of 5 to 7 slides.\n"
          . "Return JSON: {\"slides\":[{\"title\":\"\",\"body\":\"\"}]}\n"
          . "Slide 1 is the cover: a promise in at most 7 words. Then one point per slide: a\n"
          . "title of at most 7 words and a body of at most 30 words. The last slide is the call\n"
          . "to action (" . ($plan['cta'] ?? 'Learn More') . ").\n"
        : "Write a LinkedIn poll that goes with this post.\n"
          . "Return JSON: {\"question\":\"\",\"options\":[\"\",\"\"]}\n"
          . "A question the audience would genuinely want to answer, at most 140 characters, and\n"
          . "2 to 4 options of at most 30 characters each.\n";
    [$ok, $data] = fabo_ai_json($ask . "\n" . fabo_ai_rules()
        . "\nTHE POST\n" . mb_substr($copy, 0, 3000)
        . "\n\nTHE TOPIC\n" . ($plan['content_topic'] ?? '') . ' — ' . ($plan['primary_message'] ?? '')
        . "\n\nFACTS YOU MAY USE\n" . ($evidence ? '- ' . implode("\n- ", array_slice($evidence, 0, 25)) : '(none)'),
        2048);
    if (!$ok || !is_array($data)) {
        return [];
    }
    if ($kind === 'Carousel') {
        $lines = [];
        foreach ((array) ($data['slides'] ?? []) as $sl) {
            $t = trim((string) (is_array($sl) ? ($sl['title'] ?? '') : $sl));
            $b = trim((string) (is_array($sl) ? ($sl['body'] ?? '') : ''));
            if ($t !== '') {
                $lines[] = str_replace(["\n", ' — '], [' ', ' - '], $t)
                         . ($b !== '' ? ' — ' . str_replace("\n", ' ', $b) : '');
            }
        }
        return count($lines) >= 3 ? ['carousel_slides' => implode("\n", array_slice($lines, 0, 10))] : [];
    }
    $opts = array_values(array_filter(array_map(
        static fn($o) => fabo_wc_short(trim((string) $o), 30), (array) ($data['options'] ?? [])), 'strlen'));
    $q = mb_substr(trim((string) ($data['question'] ?? '')), 0, 140);
    return ($q !== '' && count($opts) >= 2)
        ? ['poll_question' => $q, 'poll_options' => implode(' | ', array_slice($opts, 0, 4))] : [];
}

/**
 * Does the copy claim anything the website does not say?
 *
 * Not a model grading itself — a string check. Every number in the generated
 * copy must appear somewhere in the evidence taken from the site. Numbers are
 * where invention does real damage ("trusted by 5,000 companies" under a real
 * brand), and they are the one thing that can be checked mechanically.
 *
 * Returns [verdict, notes]: "clean", "review" or "unsupported".
 */
function fabo_claims_check(array $claims, string $copy, array $evidence): array
{
    $hay = ' ' . mb_strtolower(implode(' | ', $evidence)) . ' ';
    $notes = [];

    /* Numbers in the copy that are nowhere in the evidence. Years and small
       counts are ignored: "3 ways to..." is a rhetorical device, not a claim
       about the business. */
    preg_match_all('~\b\d[\d,\.]*\s*%|\b[\d,]{2,}\b~u', $copy, $m);
    $unsupported = [];
    foreach (array_unique($m[0]) as $num) {
        $bare = strtolower(trim($num));
        $digits = preg_replace('~[^\d]~', '', $bare) ?? '';
        if ($digits === '' || (strlen($digits) <= 2 && !str_contains($bare, '%'))) {
            continue;
        }
        if (preg_match('~^(19|20)\d{2}$~', $digits) && str_contains($hay, $digits)) {
            continue;
        }
        $loose = ltrim(str_replace([',', '.'], '', $digits), '0');
        if (str_contains($hay, $bare) || str_contains(str_replace([',', '.'], '', $hay), $loose)) {
            continue;
        }
        $unsupported[] = $num;
    }
    if ($unsupported) {
        $notes[] = 'Numbers not found on the site: ' . implode(', ', $unsupported) . '.';
    }

    /* Authority without a number: "rated number one by Gartner", "ISO
       certified", "award-winning". No digit to check, and exactly the kind
       of claim that is defamatory-adjacent if it is not true, so the word
       itself has to appear on the site somewhere. */
    $authority = ['award', 'awarded', 'winner', 'certified', 'certification', 'accredited',
                  'iso ', 'patented', 'ranked', 'rated', 'endorsed', 'partner of',
                  'official partner', 'gartner', 'forrester', 'market leader',
                  'number one', 'no.1', '#1', 'best-in-class'];
    $lowCopy = ' ' . mb_strtolower($copy) . ' ';
    $claimed = [];
    foreach ($authority as $word) {
        if (str_contains($lowCopy, $word) && !str_contains($hay, trim($word))) {
            $claimed[] = trim($word);
        }
    }
    if ($claimed) {
        $notes[] = 'Says "' . implode('", "', array_slice($claimed, 0, 4))
                 . '" but the site does not.';
    }

    /* A claim the model itself flagged, with no evidence at all behind it,
       is the case where it knew it was reaching. */
    if ($claims && !$evidence) {
        $notes[] = 'The copy makes ' . count($claims) . ' factual claim'
                 . (count($claims) === 1 ? '' : 's')
                 . ' but nothing checkable was found on the site.';
    }
    foreach ($claims as $c) {
        $c = is_array($c) ? implode(' ', $c) : (string) $c;
        if (trim($c) === '') {
            continue;
        }
        /* A rough overlap test: most of the claim's meaningful words should
           appear in the evidence somewhere. */
        $words = array_filter(preg_split('~\W+~u', mb_strtolower($c)) ?: [],
                              static fn($w) => mb_strlen($w) > 4);
        if (!$words) {
            continue;
        }
        $hits = 0;
        foreach ($words as $w) {
            if (str_contains($hay, $w)) {
                $hits++;
            }
        }
        if ($hits / max(1, count($words)) < 0.4) {
            $notes[] = 'Claim not clearly supported: "' . mb_substr($c, 0, 90) . '".';
        }
    }

    if (!$notes) {
        return ['clean', 'Every checkable claim matched the website.'];
    }
    $verdict = ($unsupported || $claimed) ? 'unsupported' : 'review';
    return [$verdict, implode(' ', array_slice($notes, 0, 6))];
}

/* ------------------------------------------------------- the job handlers */

/** The queue's entry points into this file. */
function fabo_jobs_webcontent(): array
{
    return [
        'wc.crawl'    => fabo_wc_guarded('fabo_wc_job_crawl'),
        'wc.extract'  => fabo_wc_guarded('fabo_wc_job_extract'),
        'wc.generate' => fabo_wc_guarded('fabo_wc_job_generate'),
        'wc.write'    => fabo_wc_guarded('fabo_wc_job_write'),
        'wc.creative' => 'fabo_wc_job_creative',
    ];
}

/** How many tries a website step gets before the site is marked stopped. */
const FABO_WC_TRIES = 3;

/**
 * A website step that says so when it goes wrong.
 *
 * Without this a failed step was retried quietly in the queue while the
 * website itself still read "Reading the website — starting", for ever. Now
 * the reason is written onto the website as it happens, and after the last
 * try the website is marked FAILED with that reason, so the panel shows
 * "Stopped" and why, and "Read the site again" is offered.
 */
function fabo_wc_guarded(string $fn): Closure
{
    return static function (array $p, array $job = []) use ($fn) {
        $siteId = (int) ($p['site_id'] ?? 0);
        try {
            $note = $fn($p);
            if ($siteId > 0) {
                /* Worked this time: the earlier complaint is no longer true. */
                fabo_exec('UPDATE website_sources SET last_error = NULL
                            WHERE id = ? AND last_error LIKE "Trying again:%"', 'i', [$siteId]);
            }
            return $note;
        } catch (Throwable $e) {
            if ($siteId <= 0) {
                throw $e;
            }
            $why  = mb_substr($e->getMessage(), 0, 450);
            $last = $e instanceof InvalidArgumentException
                 || (int) ($job['attempts'] ?? 1) >= FABO_WC_TRIES;
            try {
                fabo_exec('UPDATE website_sources SET last_error = ?'
                        . ($last ? ', state = "FAILED"' : '') . ' WHERE id = ?', 'si',
                          [($last ? '' : 'Trying again: ') . $why, $siteId]);
            } catch (Throwable $ignored) {
                // The website may have been removed while this ran.
            }
            if ($last) {
                return 'Gave up: ' . $why;   // settled, so the queue stops retrying
            }
            throw $e;
        }
    };
}

/**
 * One slice of a crawl, then either another slice or the next stage.
 *
 * The job re-queues itself rather than looping, so each run is a few seconds
 * and nothing is ever holding a claim when the host cuts a request off.
 */
function fabo_wc_job_crawl(array $p): string
{
    $siteId = (int) ($p['site_id'] ?? 0);
    $owner  = (int) ($p['client_id'] ?? 0);

    /* One reading of a site at a time. Two at once -- a re-read pressed
       while a healed one was queued -- both store the same pages and both
       go on to write a set of posts. The second simply stands down. */
    $lock = 'fabo_wc_crawl_' . $siteId;
    if ((int) (fabo_one('SELECT GET_LOCK(?, 0) l', 's', [$lock])['l'] ?? 0) !== 1) {
        return 'Already being read.';
    }
    try {
        return fabo_wc_crawl_step($siteId, $owner, $p);
    } finally {
        fabo_one('SELECT RELEASE_LOCK(?) l', 's', [$lock]);
    }
}

function fabo_wc_crawl_step(int $siteId, int $owner, array $p): string
{
    fabo_exec('UPDATE website_sources SET state = "DISCOVERED", last_error = NULL WHERE id = ?',
              'i', [$siteId]);

    [$done, $did, $note] = fabo_crawl_slice($siteId, 12);

    if (!$done) {
        /* A fresh dedupe key each time, or the second slice would be
           recognised as the first and thrown away. */
        fabo_job_add('wc.crawl', $p, $owner, 'crawl:' . $siteId . ':' . microtime(true));
        return $note . ' More to read.';
    }
    $have = (int) (fabo_one('SELECT COUNT(*) c FROM crawled_pages WHERE site_id = ?',
                            'i', [$siteId])['c'] ?? 0);
    if ($have === 0) {
        throw new RuntimeException($note);
    }
    /* Reading is finished, so say what is happening now. Left as DISCOVERED,
       the panel read "Reading the website" all through the brand step. */
    fabo_exec('UPDATE website_sources SET state = "EXTRACTED" WHERE id = ?', 'i', [$siteId]);
    fabo_job_add('wc.extract', ['site_id' => $siteId, 'client_id' => $owner],
                 $owner, 'extract:' . $siteId);
    return $note;
}

/**
 * How far along a website is, as something a person can watch.
 *
 * Returns [percent, stage, note]. The percentage is deliberately weighted by
 * stage rather than by pages: reading the site is most of the wall-clock but
 * only the first third of the work, and a bar that sits at 95% through the
 * slowest part is worse than no bar at all.
 */
function fabo_wc_progress(array $site): array
{
    $state   = (string) ($site['state'] ?? '');
    $crawled = (int) ($site['pages_crawled'] ?? 0);
    $found   = max(1, (int) ($site['pages_found'] ?? 1));

    switch ($state) {
        case 'DISCOVERED':
            /* 5% for having started, then up to 35% for the reading. */
            $pc = 5 + (int) round(30 * min(1, $crawled / $found));
            return [$pc, 'Reading the website',
                    $crawled > 0 ? $crawled . ' of about ' . $found . ' pages' : 'starting'];
        case 'EXTRACTED':
            return [45, 'Working out the brand', ''];
        case 'READY_FOR_GENERATION':
            return [55, 'Planning the posts', ''];
        case 'GENERATING':
            $planned = max(1, (int) ($site['posts_planned'] ?? 0));
            $written = (int) ($site['posts_written'] ?? 0);
            /* 60% to 95%: the writing is the last long stretch, and a bar
               that reaches 100 before the posts exist is a lie. */
            return [60 + (int) round(35 * min(1, $written / $planned)),
                    'Writing the posts',
                    $written . ' of ' . $planned . ' written'];
        case 'GENERATED':
            return [100, 'Done', $crawled . ' pages read'];
        case 'FAILED':
            return [100, 'Stopped', (string) ($site['last_error'] ?? '')];
    }
    return [0, $state, ''];
}

function fabo_wc_job_extract(array $p): string
{
    $siteId = (int) ($p['site_id'] ?? 0);
    $brand  = fabo_wc_extract_brand($siteId);
    fabo_exec('UPDATE website_sources SET state = "READY_FOR_GENERATION" WHERE id = ?',
              'i', [$siteId]);
    fabo_job_add('wc.generate', ['site_id' => $siteId],
                 (int) ($p['client_id'] ?? 0), 'generate:' . $siteId);
    return 'Brand read: ' . ($brand['brand_name'] ?: 'unnamed') . '.';
}

/**
 * Plan the posts, then queue one job per post.
 *
 * The writing is not done here. Eight posts is eight calls to a model, which
 * is a minute or more of work, and a job that takes a minute inside a page
 * request is a job the host kills half way through — the same mistake the
 * crawl made. So the plan is stored and each post becomes its own short job.
 */
function fabo_wc_job_generate(array $p): string
{
    $siteId = (int) ($p['site_id'] ?? 0);
    $site   = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website record is gone.');
    }
    $owner = (int) $site['client_id'];

    /* Planned already, and the posts on their way: a second plan would be a
       second set of posts beside the first. */
    if ((string) $site['state'] === 'GENERATING' && (int) $site['posts_planned'] > 0
        && trim((string) ($site['plan'] ?? '')) !== '') {
        return 'Already planned.';
    }
    fabo_exec('UPDATE website_sources SET state = "GENERATING" WHERE id = ?', 'i', [$siteId]);

    $plan = fabo_wc_plan($siteId);

    /* Dates walk forward from the one the person chose, as often as they
       chose, at the time they chose. Worked out here, while the whole plan is
       in view, rather than in each job -- jobs may run out of order, and the
       dates must not depend on that. Days already taken by posts kept from an
       earlier run are stepped over, so no day gets two. */
    $at    = preg_match('~^\d{2}:\d{2}$~', (string) ($site['post_time'] ?? ''))
           ? (string) $site['post_time'] : '10:00';
    $every = max(1, (int) ($site['post_every'] ?? 1));
    $taken = array_flip(array_column(fabo_all('SELECT scheduled_date FROM `' . FABO_WC_TABLE . '`
                                                WHERE site_id = ? AND scheduled_date IS NOT NULL',
                                              'i', [$siteId]), 'scheduled_date'));
    $day = strtotime(max((string) ($site['post_date'] ?? ''), date('Y-m-d'))) ?: time();
    foreach ($plan as $i => $post) {
        while (isset($taken[date('Y-m-d', $day)])) {
            $day = strtotime('+' . $every . ' day', $day);
        }
        $plan[$i]['when'] = date('Y-m-d', $day);
        $plan[$i]['at']   = $at;
        /* Today's time already gone: the first post goes out shortly after it
           is written rather than waiting a whole cycle -- "from the day it was
           added". Rounded to the next quarter hour, a little ahead, so there
           is a moment to look at it. */
        if (strtotime($plan[$i]['when'] . ' ' . $at) < time() + 600) {
            $plan[$i]['at'] = date('H:i', (int) (ceil((time() + 900) / 900) * 900));
            if ($plan[$i]['at'] < date('H:i')) {          // ran past midnight
                $plan[$i]['when'] = date('Y-m-d', strtotime('+1 day'));
            }
        }
        $taken[$plan[$i]['when']] = true;
        $day = strtotime('+' . $every . ' day', $day);
    }

    fabo_exec('UPDATE website_sources SET plan = ?, posts_planned = ?, posts_written = 0
                WHERE id = ?', 'sii',
              [json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
               count($plan), $siteId]);

    foreach (array_keys($plan) as $i) {
        fabo_job_add('wc.write', ['site_id' => $siteId, 'index' => $i, 'client_id' => $owner],
                     $owner, 'write:' . $siteId . ':' . $i);
    }
    return count($plan) . ' post' . (count($plan) === 1 ? '' : 's') . ' planned.';
}

/** One post from the stored plan, written and put on the sheet. */
function fabo_wc_job_write(array $p): string
{
    $siteId = (int) ($p['site_id'] ?? 0);
    $i      = (int) ($p['index'] ?? 0);
    $site   = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website record is gone.');
    }
    $plan = json_decode((string) ($site['plan'] ?? ''), true);
    if (!is_array($plan) || !isset($plan[$i])) {
        return 'Nothing to write.';
    }
    $owner = (int) $site['client_id'];
    $when  = strtotime((string) ($plan[$i]['when'] ?? '')) ?: time();

    /* The graphic is made inside fabo_wc_create_row() now, before the post
       can be scheduled -- a post published before its picture existed would
       go out without one. */
    $rowId = fabo_wc_create_row($siteId, $owner, fabo_wc_brand($siteId), $plan[$i], $when);

    /* Counted in the database rather than in a variable, because the jobs
       that make up one site's posts do not share a process. */
    fabo_exec('UPDATE website_sources SET posts_written = posts_written + 1 WHERE id = ?',
              'i', [$siteId]);

    $left = fabo_one('SELECT posts_planned, posts_written FROM website_sources WHERE id = ?',
                     'i', [$siteId]);
    if ((int) $left['posts_written'] >= (int) $left['posts_planned']) {
        fabo_exec('UPDATE website_sources SET state = "GENERATED", plan = NULL WHERE id = ?',
                  'i', [$siteId]);
    }
    return $rowId > 0 ? 'Post written.' : 'That one could not be written.';
}

/** One planned post, written and stored as a row. */
function fabo_wc_create_row(int $siteId, int $owner, array $brand, array $plan, int $when): int
{
    /* Twice before giving up on a post: a model having a bad moment should
       not cost the calendar one of its posts. */
    $copy = null;
    for ($try = 1; $try <= 2 && $copy === null; $try++) {
        try {
            $copy = fabo_wc_write($siteId, $plan);
        } catch (Throwable $e) {
            error_log('FABO wc write (try ' . $try . '): ' . $e->getMessage());
        }
    }
    if ($copy === null) {
        return 0;
    }
    /* Never a second post with the same headline for one site, whatever
       planned it twice. Checked after writing, because the headline is the
       writer's. Returns 0 so the run still counts it and finishes. */
    $same = fabo_wc_same((string) ($copy['linkedin_headline'] ?? ''));
    if ($same !== '') {
        foreach (fabo_all('SELECT linkedin_headline FROM `' . FABO_WC_TABLE . '` WHERE site_id = ?',
                          'i', [$siteId]) as $r) {
            if (fabo_wc_same((string) $r['linkedin_headline']) === $same) {
                return 0;
            }
        }
    }
    $page = fabo_one('SELECT source_page_type, source_title, source_evidence FROM crawled_pages
                      WHERE site_id = ? AND source_url = ?',
                     'is', [$siteId, (string) ($plan['source_url'] ?? '')]);

    $take = static fn(array $a, string $k) => mb_substr((string) ($a[$k] ?? ''), 0, 4000);
    /* Single-line columns are VARCHAR(255); a longer value fails the insert
       outright on a server in strict mode. */
    $line = static fn(string $v) => mb_strimwidth(trim($v), 0, 255, '');
    $state = ((int) ($copy['human_review_required'] ?? 1) === 1) ? 'REVIEW_REQUIRED' : 'GENERATED';

    /* Every column filled without anybody typing. Where the writer left a
       field empty, the same thing is taken from the plan or from the brand
       read off the site -- all of it the site's own words, nothing new. */
    $or = static function (string ...$vals): string {
        foreach ($vals as $v) {
            if (trim($v) !== '') {
                return $v;
            }
        }
        return '';
    };
    $copy['key_benefit']     = $or($take($copy, 'key_benefit'), $take($plan, 'key_benefit'),
                                   $take($brand, 'value_proposition'), $take($brand, 'usp'));
    $copy['pain_point']      = $or($take($copy, 'pain_point'), $take($plan, 'pain_point'),
                                   $take($brand, 'audience_pain_points'),
                                   $take($brand, 'business_problem'));
    $copy['solution']        = $or($take($copy, 'solution'), $take($brand, 'business_solution'),
                                   $take($brand, 'value_proposition'));
    $copy['proof_point']     = $or($take($copy, 'proof_point'), $take($brand, 'social_proof'),
                                   $take($brand, 'differentiators'));
    $copy['hashtags']        = $or($take($copy, 'hashtags'), $take($brand, 'brand_hashtags'));
    $copy['headline']        = $or($take($copy, 'headline'), $take($copy, 'linkedin_headline'),
                                   $take($plan, 'content_topic'));
    $copy['supporting_text'] = $or($take($copy, 'supporting_text'), $take($brand, 'brand_tagline'));
    $copy['design_style']    = $or($take($copy, 'design_style'), $take($brand, 'brand_personality'));
    $copy['alt_text']        = $or($take($copy, 'alt_text'), $take($copy, 'headline'));
    /* A statistic only ever comes from the site's own sentences. */
    if (trim($take($copy, 'statistic')) === '') {
        $evidence = json_decode((string) ($page['source_evidence'] ?? ''), true) ?: [];
        foreach ($evidence as $fact) {
            if (preg_match('~\d~', (string) $fact)) {
                $copy['statistic'] = mb_substr((string) $fact, 0, 300);
                break;
            }
        }
    }
    $platform = $or($take($copy, 'platform'), $take($plan, 'platform'), 'LinkedIn');
    /* The time the person chose for this website; failing that, the hours
       each platform is read most, so the time column is never left blank. */
    $time = preg_match('~^\d{2}:\d{2}$~', (string) ($plan['at'] ?? '')) ? (string) $plan['at']
          : (stripos($platform, 'instagram') !== false ? '18:00' : '09:30');
    $remarks = trim(implode("\n", array_filter([
        $take($copy, 'secondary_message') !== '' ? 'Secondary message: ' . $take($copy, 'secondary_message') : '',
        $take($copy, 'objection_handled') !== '' ? 'Objection handled: ' . $take($copy, 'objection_handled') : '',
    ])));

    $rowId = (int) fabo_exec(
        'INSERT INTO `' . FABO_WC_TABLE . '`
         (client_id, site_id, source_url, source_page_type, source_title,
          brand_name, brand_voice, target_audience, audience_segment,
          focus, content_pillar, content_topic, content_angle, content_objective,
          funnel_stage, content_type, platform,
          linkedin_headline, linkedin_copy, instagram_hook, instagram_copy, hashtags,
          primary_message, key_benefit, pain_point, solution, proof_point, statistic,
          cta, cta_url, headline, supporting_text, visual_concept, design_style,
          image_prompt, alt_text,
          hallucination_check, human_review_required, source_verified, generation_notes,
          scheduled_date, scheduled_time, status, remarks)
         VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?,?, ?,?,?, ?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?, ?,?, ?,?,?,?, ?,?,?,?)',
        'iisss' . 'ssss' . 'sssss' . 'sss' . 'sssss' . 'ssssss' . 'ssssss' . 'ss' . 'siis' . 'ssss',
        [
            $owner, $siteId, (string) ($plan['source_url'] ?? ''),
            $line((string) ($page['source_page_type'] ?? '')),
            $line((string) ($page['source_title'] ?? '')),
            $line($take($brand, 'brand_name')),
            $line($or($take($brand, 'brand_voice'), $take($brand, 'brand_tone'),
                      $take($brand, 'brand_personality'))),
            $line($or($take($brand, 'target_audience'), $take($brand, 'audience_segment'))),
            $line($take($plan, 'audience_segment') ?: $take($brand, 'audience_segment')),
            $line($take($plan, 'focus')), $line($take($plan, 'content_pillar')),
            $line($or($take($plan, 'content_topic'), $take($copy, 'headline'),
                      $take($copy, 'linkedin_headline'), (string) ($page['source_title'] ?? ''))),
            $line($take($plan, 'content_angle')), $line($take($plan, 'content_objective')),
            $line($take($plan, 'funnel_stage')), $line($take($plan, 'content_type')),
            $line($platform),
            $line($take($copy, 'linkedin_headline')), $take($copy, 'linkedin_copy'),
            $line($take($copy, 'instagram_hook')), $take($copy, 'instagram_copy'),
            $line($take($copy, 'hashtags')),
            $take($copy, 'primary_message') ?: $take($plan, 'primary_message'),
            $line($take($copy, 'key_benefit')), $line($take($copy, 'pain_point')),
            $line($take($copy, 'solution')),
            $line($take($copy, 'proof_point')), $line($take($copy, 'statistic')),
            $line($take($plan, 'cta')), mb_substr($take($plan, 'cta_url'), 0, 512),
            $line($take($copy, 'headline')), $line($take($copy, 'supporting_text')),
            $take($copy, 'visual_concept'), $line($take($copy, 'design_style')),
            $take($copy, 'image_prompt'), $line($take($copy, 'alt_text')),
            $line((string) ($copy['hallucination_check'] ?? 'review')),
            (int) ($copy['human_review_required'] ?? 1), (int) ($copy['source_verified'] ?? 0),
            $take($copy, 'generation_notes'),
            date('Y-m-d', $when), $time, $state, $remarks,
        ]);
    fabo_wc_give_id($rowId);
    if ($rowId <= 0) {
        return $rowId;
    }

    /* The LinkedIn kind of post and what it needs. A kind the model made up
       falls back to one the content type implies. */
    $kinds = ['Text', 'Image', 'Multi-image', 'Carousel', 'Document', 'Video', 'Poll',
              'Article', 'Newsletter', 'Event', 'Job'];
    $kind  = '';
    foreach ($kinds as $k) {
        if (strcasecmp(trim((string) ($plan['linkedin_type'] ?? '')), $k) === 0) {
            $kind = $k;
        }
    }
    if ($kind === '' || $kind === 'Video') {
        $ct   = strtolower((string) ($plan['content_type'] ?? ''));
        $kind = str_contains($ct, 'carousel') ? 'Carousel' : (str_contains($ct, 'text') ? 'Text' : 'Image');
    }
    if ($kind === 'Poll' && (trim((string) ($copy['poll_question'] ?? '')) === ''
                             || substr_count((string) ($copy['poll_options'] ?? ''), '|') < 1)) {
        $kind = 'Image';                    // a poll with nothing to ask is not a poll
    }
    if ($kind === 'Carousel' && substr_count(trim((string) ($copy['carousel_slides'] ?? '')), "\n") < 2) {
        $kind = 'Image';                    // nor a carousel of two slides
    }
    fabo_exec('UPDATE `' . FABO_WC_TABLE . '`
                  SET linkedin_type = ?, poll_question = ?, poll_options = ?, poll_duration = ?,
                      carousel_slides = ?
                WHERE id = ?', 'sssssi',
              [$kind,
               mb_strimwidth(trim((string) ($copy['poll_question'] ?? '')), 0, 255, ''),
               mb_strimwidth(trim((string) ($copy['poll_options'] ?? '')), 0, 255, ''),
               $kind === 'Poll' ? '1 week' : '',
               (string) ($copy['carousel_slides'] ?? ''), $rowId]);

    /* The graphic first, then the schedule: a post is never queued to go out
       before its picture exists. */
    try {
        require_once __DIR__ . '/creative.php';
        $row  = fabo_one('SELECT * FROM `' . FABO_WC_TABLE . '` WHERE id = ?', 'i', [$rowId]);
        $file = $row ? fabo_creative_make($row) : '';
        if ($file !== '') {
            fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET media_asset = ? WHERE id = ?',
                      'si', [$file, $rowId]);
        }
        /* A carousel is its slides, as a PDF LinkedIn shows as a swipeable
           document -- made now, with the graphic, so it exists before the
           post can be scheduled. */
        if ($row && $kind === 'Carousel') {
            $doc = fabo_creative_carousel($row);
            if ($doc !== '') {
                fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET document_asset = ? WHERE id = ?',
                          'si', [$doc, $rowId]);
            }
        }
    } catch (Throwable $e) {
        error_log('FABO wc creative: ' . $e->getMessage());
    }

    /* Posting by itself, when the person asked for that: a post whose every
       checkable claim matched the website goes straight onto the schedule.
       One that did not stays with REVIEW_REQUIRED -- automatic never means
       publishing a claim the site does not make. */
    $auto = (int) (fabo_one('SELECT auto_post FROM website_sources WHERE id = ?', 'i',
                            [$siteId])['auto_post'] ?? 0);
    if ($auto === 1 && $state === 'GENERATED') {
        /* Recorded as an approval like any other, so the grid, the history
           and the publisher all agree on why it is going out. */
        require_once __DIR__ . '/approvals.php';
        fabo_set_approval(FABO_WC_SHEET, $rowId, 'approved',
                          'Every checkable claim matched the website; approved automatically.',
                          'Auto (claims verified)');
    }
    return $rowId;
}

/**
 * The row's Content ID (WC-0001), the same numbering "+ Add row" uses.
 *
 * Under a lock, because posts are now written side by side and "the highest
 * number so far, plus one" read by two writers at once is the same number.
 */
function fabo_wc_give_id(int $rowId): void
{
    if ($rowId <= 0) {
        return;
    }
    $sheet = fabo_sheet(FABO_WC_SHEET);
    if (!$sheet || empty($sheet['id_field']) || empty($sheet['prefix'])) {
        return;
    }
    $f   = $sheet['id_field'];
    $pre = (string) $sheet['prefix'];
    try {
        fabo_one('SELECT GET_LOCK("fabo_wc_id", 10) l');
        /* As fabo_next_id() in lib/endpoint.php works it out. */
        $n = (int) (fabo_one('SELECT MAX(CAST(SUBSTRING(`' . $f . '`, ?) AS UNSIGNED)) n
                                FROM `' . FABO_WC_TABLE . '` WHERE `' . $f . '` LIKE ?',
                             'is', [strlen($pre) + 1, $pre . '%'])['n'] ?? 0) + 1;
        fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET `' . $f . '` = ? WHERE id = ?', 'si',
                  [$pre . str_pad((string) $n, (int) ($sheet['pad'] ?? 4), '0', STR_PAD_LEFT),
                   $rowId]);
    } catch (Throwable $e) {
        error_log('FABO wc id: ' . $e->getMessage());
    } finally {
        try { fabo_one('SELECT RELEASE_LOCK("fabo_wc_id") l'); } catch (Throwable $e) {}
    }
}

function fabo_wc_job_creative(array $p): string
{
    $rowId = (int) ($p['row_id'] ?? 0);
    $row   = fabo_one('SELECT * FROM `' . FABO_WC_TABLE . '` WHERE id = ?', 'i', [$rowId]);
    if (!$row) {
        return 'That row is gone.';
    }
    require_once __DIR__ . '/creative.php';
    $file = fabo_creative_make($row);
    if ($file === '') {
        return 'No graphic was made.';
    }
    fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET media_asset = ? WHERE id = ?',
              'si', [$file, $rowId]);
    return 'Graphic made.';
}

/* ------------------------------------------------ words we could not read */

/** The page type older pasted pieces were filed under. Still read first, and
    kept through a rewrite, so nothing typed in before is lost. */
const FABO_WC_PROVIDED = 'Provided';

/**
 * Read the brand again and write the posts again, with everything now known.
 *
 * The posts already on the grid are taken off first. Writing a second set
 * beside the first would leave somebody comparing two calendars and guessing
 * which is current -- and the reason to press this is that the first set was
 * written from too little to be worth keeping.
 *
 * Anything edited by hand is kept. Somebody who rewrote a post meant it.
 */
function fabo_wc_rewrite(int $siteId): string
{
    $site = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website is not here any more.');
    }
    $owner = (int) $site['client_id'];

    /* Pressed while the site is still being read or written, a second run
       would start beside the first and both would put their posts on the
       grid. Once is enough: it finishes, then it can be asked again. */
    if (!in_array((string) $site['state'], ['GENERATED', 'FAILED'], true)) {
        throw new InvalidArgumentException(
            'This website is still being read and written. Wait for it to finish, '
          . 'then read it again if you need to.');
    }

    $gone = 0;
    try {
        /* Every post from this site goes, except the ones a person has made
           their own: edited in a cell (each edit is in activity_audit), or
           already approved, scheduled or out.
         *
         * This used to keep anything whose updated_at had moved more than two
         * minutes after it was written. The portal's own later steps -- the
         * Content ID, the graphic -- move it too, so most of the old posts
         * counted as "edited", stayed, and the new set landed beside them:
         * the duplicates. */
        $gone = (int) fabo_exec(
            'DELETE FROM `' . FABO_WC_TABLE . '`
              WHERE site_id = ?
                AND COALESCE(status, "") NOT IN ("APPROVED","SCHEDULED","PUBLISHING","PUBLISHED")
                AND id NOT IN (SELECT row_id FROM activity_audit
                                WHERE table_name = ? AND row_id IS NOT NULL)',
            'is', [$siteId, FABO_WC_TABLE]);
    } catch (Throwable $e) {
        error_log('FABO wc rewrite clear: ' . $e->getMessage());
    }

    /* The site is read again from scratch, not just re-written from what was
       stored last time.
     *
     * The stored pages are whatever the reader could manage on the day, and
     * the reader improves: a site that came back empty once may read properly
     * now. Going straight to the brand step would rewrite the same posts from
     * the same thin pages and look like nothing had happened.
     *
     * Pasted pages are kept. They are not something a crawl can recover, and
     * they are usually the reason somebody is pressing this. */
    try {
        fabo_exec('DELETE FROM crawled_pages WHERE site_id = ? AND source_page_type <> ?',
                  'is', [$siteId, FABO_WC_PROVIDED]);
    } catch (Throwable $e) {
        error_log('FABO wc rewrite pages: ' . $e->getMessage());
    }

    fabo_exec('UPDATE website_sources
                  SET state = "DISCOVERED", posts_planned = 0, posts_written = 0,
                      plan = NULL, last_error = NULL, frontier = NULL,
                      pages_found = 0, pages_crawled = 0
                WHERE id = ?', 'i', [$siteId]);

    /* One key per site, so a double press queues one reading, not two. */
    fabo_job_add('wc.crawl', ['site_id' => $siteId, 'client_id' => $owner],
                 $owner, 'crawl:' . $siteId . ':again');

    return ($gone > 0
            ? $gone . ' post' . ($gone === 1 ? '' : 's') . ' cleared. '
            : '')
        . 'Reading the site again and writing from what it finds — the posts appear '
        . 'here in a few minutes.';
}

/**
 * Take a website off the panel altogether, with everything that came of it:
 * its queued work, the pages read from it, and the posts written from it.
 *
 * The queued jobs go first, so nothing half way through can write a post for
 * a site that no longer exists. A job already running finds the record gone
 * and stops on its own.
 */
function fabo_wc_remove_site(int $siteId): string
{
    $site = fabo_one('SELECT id, site_host FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website is not here any more.');
    }
    /* Payloads are written by json_encode, so the id sits as "site_id":N
       followed by a comma or the closing brace. */
    fabo_exec('DELETE FROM job_queue
                WHERE kind LIKE "wc.%" AND state IN ("waiting", "running")
                  AND (payload LIKE ? OR payload LIKE ?)', 'ss',
              ['%"site_id":' . $siteId . ',%', '%"site_id":' . $siteId . '}%']);
    $posts = (int) fabo_exec('DELETE FROM `' . FABO_WC_TABLE . '` WHERE site_id = ?',
                             'i', [$siteId]);
    fabo_exec('DELETE FROM crawled_pages WHERE site_id = ?', 'i', [$siteId]);
    fabo_exec('DELETE FROM website_sources WHERE id = ?', 'i', [$siteId]);

    return (string) $site['site_host'] . ' removed'
         . ($posts > 0 ? ', with its ' . $posts . ' post' . ($posts === 1 ? '' : 's') : '')
         . '.';
}

/**
 * What an approval decision does to a website post's place on the schedule.
 *
 *   approved  -- on the schedule (SCHEDULED) for its date and time, or
 *                APPROVED and waiting for a date if it has none;
 *   rejected  -- off it (REVIEW_REQUIRED), with the reason on the row;
 *   pending   -- off it again, waiting to be looked at.
 *
 * A post already going out, or out, is left alone: a decision cannot pull
 * back something LinkedIn already has.
 */
function fabo_wc_after_decision(int $rowId, string $state, string $remarks, string $who): void
{
    $row = fabo_one('SELECT status, scheduled_date FROM `' . FABO_WC_TABLE . '` WHERE id = ?', 'i', [$rowId]);
    if (!$row || in_array((string) $row['status'], ['PUBLISHING', 'PUBLISHED'], true)) {
        return;
    }
    $dated = trim((string) ($row['scheduled_date'] ?? '')) !== ''
          && (string) $row['scheduled_date'] !== '0000-00-00';
    if ($state === 'approved') {
        $status = $dated ? 'SCHEDULED' : 'APPROVED';
        $note   = 'Approved by ' . $who . ($dated ? '; scheduled.' : '; give it a date to schedule it.');
    } elseif ($state === 'rejected') {
        $status = 'REVIEW_REQUIRED';
        $note   = 'Rejected by ' . $who . ': ' . $remarks;
    } else {
        $status = 'GENERATED';
        $note   = 'Waiting for approval.';
    }
    fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET status = ?, publishing_status = ? WHERE id = ?',
              'ssi', [$status, mb_substr($note, 0, 250), $rowId]);
}

/**
 * The posts of one website that can be approved in one go.
 *
 * Waiting for a decision (never rejected -- a rejection was somebody's
 * considered "no", and a bulk button does not overrule it), passed the claim
 * check, dated, and with everything their kind needs (a video post its
 * video, a poll its question). Returns their ids.
 */
function fabo_wc_ready_to_approve(int $siteId): array
{
    require_once __DIR__ . '/social.php';
    $ids = [];
    foreach (fabo_all('SELECT w.* FROM `' . FABO_WC_TABLE . '` w
                         LEFT JOIN approvals a ON a.sheet_key = "webcontent" AND a.row_id = w.id
                        WHERE w.site_id = ? AND w.hallucination_check = "clean"
                          AND w.status IN ("GENERATED","REVIEW_REQUIRED")
                          AND w.scheduled_date IS NOT NULL
                          AND (a.state IS NULL OR a.state = "pending")', 'i', [$siteId]) as $row) {
        [$ok] = fabo_linkedin_ready_row($row);
        if ($ok && trim((string) $row['linkedin_copy']) !== '') {
            $ids[] = (int) $row['id'];
        }
    }
    return $ids;
}

/**
 * The approval state of a website post: 'approved', 'rejected', 'pending',
 * or '' for a post from before approvals, which is judged by its status.
 */
function fabo_wc_approval_state(int $rowId): string
{
    try {
        $a = fabo_one('SELECT state FROM approvals WHERE sheet_key = "webcontent" AND row_id = ?',
                      'i', [$rowId]);
    } catch (Throwable $e) {
        return '';
    }
    return (string) ($a['state'] ?? '');
}

/** Bumped with each change to this feature, so the check page can say which code is live. */
const FABO_WC_VERSION = '2026-10-05.4';

/** How long a website may take before it is called stopped rather than slow. */
const FABO_WC_GIVE_UP_MINUTES = 15;

/**
 * Get a stalled website moving again, or say plainly that it has stopped.
 *
 * A website is stalled when it is part way through and nothing in the queue
 * is still going to move it: the job that should have was cut off by the
 * host and used up its tries, or failed in a way nobody saw. Before this, a
 * stalled website read "Reading the website — 5%" for ever.
 *
 * Called by the page's progress check, so a site heals while somebody is
 * watching it. Returns what was done, or '' when nothing was needed.
 */
function fabo_wc_heal(array $site): string
{
    $state  = (string) ($site['state'] ?? '');
    $siteId = (int) ($site['id'] ?? 0);
    if ($siteId <= 0 || !in_array($state, ['DISCOVERED', 'EXTRACTED',
                                            'READY_FOR_GENERATION', 'GENERATING'], true)) {
        return '';
    }
    $like = ['%"site_id":' . $siteId . ',%', '%"site_id":' . $siteId . '}%'];

    /* Anything that can still run for it -- waiting, or running inside its
       lease, or abandoned but with tries left (the queue reclaims those). */
    $open = (int) (fabo_one('SELECT COUNT(*) c FROM job_queue
                              WHERE kind LIKE "wc.%" AND attempts < ?
                                AND state IN ("waiting","running")
                                AND (payload LIKE ? OR payload LIKE ?)',
                            'iss', [FABO_JOB_TRIES, $like[0], $like[1]])['c'] ?? 0);
    if ($open > 0) {
        return '';
    }
    $owner = (int) ($site['client_id'] ?? 0);

    /* Too long altogether: stop, with the last thing the queue heard. */
    $age = (int) (fabo_one('SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) m
                              FROM website_sources WHERE id = ?', 'i', [$siteId])['m'] ?? 0);
    if ($age >= FABO_WC_GIVE_UP_MINUTES) {
        $why = (string) (fabo_one('SELECT last_error FROM job_queue
                                    WHERE kind LIKE "wc.%" AND last_error IS NOT NULL
                                      AND last_error <> "" AND state <> "done"
                                      AND (payload LIKE ? OR payload LIKE ?)
                                    ORDER BY id DESC LIMIT 1', 'ss', $like)['last_error'] ?? '');
        fabo_exec('UPDATE website_sources SET state = "FAILED", last_error = ? WHERE id = ?',
                  'si', [mb_substr('Stopped after ' . $age . ' minutes. '
                         . ($why !== '' ? $why : 'The server kept cutting the work off part way.')
                         . ' Press "Read the site again" to retry.', 0, 500), $siteId]);
        return 'stopped';
    }

    $again = static fn(string $kind, string $key) =>
        fabo_job_add($kind, ['site_id' => $siteId, 'client_id' => $owner], $owner,
                     /* One key per site and step, so three pollers healing
                        at once queue one job, not three. */
                     $key . ':' . $siteId . ':heal');

    if ($state === 'DISCOVERED') {
        $again('wc.crawl', 'crawl');
        return 'crawl';
    }
    if (trim((string) ($site['brand_profile'] ?? '')) === '') {
        $again('wc.extract', 'extract');
        return 'extract';
    }
    if ($state !== 'GENERATING' || (int) ($site['posts_planned'] ?? 0) === 0
        || trim((string) ($site['plan'] ?? '')) === '') {
        $again('wc.generate', 'generate');
        return 'generate';
    }

    /* Writing: put back the posts whose jobs died, each from its own slot. */
    $back = (int) fabo_exec('UPDATE job_queue
                                SET state = "waiting", attempts = 0, run_after = NOW(),
                                    lock_token = NULL
                              WHERE kind = "wc.write" AND state IN ("failed","running")
                                AND (payload LIKE ? OR payload LIKE ?)', 'ss', $like);
    if ($back > 0) {
        return 'write';
    }
    /* Nothing left to write, yet the count never reached the plan -- a job
       that wrote its post and was cut off before counting it. Finish. */
    fabo_exec('UPDATE website_sources SET state = "GENERATED", plan = NULL WHERE id = ?',
              'i', [$siteId]);
    return 'finished';
}

/* --------------------------------------------------------- starting it off */

/**
 * What the Add Website button does. Returns [siteId, note].
 *
 * $time is when each post goes out (HH:MM, the portal's own timezone),
 * $every how many days apart, and $auto whether a post that passes the claim
 * check is scheduled by itself rather than waiting to be approved.
 */
function fabo_wc_start(string $url, string $postDate, int $clientId, string $who,
                       string $time = '', int $every = 1, bool $auto = false): array
{
    $url = trim($url);
    if ($url === '') {
        throw new InvalidArgumentException('Give a website address.');
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    [$ok, $why] = fabo_url_safe($url);
    if (!$ok) {
        throw new InvalidArgumentException($why);
    }
    $date = trim($postDate);
    if ($date === '' || strtotime($date) === false) {
        throw new InvalidArgumentException('Give the date the first post should go out.');
    }
    /* Never before today: a post dated in the past would go out the moment
       it was written, which is not what "from the day it was added" means. */
    $date = max(date('Y-m-d', strtotime($date)), date('Y-m-d'));
    $time = preg_match('~^([01]\d|2[0-3]):[0-5]\d$~', trim($time)) ? trim($time) : '10:00';
    $every = in_array($every, [1, 2, 3, 7], true) ? $every : 1;
    $host = strtolower((string) (@parse_url($url, PHP_URL_HOST) ?: ''));

    $siteId = (int) fabo_exec(
        'INSERT INTO website_sources (client_id, website_url, site_host, post_date,
                                      post_time, post_every, auto_post, state, created_by)
         VALUES (?,?,?,?,?,?,?,"DISCOVERED",?)', 'issssiis',
        [$clientId, fabo_url_normalise($url), $host, $date, $time, $every, $auto ? 1 : 0,
         mb_substr($who, 0, 190)]);

    fabo_job_add('wc.crawl', ['site_id' => $siteId, 'client_id' => $clientId],
                 $clientId, 'crawl:' . $siteId);

    return [$siteId, 'Added. The site is being read now — the posts appear here as they are '
                   . 'written, usually within a few minutes.'];
}
