<?php
/**
 * Reading a website, so an AI does not have to read HTML.
 *
 * The temptation is to fetch a page and hand the markup straight to the
 * model. That goes wrong in three ways at once: the navigation, cookie
 * banner and footer appear on every page, so the model sees the same words
 * fifty times and thinks they are important; the markup costs more tokens
 * than the words it contains; and there is no way afterwards to say where a
 * claim came from.
 *
 * So each page is reduced to what it actually says, classified by what kind
 * of page it is, and stored with the sentences that carry the facts. Those
 * sentences — source_evidence — are what every generated claim is checked
 * against later. A statistic the AI writes that is not in the evidence did
 * not come from the website, and does not get published.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ssrf.php';
require_once __DIR__ . '/google.php';   // fabo_env_or()

/** How far, how wide, and how long a crawl may go.
 *
 * Ten pages, because the prompts only have room for about that many (see
 * fabo_wc_source_digest()) and the pages are read most-valuable first. Every
 * page past that was time spent waiting for words nobody would read -- on a
 * site that has to be rendered, four or five seconds a page. */
const FABO_CRAWL_MAX_PAGES = 10;
const FABO_CRAWL_MAX_DEPTH = 2;

/** The kinds of page worth telling apart, in the order they are tested. */
function fabo_page_types(): array
{
    return [
        'Pricing'        => ['~/pricing~i', '~/plans?\b~i', '~/cost~i'],
        'Case Study'     => ['~/case-?stud~i', '~/success-?stor~i', '~/customer-?stor~i'],
        'Customer Story' => ['~/customers?/~i', '~/clients?/~i'],
        'Blog'           => ['~/blog~i', '~/news~i', '~/articles?~i', '~/insights?~i', '~/posts?/~i'],
        'Resource'       => ['~/resources?~i', '~/downloads?~i', '~/guides?~i', '~/ebooks?~i',
                             '~/whitepapers?~i', '~/reports?~i', '~/templates?~i', '~/tools?~i'],
        'Documentation'  => ['~/docs?~i', '~/documentation~i', '~/support/~i', '~/help/~i'],
        'Webinar'        => ['~/webinars?~i'],
        'Event'          => ['~/events?~i', '~/conference~i'],
        'FAQ'            => ['~/faqs?~i', '~/questions~i'],
        'About'          => ['~/about~i', '~/company~i', '~/who-we-are~i', '~/our-story~i',
                             '~/team~i', '~/careers?~i'],
        'Contact'        => ['~/contact~i', '~/get-in-touch~i', '~/book-a~i', '~/demo~i'],
        'Service'        => ['~/services?~i', '~/solutions?~i', '~/what-we-do~i', '~/capabilit~i'],
        'Feature'        => ['~/features?~i', '~/platform~i', '~/how-it-works~i'],
        'Product'        => ['~/products?~i', '~/shop~i', '~/store~i', '~/collections?~i'],
        'Landing Page'   => ['~/lp/~i', '~/landing~i', '~/campaign~i'],
    ];
}

/** What kind of page is this, judged on its address and then its words. */
function fabo_classify_page(string $url, string $title, string $text, array $ld): string
{
    $path = (string) (@parse_url($url, PHP_URL_PATH) ?: '/');
    if ($path === '/' || $path === '') {
        return 'Homepage';
    }
    foreach (fabo_page_types() as $type => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $path)) {
                return $type;
            }
        }
    }
    /* Structured data is the site telling us directly, so it beats guessing
       from the words — but only after the address, which is more reliable
       still because a shop puts every product under /products/. */
    foreach ($ld as $node) {
        $t = strtolower((string) ($node['@type'] ?? ''));
        if ($t === 'product' || $t === 'offer')        { return 'Product'; }
        if ($t === 'service')                          { return 'Service'; }
        if (in_array($t, ['blogposting', 'article', 'newsarticle'], true)) { return 'Blog'; }
        if ($t === 'faqpage')                          { return 'FAQ'; }
        if ($t === 'event')                            { return 'Event'; }
        if ($t === 'aboutpage')                        { return 'About'; }
        if ($t === 'contactpage')                      { return 'Contact'; }
    }
    $hay = strtolower($title . ' ' . mb_substr($text, 0, 600));
    foreach ([
        'Pricing' => ['pricing', 'per month', 'free trial', 'plans'],
        'About'   => ['about us', 'our story', 'founded in', 'our mission'],
        'Contact' => ['contact us', 'get in touch', 'book a demo'],
    ] as $type => $words) {
        foreach ($words as $w) {
            if (str_contains($hay, $w)) {
                return $type;
            }
        }
    }
    return 'Other';
}

/** Everything between two tags, gone — scripts, styles, and what they hold. */
function fabo_strip_noise(string $html): string
{
    $html = preg_replace('~<(script|style|noscript|svg|iframe|template)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
    $html = preg_replace('~<!--.*?-->~s', ' ', $html) ?? $html;
    /* The furniture every page carries. Dropped because a phrase that
       appears on all fifty pages tells the model nothing except that it is
       important, which it is not. */
    $html = preg_replace('~<(nav|header|footer|aside|form)\b[^>]*>.*?</\1>~is', ' ', $html) ?? $html;
    return $html;
}

/** The readable words of a page, in order, with the blank space collapsed. */
function fabo_visible_text(string $html): string
{
    $html = fabo_strip_noise($html);
    /* Block elements become line breaks first, or "Pricing" and "From £9"
       run together into one unreadable string. */
    $html = preg_replace('~</(p|div|li|tr|h[1-6]|section|article|br)\s*>~i', "\n", $html) ?? $html;
    $html = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\xc2\xa0"], ["\n", ' '], $text);
    $text = preg_replace('~[ \t]+~', ' ', $text) ?? $text;
    $text = preg_replace('~\n\s*\n\s*\n+~', "\n\n", $text) ?? $text;

    /* A line repeated down the page is a menu that survived the strip. */
    $seen  = [];
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || mb_strlen($line) < 2) {
            continue;
        }
        $k = mb_strtolower($line);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $lines[]  = $line;
    }
    return trim(implode("\n", $lines));
}

/** One tag's attribute, when a regex is enough and a parser is not worth it. */
function fabo_meta(string $html, string $re): string
{
    return preg_match($re, $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
}

/** Every JSON-LD block on the page, flattened to a list of nodes. */
function fabo_json_ld(string $html): array
{
    if (!preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $raw) {
        $data = json_decode(trim($raw), true);
        if (!is_array($data)) {
            continue;
        }
        $nodes = isset($data['@graph']) && is_array($data['@graph'])
               ? $data['@graph']
               : (isset($data[0]) ? $data : [$data]);
        foreach ($nodes as $n) {
            if (is_array($n)) {
                $out[] = $n;
            }
        }
    }
    return $out;
}

/**
 * The sentences worth keeping as evidence.
 *
 * A claim the AI makes later is checked against these, so what counts is a
 * sentence carrying something checkable: a number, a currency, a percentage,
 * a named thing, a superlative. Prose that says the company is passionate
 * about excellence supports nothing and is left out.
 */
function fabo_evidence(string $text, int $max = 40): array
{
    $out = [];
    foreach (preg_split('~(?<=[.!?])\s+|\n~', $text) as $s) {
        $s = trim($s);
        $len = mb_strlen($s);
        if ($len < 25 || $len > 320) {
            continue;
        }
        $keep = preg_match('~\d~', $s)                       // any number
             || preg_match('~[$£€₹]~u', $s)                  // money
             || preg_match('~\b\d+\s*%~', $s)                // a percentage
             || preg_match('~\b(award|certified|ISO|partner|trusted by|customers?|clients?'
                         . '|founded|since|years?|winner|accredited|rated)\b~i', $s);
        if ($keep) {
            $out[] = $s;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

/**
 * Where the company's logo is, best first.
 *
 * Scored rather than taken in page order, because the first image marked
 * "logo" is often somebody else's -- a partner, an accreditor, a client --
 * and their logo on this company's posts would be a real mistake. What
 * counts most is the image naming the company itself (its address, in the
 * file name or the alt text) or the site's structured data declaring it.
 *
 * $extra is the images a rendered copy of the page showed, for sites that
 * draw their header with JavaScript. Returns [urls, sure] where sure means
 * the best one names the company.
 */
function fabo_page_logo(string $html, string $base, array $extra = []): array
{
    $host  = strtolower((string) (@parse_url($base, PHP_URL_HOST) ?: ''));
    $host  = preg_replace('~^www\.~', '', $host) ?? $host;
    $token = preg_replace('~[^a-z0-9]~', '', explode('.', $host)[0] ?? '') ?? '';
    $names = static fn(string $s) => $token !== '' && strlen($token) >= 3
        && str_contains(preg_replace('~[^a-z0-9]~', '', strtolower($s)) ?? '', $token);

    $score = [];
    $add = static function (string $u, int $pts) use (&$score, $base) {
        $u = trim(html_entity_decode($u, ENT_QUOTES, 'UTF-8'));
        if ($u === '' || str_starts_with($u, 'data:')) {
            return;
        }
        $abs = fabo_url_absolute($u, $base);
        if ($abs !== '') {
            $score[$abs] = max($score[$abs] ?? PHP_INT_MIN, $pts);
        }
    };
    $judge = static function (string $src, string $alt, string $attrs, int $order) use ($names, $add) {
        /* The file's own name, not its address: every image on a site lives
           at the company's domain, so the domain says nothing. */
        $file   = rawurldecode(basename((string) (@parse_url($src, PHP_URL_PATH) ?: $src)));
        $marked = (bool) preg_match('~logo~i', $file . ' ' . $attrs . ' ' . $alt);
        /* Alt text naming the company marks a logo only when that is all it
           says ("MarkUp") or it says logo; "RTIH ideate/innovate infographic"
           is a picture about the company, not its mark. */
        $rest = 0;
        foreach (preg_split('~[^\p{L}\p{N}]+~u', $alt) ?: [] as $word) {
            if (mb_strlen($word) > 1 && !$names($word)) {
                $rest++;                    // a word that is not the company's name
            }
        }
        /* The same test for the file name: "rtih-logo" and "markup-white"
           are the company's mark; "about-rtih" is a picture about it. */
        $fileRest = 0;
        $stem     = preg_replace('~\.[a-z0-9]+$~i', '', $file) ?? $file;
        foreach (preg_split('~[^\p{L}\p{N}]+~u', $stem) ?: [] as $word) {
            if (mb_strlen($word) > 1 && !$names($word) && !preg_match(
                    '~^(logo|logos|icon|mark|brand|img|image|main|header|nav|site|white|black|dark|'
                  . 'light|colou?r|full|small|large|new|final|primary|default|horizontal|'
                  . 'vertical|\d+x|v\d+|\d+)$~i', $word)) {
                $fileRest++;
            }
        }
        $self = ($names($file) && $fileRest === 0) || ($names($alt) && ($marked || $rest === 0));
        if (!$self && !$marked) {
            return;
        }
        $pts = ($self ? 10 : 0) + ($marked ? 3 : 0) - min(3, $order);
        /* A logo whose alt text names something else is somebody else's. */
        if (!$self && trim($alt) !== '') {
            $pts -= 8;
        }
        $add($src, $pts);
    };

    foreach (fabo_json_ld($html) as $node) {
        $type = strtolower(implode(' ', (array) ($node['@type'] ?? '')));
        if (preg_match('~organization|corporation|business|school|brand|college~', $type)
            && !empty($node['logo'])) {
            $logo = $node['logo'];
            $add(is_array($logo) ? (string) ($logo['url'] ?? $logo['contentUrl'] ?? '') : (string) $logo, 12);
        }
    }
    if (preg_match_all('~<img\b[^>]*>~i', $html, $imgs)) {
        foreach ($imgs[0] as $i => $tag) {
            $src = fabo_meta($tag, '~\bsrc=["\']([^"\']+)["\']~i')
                ?: fabo_meta($tag, '~\bdata-src=["\']([^"\']+)["\']~i');
            $judge($src, fabo_meta($tag, '~\balt=["\']([^"\']*)["\']~i'), $tag, $i);
        }
    }
    foreach (array_values($extra) as $i => $img) {
        $judge((string) ($img['url'] ?? ''), (string) ($img['alt'] ?? ''), '', $i);
    }
    /* The icons a site gives phones and browsers are always its own. */
    foreach (['~<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]*href=["\']([^"\']+)~i' => 5,
              '~<link[^>]+href=["\']([^"\']+)["\'][^>]*rel=["\'][^"\']*apple-touch-icon~i' => 5,
              '~<meta[^>]+property=["\']og:logo["\'][^>]+content=["\']([^"\']+)~i' => 8,
              '~<link[^>]+rel=["\'][^"\']*icon[^"\']*["\'][^>]*href=["\']([^"\']+\.png[^"\']*)~i' => 2,
              '~<link[^>]+href=["\']([^"\']+\.png[^"\']*)["\'][^>]*rel=["\'][^"\']*icon~i' => 2] as $re => $pts) {
        if (preg_match($re, $html, $m)) {
            $add($m[1], $pts);
        }
    }
    $score = array_filter($score, static fn($p) => $p > 0);
    arsort($score);
    $best = (int) (reset($score) ?: 0);
    return [array_slice(array_keys($score), 0, 6), $best >= 8];
}

/** The images a post could actually use: big enough, and not an icon. */
function fabo_page_images(string $html, string $base): array
{
    $out = [];
    if (preg_match_all('~<img[^>]+>~i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            $src = fabo_meta($tag, '~\bsrc=["\']([^"\']+)["\']~i');
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            if (preg_match('~(sprite|icon|logo-?small|pixel|spacer|1x1)~i', $src)) {
                continue;
            }
            $abs = fabo_url_absolute($src, $base);
            if ($abs === '') {
                continue;
            }
            $out[$abs] = ['url' => $abs, 'alt' => fabo_meta($tag, '~\balt=["\']([^"\']*)["\']~i')];
            if (count($out) >= 12) {
                break;
            }
        }
    }
    $og = fabo_meta($html, '~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)~i');
    if ($og !== '') {
        $abs = fabo_url_absolute($og, $base);
        if ($abs !== '') {
            $out = [$abs => ['url' => $abs, 'alt' => 'Open Graph image']] + $out;
        }
    }
    return array_values($out);
}

/** One page, fetched and reduced. Returns null when there is nothing to keep. */
function fabo_read_page(string $url): ?array
{
    [$ok, $html, $meta] = fabo_url_open($url);
    if (!$ok || $html === '') {
        /* The server could not fetch it itself -- a firewall that turns
           away hosting addresses, a site on the same host, a bot filter.
           The renderer fetches from elsewhere, so it is asked instead. */
        return fabo_page_from_render($url);
    }
    if ($meta['type'] !== '' && !preg_match('~text/html|application/xhtml~i', $meta['type'])) {
        return null;                        // a PDF or an image is not a page to read
    }
    $text = fabo_visible_text($html);
    $ld   = fabo_json_ld($html);
    $spa  = fabo_is_js_page($html);

    /* A page whose body is empty until JavaScript runs — an Angular or React
       app served as a shell. Read it again through a renderer that runs the
       scripts, so the words on the grid are the site's own words rather than
       something somebody has to paste in by hand. Its links come back too,
       which is what lets the crawl get past the homepage of such a site. */
    $rendered = null;
    $isHome   = in_array((string) (@parse_url($url, PHP_URL_PATH) ?: '/'),
                         ['/', '', '/index.html', '/index.php'], true);
    if (mb_strlen($text) < 400 && ($spa || mb_strlen($text) < 120)) {
        $rendered = fabo_rendered_page($url, $isHome);
        if ($rendered && mb_strlen($rendered['text']) > mb_strlen($text)) {
            $text = $rendered['text'];
        } else {
            $rendered = null;
        }
    }

    if (mb_strlen($text) < 120) {
        /* Nothing even after rendering. The head is usually written for
           search engines and social previews, and that is a real description
           of the page written by the company itself. Thin, and far better
           than nothing.

           Marked so the caller can say plainly what happened rather than
           reporting an empty crawl. */
        $meta = fabo_head_content($html);

        /* Before settling for the head, look in the scripts the page asked
           for. A site built this way keeps its own words in there, and they
           are the words somebody wrote about the company rather than a
           sentence written for Google. */
        $fromJs = $spa ? fabo_js_sentences($html, $url) : '';
        if (mb_strlen($fromJs) >= 200) {
            $text = $meta !== '' ? $meta . "\n" . $fromJs : $fromJs;
        } elseif (mb_strlen($meta) >= 60) {
            $text = $meta;
        } else {
            return null;                    // nothing a person would call content
        }
    }
    $title = fabo_meta($html, '~<title[^>]*>(.*?)</title~is');
    if ($title === '' && $rendered) {
        $title = $rendered['title'];
    }
    if ($title === '') {
        $title = fabo_meta($html, '~<h1[^>]*>(.*?)</h1~is');
    }
    $desc = fabo_meta($html, '~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i');
    if ($desc === '') {
        $desc = fabo_meta($html, '~<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)~i');
    }
    $canon = fabo_meta($html, '~<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)~i');
    $canon = $canon !== '' ? fabo_url_normalise(fabo_url_absolute($canon, $url)) : '';

    /* Headings carry the page's own outline, which survives being cut for
       length far better than the middle of a paragraph does. */
    $heads = $rendered ? $rendered['headings'] : [];
    if (preg_match_all('~<h([1-3])[^>]*>(.*?)</h\1>~is', fabo_strip_noise($html), $hm)) {
        foreach ($hm[2] as $h) {
            $h = trim(html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8'));
            if ($h !== '' && mb_strlen($h) < 200) {
                $heads[] = $h;
            }
        }
    }
    $links = fabo_page_links($html, $url);
    if ($rendered) {
        $links = array_values(array_unique(array_merge($links, $rendered['links'])));
    }

    /* The company's logo, looked for on the homepage. When the page itself
       does not name it plainly, the rendered page is asked too -- many sites
       draw their header with JavaScript, and that is where the logo is. */
    $logo = [];
    if ($isHome) {
        [$logo, $sure] = fabo_page_logo($html, $url, $rendered['images'] ?? []);
        if (!$sure && !$rendered) {
            $rendered = fabo_rendered_page($url, true) ?: null;
            if ($rendered) {
                [$logo] = fabo_page_logo($html, $url, $rendered['images']);
            }
        }
    }
    $images = [];
    foreach (array_merge(fabo_page_images($html, $url), $rendered['images'] ?? []) as $img) {
        $u = (string) ($img['url'] ?? '');
        if ($u === '' || isset($images[$u]) || in_array($u, $logo, true)
            || preg_match('~\.svg(\?|$)|logo|icon|favicon|sprite~i', $u . ' ' . ($img['alt'] ?? ''))) {
            continue;
        }
        $images[$u] = $img;
        if (count($images) >= 16) {
            break;
        }
    }
    $images = array_values($images);

    return [
        /* Thin only if there was nothing anywhere: no body, and no words in
           the scripts either. A page recovered from its bundle reads like any
           other, and flagging it would send somebody off to paste content
           that is already here. */
        'js_only'            => $spa && mb_strlen($text) < 120,
        'source_url'         => fabo_url_normalise($url),
        'canonical_url'      => $canon,
        'source_title'       => mb_substr($title, 0, 400),
        'source_description' => mb_substr($desc, 0, 1000),
        'source_page_type'   => fabo_classify_page($url, $title, $text, $ld),
        'source_content'     => mb_substr($text, 0, 20000),
        'headings'           => array_slice(array_unique($heads), 0, 30),
        'source_evidence'    => fabo_evidence($text),
        'structured_data'    => $ld,
        'images'             => $images,
        'logo'               => $logo,
        'links'              => $links,
        'content_hash'       => sha1($text),
    ];
}

/** A page built from the renderer alone, for when the server cannot fetch it. */
function fabo_page_from_render(string $url): ?array
{
    $r = fabo_rendered_page($url);
    if (!$r || mb_strlen($r['text']) < 120) {
        return null;
    }
    return [
        'js_only'            => false,
        'source_url'         => fabo_url_normalise($url),
        'canonical_url'      => '',
        'source_title'       => $r['title'],
        'source_description' => '',
        'source_page_type'   => fabo_classify_page($url, $r['title'], $r['text'], []),
        'source_content'     => mb_substr($r['text'], 0, 20000),
        'headings'           => $r['headings'],
        'source_evidence'    => fabo_evidence($r['text']),
        'structured_data'    => [],
        'images'             => array_values(array_filter($r['images'],
                                    static fn($i) => !preg_match('~\.svg(\?|$)~i', $i['url']))),
        'logo'               => [],
        'links'              => $r['links'],
        'content_hash'       => sha1($r['text']),
    ];
}

/**
 * Is this page a shell that only fills in once JavaScript has run?
 *
 * Worth knowing precisely, because "we read nothing" and "there is nothing
 * to read without a browser" are different problems with different answers,
 * and telling somebody the wrong one wastes their afternoon.
 */
/** How many scripts one page may have read out of it, and how big. */
const FABO_JS_SCRIPTS   = 4;
const FABO_JS_MAX_BYTES = 3000000;

/**
 * The sentences a page's own JavaScript is carrying.
 *
 * A site that draws itself in the browser compiles its templates into its
 * bundle, so the headings and paragraphs are in there as string literals.
 * They can be read without running anything.
 *
 * Prose is told from code by looking like prose: several words, sentence
 * punctuation, and none of the brackets, arrows and keywords that code is
 * made of. That is a rough rule, and it is allowed to be -- a stray line of
 * code among forty real sentences costs far less than forty missing
 * sentences.
 *
 * Only scripts on the page's own host, because a page names a dozen
 * third-party scripts and none of them describe the company.
 */
/**
 * The human sentences inside a lump of JavaScript.
 *
 * A site that draws itself in the browser compiles its templates into its
 * bundle, so the headings and paragraphs are in there as string literals.
 * They can be read without running anything.
 *
 * Prose is told from code by looking like prose: several words, sentence
 * punctuation, and none of the brackets, arrows and keywords that code is
 * made of. That is a rough rule, and it is allowed to be — a stray line of
 * code among forty real sentences costs far less than forty missing
 * sentences.
 *
 * Kept apart from the fetching, because the judgement is the part worth
 * being sure about and it can be exercised here on its own.
 */
function fabo_js_prose(string $js): array
{
    if (!preg_match_all('~["\'`]([^"\'`]{40,400})["\'`]~', $js, $q)) {
        return [];
    }
    $out = [];
    foreach ($q[1] as $s) {
        /* A bundle keeps its text the way JavaScript writes it: curly quotes
           and accents arrive as \uXXXX escapes. Decoded through JSON, which
           uses the same escaping. */
        if (str_contains($s, '\\u')) {
            $un = json_decode('"' . str_replace('"', '\\"', $s) . '"');
            if (is_string($un)) {
                $s = $un;
            }
        }
        $s = trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($s === '' || mb_substr_count($s, ' ') < 6) {
            continue;                       // a word or two is a label, not prose
        }
        if (!preg_match('~[.!?,;:]~', $s)) {
            continue;                       // no punctuation at all: probably a key
        }
        if (preg_match('~[{}<>@\\|]|=>|function |return |var |const |let |'
                     . 'null|undefined|\$\{~i', $s)) {
            continue;                       // code, or a template with code in it
        }
        if (preg_match('~^[0-9\s.,;:+-]+$~', $s)) {
            continue;                       // numbers and separators
        }
        /* Letters should outweigh the punctuation by a good margin, or a
           minified fragment slips through looking like a long sentence. */
        if (preg_match_all('~[\p{L}\s]~u', $s) < mb_strlen($s) * 0.8) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/**
 * The sentences a page's own scripts are carrying.
 *
 * Only scripts on the page's own host, because a page names a dozen
 * third-party scripts and none of them describe the company; and only until
 * a budget is used up, because a crawler that follows every script a page
 * mentions is a crawler that fetches half the advertising internet.
 */
function fabo_js_sentences(string $html, string $base): string
{
    $host = strtolower((string) (@parse_url($base, PHP_URL_HOST) ?: ''));
    if ($host === '') {
        return '';
    }
    if (!preg_match_all('~<script[^>]+src=["\']([^"\']+)["\']~i', $html, $m)) {
        return '';
    }

    $lines = [];
    $seen  = [];
    $spent = 0;
    $read  = 0;

    foreach ($m[1] as $src) {
        if ($read >= FABO_JS_SCRIPTS || $spent >= FABO_JS_MAX_BYTES) {
            break;
        }
        $url = fabo_url_absolute($src, $base);
        if ($url === '' || strtolower((string) (@parse_url($url, PHP_URL_HOST) ?: '')) !== $host) {
            continue;                       // somebody else's script
        }
        [$ok, $js, $meta] = fabo_url_open($url);
        if (!$ok || $js === '') {
            continue;
        }
        $read++;
        $spent += mb_strlen($js);

        foreach (fabo_js_prose($js) as $s) {
            $k = mb_strtolower($s);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $lines[]  = $s;
        }
    }
    return implode("\n", $lines);
}

/** Where JavaScript pages are sent to be drawn. Override with RENDER_ENDPOINT. */
const FABO_RENDER_ENDPOINT = 'https://r.jina.ai/';
const FABO_RENDER_TIMEOUT  = 20;

/**
 * A page as a browser would show it, for sites that draw themselves.
 *
 * The page is handed to a reader that runs its scripts and returns what ends
 * up on screen, as Markdown. Only the words, headings and on-site links are
 * kept. Returns null when the renderer is unreachable or switched off
 * (RENDER_ENDPOINT set to "off"), in which case the crawl falls back to the
 * page head exactly as before.
 *
 * The address is checked with fabo_url_safe() first: the renderer fetches it
 * from outside, but an address that is unsafe to fetch here is not one to
 * hand anyone else either.
 */
function fabo_rendered_page(string $url, bool $withHeader = false): ?array
{
    $endpoint = trim(fabo_env_or('RENDER_ENDPOINT', FABO_RENDER_ENDPOINT));
    if ($endpoint === '' || strtolower($endpoint) === 'off' || !function_exists('curl_init')) {
        return null;
    }
    [$safe] = fabo_url_safe($url);
    if (!$safe) {
        return null;
    }
    $headers = ['Accept: text/plain',
                'X-Return-Format: markdown',
                'X-Timeout: ' . (FABO_RENDER_TIMEOUT - 4)];
    /* The furniture every page carries, as in fabo_strip_noise() -- except
       on the homepage, whose header is where the company's logo is. */
    $headers[] = $withHeader ? 'X-Remove-Selector: footer, form'
                             : 'X-Remove-Selector: nav, header, footer, aside, form, [role=navigation]';
    $key = trim(fabo_env_or('RENDER_API_KEY', fabo_env_or('JINA_API_KEY')));
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }
    $ch = curl_init(rtrim($endpoint, '/') . '/' . $url);
    $opt = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => FABO_RENDER_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if (defined('SMTP_CAFILE') && is_file(SMTP_CAFILE)) {
        $opt[CURLOPT_CAINFO] = SMTP_CAFILE;
    }
    curl_setopt_array($ch, $opt);
    $md     = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    if (!is_string($md) || $status < 200 || $status >= 300 || trim($md) === '') {
        return null;
    }
    $md = substr($md, 0, FABO_FETCH_MAX_BYTES);

    $title = preg_match('~^Title:\s*(.+)$~m', $md, $m) ? trim($m[1]) : '';
    if (($at = strpos($md, 'Markdown Content:')) !== false) {
        $md = substr($md, $at + 17);
    }

    /* Its pictures, with their alt text: the logo and photos of a site that
       draws its header and pages with JavaScript are only visible here. */
    $images = [];
    if (preg_match_all('~!\[([^\]]*)\]\((https?://[^)\s]+)~', $md, $im, PREG_SET_ORDER)) {
        foreach ($im as $m) {
            $alt = trim(preg_replace('~^Image\s+\d+:?\s*~i', '', $m[1]) ?? $m[1]);
            $images[$m[2]] = ['url' => $m[2], 'alt' => $alt];
            if (count($images) >= 20) {
                break;
            }
        }
    }

    /* On-site links, before they are flattened into their text. */
    $links = [];
    if (preg_match_all('~\]\((https?://[^)\s]+)~', $md, $lm)) {
        foreach ($lm[1] as $href) {
            if (!fabo_url_same_site($href, $url)
                || preg_match('~\.(pdf|zip|jpe?g|png|gif|webp|svg|mp4|mp3|docx?|xlsx?|pptx?|csv)(\?|$)~i', $href)) {
                continue;
            }
            $n = fabo_url_normalise($href);
            if ($n !== '') {
                $links[$n] = true;
            }
        }
    }

    $heads = [];
    if (preg_match_all('~^#{1,3}\s+(.+)$~m', $md, $hm)) {
        foreach ($hm[1] as $h) {
            $h = trim(preg_replace('~\[([^\]]*)\]\([^)]*\)~', '$1', $h) ?? $h, " #*\t");
            if ($h !== '' && mb_strlen($h) < 200) {
                $heads[] = $h;
            }
        }
    }

    /* Markdown down to words: images go, links keep their text, and the
       emphasis and heading marks are dropped. */
    $t = preg_replace('~!\[[^\]]*\]\([^)]*\)~', ' ', $md) ?? $md;
    $t = preg_replace('~\[([^\]]*)\]\([^)]*\)~', '$1', $t) ?? $t;
    $t = preg_replace('~^\s{0,3}(#{1,6}|>|[-*+]|\d+\.)\s+~m', '', $t) ?? $t;
    $t = preg_replace('~^[\s=\-_*|:]+$~m', '', $t) ?? $t;
    $t = str_replace(['**', '__', '`'], '', $t);

    $seen  = [];
    $lines = [];
    foreach (explode("\n", $t) as $line) {
        $line = trim(preg_replace('~[ \t]+~', ' ', $line) ?? $line);
        if (mb_strlen($line) < 2 || isset($seen[$k = mb_strtolower($line)])) {
            continue;
        }
        $seen[$k] = true;
        $lines[]  = $line;
    }
    return ['title'    => mb_substr($title, 0, 400),
            'text'     => trim(implode("\n", $lines)),
            'headings' => array_slice(array_unique($heads), 0, 30),
            'images'   => array_values($images),
            'links'    => array_keys($links)];
}

function fabo_is_js_page(string $html): bool
{
    return (bool) preg_match('~<app-root|<div[^>]+id=["\'](root|app|__next)["\']|'
                           . 'ng-version=|__NUXT__|data-reactroot~i', $html);
}

/**
 * What the head says about the page, when the body says nothing.
 *
 * Meta descriptions, Open Graph and Twitter cards are written for search
 * engines and link previews, which means they are the company describing
 * its own page in its own words. On a JavaScript-rendered site they are
 * often the only server-rendered prose there is.
 */
function fabo_head_content(string $html): string
{
    $bits = [];
    foreach ([
        '~<title[^>]*>(.*?)</title~is',
        '~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i',
        '~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)~i',
        '~<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)~i',
        '~<meta[^>]+name=["\']twitter:title["\'][^>]+content=["\']([^"\']*)~i',
        '~<meta[^>]+name=["\']twitter:description["\'][^>]+content=["\']([^"\']*)~i',
        '~<meta[^>]+name=["\']keywords["\'][^>]+content=["\']([^"\']*)~i',
    ] as $re) {
        $v = fabo_meta($html, $re);
        if ($v !== '' && !in_array($v, $bits, true)) {
            $bits[] = $v;
        }
    }
    /* The h1 as well: some shells render one server-side for SEO even when
       the rest of the page waits for the script. */
    if (preg_match_all('~<h1[^>]*>(.*?)</h1>~is', $html, $m)) {
        foreach ($m[1] as $h) {
            $h = trim(html_entity_decode(strip_tags($h), ENT_QUOTES, 'UTF-8'));
            if ($h !== '' && !in_array($h, $bits, true)) {
                $bits[] = $h;
            }
        }
    }
    return implode("
", $bits);
}

/** Every on-site link worth following. */
function fabo_page_links(string $html, string $base): array
{
    if (!preg_match_all('~<a[^>]+href=["\']([^"\']+)["\']~i', $html, $m)) {
        return [];
    }
    $out = [];
    foreach ($m[1] as $href) {
        $abs = fabo_url_absolute(html_entity_decode($href, ENT_QUOTES, 'UTF-8'), $base);
        if ($abs === '' || !fabo_url_same_site($abs, $base)) {
            continue;
        }
        /* Files are not pages. Following them wastes the page budget on
           things the crawler cannot read anyway. */
        if (preg_match('~\.(pdf|zip|jpe?g|png|gif|webp|svg|mp4|mp3|docx?|xlsx?|pptx?|csv)(\?|$)~i', $abs)) {
            continue;
        }
        $n = fabo_url_normalise($abs);
        if ($n !== '') {
            $out[$n] = true;
        }
    }
    return array_keys($out);
}

/** What robots.txt forbids, for the paths it forbids to everybody. */
function fabo_robots_rules(string $root): array
{
    [$ok, $body] = fabo_url_open(rtrim($root, '/') . '/robots.txt');
    if (!$ok || $body === '') {
        return ['deny' => [], 'sitemaps' => []];
    }
    $deny     = [];
    $sitemaps = [];
    $applies  = false;
    foreach (preg_split('~\R~', $body) as $line) {
        $line = trim(preg_replace('~#.*$~', '', $line) ?? '');
        if ($line === '') {
            continue;
        }
        if (preg_match('~^user-agent:\s*(.+)$~i', $line, $m)) {
            $applies = trim($m[1]) === '*';
            continue;
        }
        if (preg_match('~^sitemap:\s*(.+)$~i', $line, $m)) {
            $sitemaps[] = trim($m[1]);
            continue;
        }
        if ($applies && preg_match('~^disallow:\s*(.*)$~i', $line, $m)) {
            $path = trim($m[1]);
            if ($path !== '') {
                $deny[] = $path;
            }
        }
    }
    return ['deny' => $deny, 'sitemaps' => $sitemaps];
}

/** Does robots.txt say to leave this alone? */
function fabo_robots_allows(string $url, array $rules): bool
{
    $path = (string) (@parse_url($url, PHP_URL_PATH) ?: '/');
    foreach ($rules['deny'] as $deny) {
        if ($deny === '/' || str_starts_with($path, rtrim($deny, '*'))) {
            return false;
        }
    }
    return true;
}

/** Every URL a sitemap offers, following sitemap indexes one level down. */
function fabo_sitemap_urls(string $url, int $depth = 0): array
{
    if ($depth > 1) {
        return [];
    }
    [$ok, $xml] = fabo_url_open($url);
    if (!$ok || $xml === '') {
        return [];
    }
    $out = [];
    if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $xml, $m)) {
        $isIndex = stripos($xml, '<sitemapindex') !== false;
        foreach ($m[1] as $loc) {
            $loc = html_entity_decode(trim($loc), ENT_QUOTES, 'UTF-8');
            if ($isIndex) {
                foreach (fabo_sitemap_urls($loc, $depth + 1) as $u) {
                    $out[$u] = true;
                }
                if (count($out) > 500) {
                    break;
                }
            } else {
                $n = fabo_url_normalise($loc);
                if ($n !== '') {
                    $out[$n] = true;
                }
            }
        }
    }
    return array_keys($out);
}

/**
 * Which pages are worth the budget.
 *
 * A crawler that takes the first forty links it meets comes back with forty
 * blog posts from 2019. The homepage, what the company sells and who it has
 * sold to are what marketing content is made from, so those go first and the
 * long tail gets whatever is left.
 */
function fabo_page_priority(string $url): int
{
    $path = strtolower((string) (@parse_url($url, PHP_URL_PATH) ?: '/'));
    if ($path === '/' || $path === '') {
        return 0;
    }
    $depth = substr_count(trim($path, '/'), '/');
    foreach ([
        1 => ['~/about~', '~/company~', '~/our-story~'],
        2 => ['~/services?~', '~/solutions?~', '~/what-we-do~', '~/products?~', '~/features?~'],
        3 => ['~/case-?stud~', '~/success-?stor~', '~/customer~', '~/clients?~'],
        4 => ['~/pricing~', '~/plans?~'],
        5 => ['~/resources?~', '~/guides?~', '~/ebooks?~', '~/whitepapers?~', '~/reports?~'],
        6 => ['~/blog~', '~/insights?~', '~/articles?~', '~/news~'],
    ] as $rank => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $path)) {
                return $rank * 10 + $depth;
            }
        }
    }
    return 90 + $depth;
}

/**
 * Crawl a site and store what it says. Returns [pagesStored, note].
 *
 * Bounded on every axis — pages, depth, and the time one cron run may take —
 * because the address comes from a text box and a site can be infinite.
 */
/**
 * Crawl a little more of a site. Returns [done, storedThisSlice, note].
 *
 * Deliberately short. A crawl that runs for forty seconds is fine on a
 * command line and fatal inside a page load: the host kills the request
 * somewhere in the middle, the job stays marked as running, and nobody can
 * pick it up again until the lease expires. The person watching sees a
 * stage that never changes.
 *
 * So the frontier — the addresses still to visit — lives on the website
 * record rather than in a local variable, and every slice picks up where the
 * last one stopped. A slice that is killed costs that slice and nothing
 * else, because everything it had already read is in crawled_pages and
 * everything it had not is in the frontier.
 */
function fabo_crawl_slice(int $siteId, int $budgetSeconds = 12): array
{
    $site = fabo_one('SELECT * FROM website_sources WHERE id = ?', 'i', [$siteId]);
    if (!$site) {
        throw new InvalidArgumentException('That website record was not found.');
    }
    $root  = fabo_url_normalise((string) $site['website_url']);
    $owner = (int) $site['client_id'];
    if ($root === '') {
        throw new InvalidArgumentException('That website address cannot be read.');
    }
    $until = time() + max(4, $budgetSeconds);

    /* What has already been read, so a slice never fetches a page twice and
       a re-crawl updates rather than duplicates. */
    $seen = [];
    foreach (fabo_all('SELECT source_url, canonical_url FROM crawled_pages WHERE site_id = ?',
                      'i', [$siteId]) as $r) {
        $seen[(string) $r['source_url']] = true;
        if (trim((string) $r['canonical_url']) !== '') {
            $seen[(string) $r['canonical_url']] = true;
        }
    }
    $stored = count($seen);

    $frontier = json_decode((string) ($site['frontier'] ?? ''), true);
    $rules    = json_decode((string) ($site['robots_rules'] ?? ''), true);

    /* First slice: work out what the site says about itself. The sitemap is
       the site's own list of its pages, which beats walking the links — it
       includes pages nothing links to, and it is already the set the site
       wants found. Done once, because fetching it every slice would spend
       the whole budget on the same file. */
    if (!is_array($frontier)) {
        $rules    = fabo_robots_rules($root);
        $frontier = [$root => 0];
        foreach ($rules['sitemaps'] ?: [rtrim($root, '/') . '/sitemap.xml'] as $sm) {
            foreach (fabo_sitemap_urls($sm) as $u) {
                if (fabo_url_same_site($u, $root) && !isset($frontier[$u])) {
                    $frontier[$u] = 1;
                }
                if (count($frontier) > 300) {
                    break 2;              // enough to choose from
                }
            }
        }
        fabo_exec('UPDATE website_sources SET robots_rules = ? WHERE id = ?', 'si',
                  [json_encode($rules), $siteId]);
    }
    if (!is_array($rules)) {
        $rules = ['deny' => [], 'sitemaps' => []];
    }

    $did    = 0;
    $jsOnly = 0;
    while ($frontier && $stored < FABO_CRAWL_MAX_PAGES && time() < $until) {
        /* Most valuable first, so running out of pages costs the blog and
           never the homepage. */
        uksort($frontier, static fn($a, $b) => fabo_page_priority($a) <=> fabo_page_priority($b));
        $url   = (string) array_key_first($frontier);
        $depth = (int) $frontier[$url];
        unset($frontier[$url]);

        if (isset($seen[$url]) || $depth > FABO_CRAWL_MAX_DEPTH) {
            continue;
        }
        $seen[$url] = true;

        if (!fabo_robots_allows($url, $rules)) {
            continue;
        }
        $page = fabo_read_page($url);
        if (!$page) {
            continue;
        }
        /* A canonical URL pointing elsewhere means this address is a copy;
           the original is the one to keep. */
        if ($page['canonical_url'] !== '' && $page['canonical_url'] !== $page['source_url']) {
            if (isset($seen[$page['canonical_url']])) {
                continue;
            }
            $seen[$page['canonical_url']] = true;
        }
        fabo_store_page($siteId, $owner, $page, $depth);
        /* The logo is the company's, so it is taken from the first page that
           offers one -- the homepage, which is read first -- and kept. */
        if (!empty($page['logo'])) {
            fabo_exec('UPDATE website_sources SET logo = ?
                        WHERE id = ? AND (logo IS NULL OR logo = "" OR logo = "[]")', 'si',
                      [json_encode($page['logo'], JSON_UNESCAPED_SLASHES), $siteId]);
        }
        $stored++;
        $did++;
        if (!empty($page['js_only'])) {
            $jsOnly++;
        }

        if ($depth < FABO_CRAWL_MAX_DEPTH) {
            foreach ($page['links'] as $link) {
                if (!isset($seen[$link]) && !isset($frontier[$link])) {
                    $frontier[$link] = $depth + 1;
                }
            }
        }
    }

    $done = !$frontier || $stored >= FABO_CRAWL_MAX_PAGES;

    /* pages_found is what the person sees as the denominator, so it has to
       be something honest: how many we will actually read, not how many the
       sitemap happens to list. */
    $target = $done ? max(1, $stored) : min(FABO_CRAWL_MAX_PAGES, $stored + count($frontier));

    fabo_exec('UPDATE website_sources
                  SET frontier = ?, pages_found = ?, pages_crawled = ?, last_crawled_at = NOW()
                WHERE id = ?', 'siii',
              [json_encode(array_slice($frontier, 0, 300, true)), $target, $stored, $siteId]);

    $note = $stored === 0 && $done
        ? 'Nothing could be read from that site. It may need JavaScript to show its content.'
        : $stored . ' page' . ($stored === 1 ? '' : 's') . ' read.';

    /* A site built as a JavaScript app serves an empty page and fills it in
       the browser, so there is nothing for a server to read. Saying so is
       the difference between "the crawl is thin" and "here is why, and here
       is what was used instead" — because otherwise the obvious conclusion
       is that the feature is broken. */
    /* Only where the scripts gave nothing either. A page whose words were
       recovered is not thin, and saying it is would send somebody off to
       paste content that is already here. */
    if ($done && $jsOnly > 0 && $jsOnly >= $stored) {
        fabo_exec('UPDATE website_sources SET last_error = ? WHERE id = ?', 'si',
                  ['This site builds its pages in the browser, so the server sees them '
                 . 'empty. Only the page descriptions could be read, so the posts will be '
                 . 'broad rather than specific.', $siteId]);
    }
    return [$done, $did, $note];
}

/**
 * Read a whole site, slice after slice. For the command line and for tests —
 * a page load should call fabo_crawl_slice() and come back.
 */
function fabo_crawl_site(int $siteId, int $budgetSeconds = 40): array
{
    $until = time() + max(5, $budgetSeconds);
    $total = 0;
    $note  = '';
    while (time() < $until) {
        [$done, $did, $note] = fabo_crawl_slice($siteId, 10);
        $total += $did;
        if ($done) {
            break;
        }
    }
    return [$total, $note];
}

/** Store one page, remembering what it said last time so changes show up. */
function fabo_store_page(int $siteId, int $owner, array $page, int $depth): void
{
    $was = fabo_one('SELECT id, content_hash FROM crawled_pages
                     WHERE site_id = ? AND source_url = ?', 'is', [$siteId, $page['source_url']]);

    $json = static fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $body = $page['source_content'];
    if ($page['headings']) {
        $body = "HEADINGS\n" . implode("\n", $page['headings']) . "\n\n" . $body;
    }

    if ($was) {
        fabo_exec('UPDATE crawled_pages
                      SET canonical_url = ?, source_page_type = ?, source_title = ?,
                          source_description = ?, source_content = ?, source_evidence = ?,
                          structured_data = ?, images = ?, previous_hash = content_hash,
                          content_hash = ?, depth = ?, fetched_at = NOW()
                    WHERE id = ?', 'sssssssssii',
                  [$page['canonical_url'], $page['source_page_type'], $page['source_title'],
                   $page['source_description'], $body, $json($page['source_evidence']),
                   $json($page['structured_data']), $json($page['images']),
                   $page['content_hash'], $depth, (int) $was['id']]);
        return;
    }
    fabo_exec('INSERT INTO crawled_pages
               (client_id, site_id, source_url, canonical_url, source_page_type, source_title,
                source_description, source_content, source_evidence, structured_data, images,
                content_hash, depth, fetched_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())', 'iisssssssssi' . 'i',
              [$owner, $siteId, $page['source_url'], $page['canonical_url'],
               $page['source_page_type'], $page['source_title'], $page['source_description'],
               $body, $json($page['source_evidence']), $json($page['structured_data']),
               $json($page['images']), $page['content_hash'], $depth]);
}
