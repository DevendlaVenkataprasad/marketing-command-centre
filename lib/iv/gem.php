<?php
/**
 * Startup Discovery — the Gem check.
 *
 * The owner assigns a round one of the organisation's Gems from Incubation
 * Management (inc_gems: instructions, a weighted rubric, the questions). The
 * Gem is read from there, never copied, so editing it in Incubation changes
 * it here too.
 *
 * A HoD checks an idea in one of two ways, and both count towards the limit
 * (iv_ai_limit(): 2 per idea unless the owner says otherwise):
 *   portal  the portal sends the idea to Gemini with the Gem (our key)
 *   own     the HoD copies the same prompt into their own AI tool (Gemini,
 *           ChatGPT, ...) and pastes the whole reply back. The prompt holds a
 *           one-time check code the reply must return (iv_check_code), marks
 *           cannot be typed in, and the reply is kept for the owner. The owner
 *           can switch this way off per round (iv_rounds.own_ai).
 * The idea's score is the average of its checks, and the owner can run their
 * own verify check, outside the HoD's limit.
 * Each mark is clamped to its criterion's weight and the total is added up
 * here, so no reply can produce a score the rubric does not allow.
 */

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/../cryptolink.php';   // fabo_seal_key(): the check code

/* ================================================================ the Gem */

/** Runs $fn with Incubation's tables switched to $org's, and switches back. */
function iv_with_inc(int $org, callable $fn)
{
    require_once __DIR__ . '/../inc/gems.php';
    $prev = inc_org();
    inc_use_org($org);
    try {
        return $fn();
    } finally {
        inc_use_org($prev);
    }
}

/** Does this organisation have Incubation's Gems at all? */
function iv_gems_available(int $org): bool
{
    $t = $org > 0 ? 'inc_o' . $org . '_gems' : 'inc_gems';
    return (bool) fabo_one('SELECT 1 AS y FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', 's', [$t]);
}

/** The organisation's active Gems: id => decoded Gem. */
function iv_gems(int $org): array
{
    if (!iv_gems_available($org)) {
        return [];
    }
    return iv_with_inc($org, static fn() => inc_gems(true));
}

function iv_gem(int $org, int $id): ?array
{
    if ($id <= 0 || !iv_gems_available($org)) {
        return null;
    }
    return iv_with_inc($org, static fn() => inc_gem($id));
}

/** The prompt for one idea: the Gem's instructions, its rubric, the student's answers, and the reply shape. */
function iv_prompt(array $gem, array $idea, string $code = ''): string
{
    $rubric = '';
    foreach ($gem['criteria'] as $i => $c) {
        $rubric .= ($i + 1) . '. ' . $c['name'] . ' — maximum ' . rtrim(rtrim(number_format((float) $c['weight'], 1), '0'), '.') . ' points. ' . $c['asks'] . "\n";
    }
    $answers = '### Idea title' . "\n" . $idea['title'] . "\n\n";
    foreach (iv_idea_answers($idea) as $a) {
        $help = trim((string) ($a['help'] ?? ''));
        $answers .= '### ' . $a['label'] . "\n" . ($help !== '' ? 'Question asked: ' . $help . "\nAnswer:\n" : '') . ($a['value'] !== '' ? $a['value'] : '(no answer)') . "\n\n";
    }
    $improve = function_exists('inc_gem_improve_rule') ? inc_gem_improve_rule() : 'In "improvements" give 3 to 5 concrete changes that would make the idea itself better.';
    return trim((string) $gem['instructions']) . "\n\n"
         . "Score the student idea below against this rubric. Give each criterion a whole or half-point mark from 0 up to its maximum, "
         . "and a one or two sentence reason that cites what the student wrote. Judge only what is written; do not invent facts. "
         . "A missing or vague answer earns a low mark for the criteria it affects.\n\nRUBRIC (total " . rtrim(rtrim(number_format((float) $gem['max'], 1), '0'), '.') . " points)\n" . $rubric
         . "\nIDEA\n" . $answers
         . "Reply with JSON only, in this shape:\n"
         . '{' . ($code !== '' ? '"check":"' . $code . '",' : '') . '"criteria":[{"n":1,"score":0,"reason":""}],"summary":"three sentences: strengths, weaknesses, recommendation",'
         . '"improvements":["one specific thing to improve"]}'
         . "\nInclude every criterion, numbered as in the rubric. " . ($code !== '' ? 'Copy "check" exactly as given: ' . $code . '. ' : '') . $improve;
}

/**
 * Adds up marks against the rubric. $byN: criterion number (1-based) =>
 * ['score' => n, 'reason' => s]. Returns [total, breakdown]. Throws when a
 * criterion is missing.
 */
function iv_tally(array $gem, array $byN): array
{
    $total = 0.0;
    $breakdown = [];
    foreach ($gem['criteria'] as $i => $c) {
        $g = $byN[$i + 1] ?? null;
        if ($g === null || !is_numeric($g['score'] ?? null)) {
            throw new InvalidArgumentException('No mark for "' . $c['name'] . '".');
        }
        $s = max(0.0, min((float) $c['weight'], round((float) $g['score'] * 2) / 2));
        $total += $s;
        $breakdown[] = ['name' => $c['name'], 'max' => (float) $c['weight'], 'score' => $s, 'reason' => mb_substr(trim((string) ($g['reason'] ?? '')), 0, 600)];
    }
    return [$total, $breakdown];
}

/** A reply as the Gem asked for it, as an array; also finds the JSON inside a chatty reply or a ```json block. */
function iv_reply_json(string $text): ?array
{
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $j = json_decode($text, true);
    if (is_array($j)) {
        return $j;
    }
    $a = strpos($text, '{');
    $b = strrpos($text, '}');
    if ($a === false || $b === false || $b <= $a) {
        return null;
    }
    $j = json_decode(substr($text, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

/** criteria list from a reply -> [n => criterion]. */
function iv_reply_by_n(array $json): array
{
    $byN = [];
    foreach (is_array($json['criteria'] ?? null) ? $json['criteria'] : [] as $k => $c) {
        if (is_array($c)) {
            $byN[(int) ($c['n'] ?? $k + 1)] = $c;
        }
    }
    return $byN;
}

/* ================================================================== runs */

function iv_runs(int $org, int $ideaId): array
{
    return fabo_all('SELECT * FROM iv_ai_runs WHERE org_id = ? AND idea_id = ? AND state = "done" ORDER BY id DESC', 'ii', [$org, $ideaId]);
}

/** Checks used on an idea: finished ones, and one under way in the last 5 minutes. The owner's own verify checks do not count. */
function iv_runs_used(int $ideaId): int
{
    return (int) (fabo_one('SELECT COUNT(*) n FROM iv_ai_runs WHERE idea_id = ? AND actor NOT LIKE "%(owner check)" AND (state = "done" OR created_at > ?)', 'is', [$ideaId, iv_now(-300)])['n'] ?? 0);
}

/**
 * The code an own-tool prompt carries and the AI must hand back. It changes
 * after every check, so a reply only counts for this idea, for this check:
 * a reply written for another idea, or saved from an earlier check, is
 * refused.
 */
function iv_check_code(int $org, array $idea): string
{
    $n = (int) (fabo_one('SELECT COUNT(*) n FROM iv_ai_runs WHERE idea_id = ? AND state = "done"', 'i', [(int) $idea['id']])['n'] ?? 0);
    $raw = hash_hmac('sha256', 'iv-check|' . $org . '|' . (int) $idea['id'] . '|' . $n, fabo_seal_key(), true);
    return 'IV-' . strtoupper(substr(strtr(base64_encode($raw), ['+' => '', '/' => '', '=' => '', 'O' => '', '0' => '', 'I' => '', 'l' => '', '1' => '']), 0, 6));
}

/**
 * Takes one check off the idea's allowance, under a lock so two clicks cannot
 * both get the last one. Returns the new run's id (state pending). The
 * owner's verify check ($owner) is not limited and not counted.
 */
function iv_run_reserve(int $org, array $idea, array $round, ?array $hod, string $mode, string $actor, bool $owner = false): int
{
    return iv_tx(static function () use ($org, $idea, $round, $hod, $mode, $actor, $owner) {
        fabo_one('SELECT id FROM iv_ideas WHERE id = ? FOR UPDATE', 'i', [(int) $idea['id']]);
        fabo_exec('DELETE FROM iv_ai_runs WHERE idea_id = ? AND state = "pending" AND created_at <= ?', 'is', [(int) $idea['id'], iv_now(-300)]);
        if (!$owner) {
            $limit = iv_ai_limit($round, $hod);
            if (iv_runs_used((int) $idea['id']) >= $limit) {
                throw new InvalidArgumentException($limit === 0 ? 'AI checks are switched off for you. Ask the owner.'
                    : 'This idea has had its ' . $limit . ' AI check' . ($limit === 1 ? '' : 's') . '. The owner can raise the limit.');
            }
        }
        return fabo_exec('INSERT INTO iv_ai_runs (org_id, idea_id, gem_id, mode, actor, created_at) VALUES (?,?,?,?,?,?)', 'iiisss',
                         [$org, (int) $idea['id'], (int) $round['gem_id'] ?: null, $mode, mb_substr($actor . ($owner ? ' (owner check)' : ''), 0, 190), iv_now()]);
    });
}

/**
 * Saves a finished run and puts the idea's score on it: the AVERAGE of all
 * its checks, so a second check cannot simply replace a score the HoD did
 * not like.
 */
function iv_run_finish(int $org, int $runId, array $idea, array $gem, float $total, array $breakdown, string $summary, array $improve, string $tool, string $actor, string $reply = ''): void
{
    fabo_exec('UPDATE iv_ai_runs SET state = "done", tool = ?, score = ?, max_score = ?, breakdown_json = ?, summary = ?, improvements_json = ?, reply_text = ? WHERE id = ?', 'sddssssi',
              [mb_substr($tool, 0, 60), $total, (float) $gem['max'], json_encode($breakdown, JSON_UNESCAPED_UNICODE), mb_substr(trim($summary), 0, 2000),
               $improve ? json_encode($improve, JSON_UNESCAPED_UNICODE) : null, $reply !== '' ? mb_substr($reply, 0, 60000) : null, $runId]);
    $avg = fabo_one('SELECT AVG(score / max_score) p FROM iv_ai_runs WHERE idea_id = ? AND state = "done" AND max_score > 0', 'i', [(int) $idea['id']]);
    $score = round((float) ($avg['p'] ?? 0) * (float) $gem['max'], 2);
    fabo_exec('UPDATE iv_ideas SET ai_score = ?, ai_max = ? WHERE id = ?', 'ddi', [$score, (float) $gem['max'], (int) $idea['id']]);
    iv_log($org, (int) $idea['id'], $actor, 'AI check (' . ($tool !== '' ? $tool : 'portal') . '): ' . iv_score($total, $gem['max']) . '.');
}

function iv_improve_clean($list): array
{
    $out = [];
    foreach (is_array($list) ? $list : [] as $p) {
        $p = trim(preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', (string) (is_array($p) ? ($p['text'] ?? '') : $p)));
        if ($p !== '') {
            $out[] = mb_substr($p, 0, 500);
        }
    }
    return array_slice($out, 0, 6);
}

/** The Gem a round uses, or why there is none. */
function iv_round_gem(int $org, array $round): array
{
    $gem = $round['gem_id'] ? iv_gem($org, (int) $round['gem_id']) : null;
    if (!$gem) {
        throw new InvalidArgumentException('The owner has not assigned a Gem to this round yet.');
    }
    if (!$gem['criteria']) {
        throw new InvalidArgumentException('The assigned Gem has no scoring criteria.');
    }
    return $gem;
}

/** Can the portal itself call the AI here? */
function iv_portal_ai_ready(): bool
{
    return trim(fabo_env_or('GEMINI_API_KEY')) !== '' || is_callable($GLOBALS['iv_gemini_hook'] ?? null);
}

/**
 * Checks an idea inside the portal with Gemini. A failure that is Gemini's
 * (busy, over quota, unreadable reply) gives the check back. Returns words.
 * $owner: the owner's verify check, outside the HoD's limit.
 */
function iv_run_portal(int $org, array $idea, array $round, ?array $hod, string $actor, bool $owner = false): string
{
    $gem = iv_round_gem($org, $round);
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    $runner = $GLOBALS['iv_gemini_hook'] ?? null;          // tests: no call leaves the machine
    if ($key === '' && !is_callable($runner)) {
        throw new InvalidArgumentException('AI inside the portal is not set up on this server.' . ($owner ? '' : ' Use "Your own AI tool" instead.'));
    }
    $runId = iv_run_reserve($org, $idea, $round, $hod, 'portal', $actor, $owner);
    $prompt = iv_prompt($gem, $idea);
    try {
        if (is_callable($runner)) {
            [$ok, $json] = $runner($prompt);
        } else {
            [$ok, $json] = iv_with_inc($org, static fn() => inc_gem_gemini($key, $prompt));
        }
        if (!$ok) {
            throw new InvalidArgumentException('The AI did not answer just now, so this check was not counted. Try again in a few minutes. (' . mb_substr((string) $json, 0, 160) . ')');
        }
        try {
            [$total, $breakdown] = iv_tally($gem, iv_reply_by_n($json));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('The AI\'s reply could not be read as a score, so this check was not counted. Try again.');
        }
        iv_run_finish($org, $runId, $idea, $gem, $total, $breakdown, (string) ($json['summary'] ?? ''), iv_improve_clean($json['improvements'] ?? []), $owner ? 'Gemini (owner check)' : 'Gemini (in portal)', $actor);
        return 'Checked with ' . $gem['name'] . ': ' . iv_score($total, $gem['max']) . '.';
    } catch (Throwable $e) {
        fabo_exec('DELETE FROM iv_ai_runs WHERE id = ? AND state = "pending"', 'i', [$runId]);
        if (!$e instanceof InvalidArgumentException) {
            error_log('FABO iv gemini: ' . $e->getMessage());
            throw new InvalidArgumentException('The AI check failed and was not counted. Try again.');
        }
        throw $e;
    }
}

/**
 * Records a check made in the HoD's own AI tool from the reply pasted whole.
 * Marks cannot be typed in: the score is what the AI wrote. The reply must
 * carry this idea's check code (iv_check_code), and it is kept for the owner.
 * Counts towards the limit. Returns words.
 */
function iv_run_own(int $org, array $idea, array $round, ?array $hod, string $tool, string $reply, string $actor): string
{
    if (isset($round['own_ai']) && !(int) $round['own_ai']) {
        throw new InvalidArgumentException('In this round ideas are checked in the portal only.');
    }
    $gem = iv_round_gem($org, $round);
    $tool = iv_clean($tool, 60) ?: 'Own AI tool';
    $reply = trim($reply);
    if ($reply === '') {
        throw new InvalidArgumentException('Paste the AI\'s whole reply first.');
    }
    $json = iv_reply_json($reply);
    if (!$json || !iv_reply_by_n($json)) {
        throw new InvalidArgumentException('The pasted reply has no marks this page can read. Paste the whole reply, including the part in { } brackets.');
    }
    $code = iv_check_code($org, $idea);
    if (strcasecmp(trim((string) ($json['check'] ?? '')), $code) !== 0) {
        throw new InvalidArgumentException('This reply does not carry this idea\'s check code (' . $code . '). Copy the prompt from this page again, ask the AI, and paste its new reply.');
    }
    try {
        [$total, $breakdown] = iv_tally($gem, iv_reply_by_n($json));
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException('The reply leaves out a criterion: ' . $e->getMessage() . ' Ask the AI to score every criterion, then paste again.');
    }
    $runId = iv_run_reserve($org, $idea, $round, $hod, 'own', $actor);
    iv_run_finish($org, $runId, $idea, $gem, $total, $breakdown, (string) ($json['summary'] ?? ''), iv_improve_clean($json['improvements'] ?? []), $tool, $actor, $reply);
    return 'Recorded the ' . $tool . ' check: ' . iv_score($total, $gem['max']) . '.';
}

/* ====================================================== the Gems page */
/*
 * Startup Discovery's own Gems page edits the organisation's Gems in place:
 * the same rows Incubation Management → Gems shows, so an organisation with
 * both modules keeps one set. An organisation without Incubation gets its
 * tables made on first use (inc_install(), which also seeds Gem 1). The
 * permission is Startup Discovery's (iv_can_edit()), checked by the caller;
 * Incubation's own checks are satisfied for the length of the call only.
 */

/** Runs $fn as the Startup Discovery owner inside $org's Incubation tables. */
function iv_inc_as(int $org, string $actor, callable $fn)
{
    return iv_with_inc($org, static function () use ($actor, $fn) {
        $prev = $GLOBALS['inc_ctx'] ?? null;
        $GLOBALS['inc_ctx'] = ['viewer' => null, 'is_admin' => true, 'grant' => 'edit', 'desk' => false, 'actor' => mb_substr($actor . ' · Startup Discovery', 0, 190)];
        try {
            return $fn();
        } finally {
            if ($prev === null) {
                unset($GLOBALS['inc_ctx']);
            } else {
                $GLOBALS['inc_ctx'] = $prev;
            }
        }
    });
}

/** Makes the Gem tables for an organisation that has none yet (and its Gem 1). */
function iv_gems_prepare(int $org, string $actor): void
{
    if (iv_gems_available($org)) {
        return;
    }
    iv_inc_as($org, $actor, static function () {
        inc_install();
    });
}

/** Every Gem of the organisation, switched-off ones too: id => decoded Gem. */
function iv_gems_all(int $org): array
{
    if (!iv_gems_available($org)) {
        return [];
    }
    return iv_with_inc($org, static fn() => inc_gems(false));
}

/** Per Gem: the rounds using it, its ideas, its checks and their average share of the maximum. */
function iv_gem_usage(int $org): array
{
    $out = [];
    foreach (iv_rounds($org) as $r) {
        if ($r['gem_id']) {
            $out[(int) $r['gem_id']]['rounds'][] = $r;
        }
    }
    foreach (fabo_all('SELECT r.gem_id, COUNT(DISTINCT i.id) AS ideas FROM iv_rounds r JOIN iv_ideas i ON i.round_id = r.id AND i.org_id = r.org_id
                       WHERE r.org_id = ? AND r.gem_id IS NOT NULL GROUP BY r.gem_id', 'i', [$org]) as $x) {
        $out[(int) $x['gem_id']]['ideas'] = (int) $x['ideas'];
    }
    foreach (fabo_all('SELECT gem_id, COUNT(*) AS n, AVG(score / NULLIF(max_score, 0)) AS share FROM iv_ai_runs
                       WHERE org_id = ? AND state = "done" AND gem_id IS NOT NULL GROUP BY gem_id', 'i', [$org]) as $x) {
        $out[(int) $x['gem_id']]['checks'] = (int) $x['n'];
        $out[(int) $x['gem_id']]['share'] = $x['share'] !== null ? (float) $x['share'] : null;
    }
    return $out;
}

/** The questions editor's rows (q[n][uid|label|help|type|required]) in the shape Incubation's saver reads. */
function iv_gem_rows_in(array $post): array
{
    $in = ['q_label' => [], 'q_uid' => [], 'q_help' => [], 'q_type' => [], 'q_req' => []];
    foreach (array_values(is_array($post['q'] ?? null) ? $post['q'] : []) as $i => $q) {
        if (!is_array($q)) {
            continue;
        }
        $in['q_label'][$i] = (string) ($q['label'] ?? '');
        $in['q_uid'][$i] = (string) ($q['uid'] ?? '');
        $in['q_help'][$i] = (string) ($q['help'] ?? '');
        $in['q_type'][$i] = ($q['type'] ?? '') === 'short' ? 'short' : 'long';
        if (!empty($q['required'])) {
            $in['q_req'][$i] = 1;
        }
    }
    return $in;
}

/**
 * Saves a Gem from the Startup Discovery editor: name, instructions, rubric,
 * questions, on/off. What only Incubation uses (its emails, intro, stage) is
 * kept as it is. Returns the Gem's id.
 */
function iv_gem_save(int $org, ?int $id, array $post, string $actor): int
{
    iv_gems_prepare($org, $actor);
    return iv_inc_as($org, $actor, static function () use ($id, $post) {
        $old = $id ? inc_gem($id) : null;
        if ($id && !$old) {
            throw new InvalidArgumentException('That Gem is no longer here.');
        }
        $keep = $old ?? ['intro' => '', 'mail_subject' => '', 'mail_body' => '', 'send_stage_id' => null, 'ack_subject' => '', 'ack_body' => ''];
        $in = ['name' => $post['name'] ?? '', 'instructions' => $post['instructions'] ?? '', 'criteria' => $post['criteria'] ?? '',
               'active' => !empty($post['active']) ? 1 : 0,
               'intro' => $keep['intro'], 'mail_subject' => $keep['mail_subject'], 'mail_body' => $keep['mail_body'],
               'send_stage_id' => $keep['send_stage_id'], 'ack_subject' => $keep['ack_subject'], 'ack_body' => $keep['ack_body']]
            + iv_gem_rows_in($post);
        if (count(array_filter($in['q_label'], static fn($l) => trim((string) $l) !== '')) > 25) {
            throw new InvalidArgumentException('A Gem takes up to 25 questions.');
        }
        return inc_gem_save($id ?: null, $in);
    });
}

/** A copy of a Gem, switched off, to change without touching the rounds that use the original. */
function iv_gem_copy(int $org, int $id, string $actor): int
{
    $g = iv_gem($org, $id);
    if (!$g) {
        throw new InvalidArgumentException('That Gem is no longer here.');
    }
    return iv_inc_as($org, $actor, static function () use ($g) {
        $name = mb_substr('Copy of ' . $g['name'], 0, 120);
        $nid = inc_exec('INSERT INTO inc_gems (name, intro, instructions, criteria_json, fields_json, mail_subject, mail_body, send_stage_id, active, ack_subject, ack_body, created_by)
                         VALUES (?,?,?,?,?,?,?,NULL,0,?,?,?)', 'ssssssssss',
                        [$name, $g['intro'], $g['instructions'], $g['criteria_json'], $g['fields_json'], $g['mail_subject'], $g['mail_body'],
                         $g['ack_subject'], $g['ack_body'], inc_actor()]);
        inc_audit('create', 'gem', $nid, $name . ' (copied from #' . (int) $g['id'] . ')');
        return $nid;
    });
}

/** A form question made from one of the Gem's questions. */
function iv_gem_form_question(array $f): array
{
    return ['uid' => 'g_' . substr(preg_replace('/[^a-z0-9_]/', '', (string) $f['uid']), 0, 36), 'label' => (string) $f['label'],
            'help' => (string) ($f['help'] ?? ''), 'type' => isset(iv_question_types()[$f['type'] ?? '']) ? $f['type'] : 'long',
            'required' => !empty($f['required']), 'gem' => true];
}

/** The department forms of the rounds using a Gem. */
function iv_gem_forms(int $org, int $gemId): array
{
    return fabo_all('SELECT f.id, f.questions_json FROM iv_forms f JOIN iv_rounds r ON r.id = f.round_id AND r.org_id = f.org_id
                     WHERE f.org_id = ? AND r.gem_id = ?', 'ii', [$org, $gemId]);
}

/**
 * Puts a Gem's current questions on the department forms of the rounds using
 * it. The Gem's questions are replaced (reworded, added, removed); a HoD's
 * own questions stay, after them. Ideas already sent keep their answers as
 * they were given. Returns the number of forms changed.
 */
function iv_gem_sync_forms(int $org, int $gemId): int
{
    $gem = iv_gem($org, $gemId);
    if (!$gem) {
        throw new InvalidArgumentException('That Gem is no longer here.');
    }
    $fresh = array_map('iv_gem_form_question', $gem['fields']);
    if (!$fresh) {
        return 0;
    }
    $n = 0;
    foreach (iv_gem_forms($org, $gemId) as $f) {
        $own = array_values(array_filter(iv_form_questions($f), static fn($q) => empty($q['gem'])));
        $json = json_encode(array_slice(array_merge($fresh, $own), 0, 30), JSON_UNESCAPED_UNICODE);
        if ($json !== $f['questions_json']) {
            fabo_exec('UPDATE iv_forms SET questions_json = ? WHERE id = ? AND org_id = ?', 'sii', [$json, (int) $f['id'], $org]);
            $n++;
        }
    }
    return $n;
}

/** [forms of rounds using the Gem, how many of them do not ask its current questions]. */
function iv_gem_forms_behind(int $org, int $gemId, array $gem): array
{
    $sig = static fn(array $q) => [(string) $q['uid'], (string) $q['label'], (string) ($q['help'] ?? ''), (string) ($q['type'] ?? ''), !empty($q['required'])];
    $want = array_map(static fn($f) => $sig(iv_gem_form_question($f)), $gem['fields']);
    $total = 0;
    $behind = 0;
    foreach (iv_gem_forms($org, $gemId) as $f) {
        $total++;
        $have = array_map($sig, array_values(array_filter(iv_form_questions($f), static fn($q) => !empty($q['gem']))));
        if ($have !== $want) {
            $behind++;
        }
    }
    return [$total, $behind];
}

/**
 * A trial run: the Gem scores sample answers typed on the Gems page. Nothing
 * is saved and no idea's limit is touched. Returns [total, breakdown, summary, improvements].
 */
function iv_gem_try(int $org, int $gemId, array $post): array
{
    $gem = iv_gem($org, $gemId);
    if (!$gem || !$gem['criteria']) {
        throw new InvalidArgumentException('That Gem has no scoring criteria yet.');
    }
    $key = trim(fabo_env_or('GEMINI_API_KEY'));
    $runner = $GLOBALS['iv_gemini_hook'] ?? null;          // tests: no call leaves the machine
    if ($key === '' && !is_callable($runner)) {
        throw new InvalidArgumentException('AI inside the portal is not set up on this server, so a Gem cannot be tried here.');
    }
    $answers = [];
    $any = false;
    foreach ($gem['fields'] as $f) {
        $v = mb_substr(trim((string) ($post['try'][$f['uid']] ?? '')), 0, 5000);
        $any = $any || $v !== '';
        $answers[] = ['label' => $f['label'], 'help' => (string) ($f['help'] ?? ''), 'value' => $v];
    }
    if (!$any) {
        throw new InvalidArgumentException('Type a sample answer to at least one question.');
    }
    $idea = ['title' => mb_substr(trim((string) ($post['try_title'] ?? '')), 0, 190) ?: 'Sample idea', 'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE)];
    $prompt = iv_prompt($gem, $idea);
    [$ok, $json] = is_callable($runner) ? $runner($prompt) : iv_with_inc($org, static fn() => inc_gem_gemini($key, $prompt));
    if (!$ok) {
        throw new InvalidArgumentException('The AI did not answer just now. Try again in a few minutes. (' . mb_substr((string) $json, 0, 160) . ')');
    }
    try {
        [$total, $breakdown] = iv_tally($gem, iv_reply_by_n($json));
    } catch (InvalidArgumentException $e) {
        throw new InvalidArgumentException('The AI\'s reply could not be read as a score. Try again.');
    }
    return [$total, $breakdown, mb_substr(trim((string) ($json['summary'] ?? '')), 0, 2000), iv_improve_clean($json['improvements'] ?? [])];
}
