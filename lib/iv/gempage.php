<?php
/**
 * Startup Discovery — Gems: the owner's page for the Gems that validate the
 * ideas. A Gem is the AI's set-up: its role (instructions), a weighted
 * rubric, and the questions every department's form asks. They are the
 * organisation's Incubation Gems, edited in place (lib/iv/gem.php), so an
 * organisation running both modules keeps one set.
 *
 *   ?p=gems            the first Gem (or the empty state)
 *   ?p=gems&id=N       one Gem: edit, where it is used, its forms, a trial run
 *   ?p=gems&id=new     a new Gem
 *
 * Actions (iv_post): gem-save, gem-copy, gem-sync, gem-try, gem-start.
 */

/** A blank Gem for the New Gem form, set up like the built-in Gem 1 so it is never empty. */
function iv_gem_blank(): array
{
    require_once __DIR__ . '/../inc/gems.php';
    $t = inc_gem_template();
    $fields = [];
    try {
        $fields = isset($t['fields']) ? inc_gem_parse_fields((string) $t['fields']) : [];
    } catch (Throwable $e) {
        $fields = [];
    }
    return ['id' => 0, 'name' => '', 'instructions' => (string) ($t['instructions'] ?? ''), 'criteria_text' => (string) ($t['criteria'] ?? ''),
            'fields' => $fields, 'active' => 1];
}

function iv_gem_q_row(string $n, array $q): string
{
    $h = '<li class="iv-q gem"><input type="hidden" name="q[' . e($n) . '][uid]" value="' . e((string) ($q['uid'] ?? '')) . '"><div class="iv-fields">'
       . '<label class="field wide"><span>Question</span><input name="q[' . e($n) . '][label]" maxlength="160" value="' . e((string) ($q['label'] ?? '')) . '" placeholder="e.g. What problem are you solving?"></label>'
       . '<label class="field wide"><span>Help for the student (also given to the AI)</span><textarea name="q[' . e($n) . '][help]" rows="2" maxlength="1000">' . e((string) ($q['help'] ?? '')) . '</textarea></label>'
       . '<label class="field"><span>Answer</span><select name="q[' . e($n) . '][type]">'
       . '<option value="long"' . (($q['type'] ?? 'long') !== 'short' ? ' selected' : '') . '>Paragraph</option>'
       . '<option value="short"' . (($q['type'] ?? '') === 'short' ? ' selected' : '') . '>Short answer</option></select></label>'
       . '<label class="iv-check"><input type="checkbox" name="q[' . e($n) . '][required]" value="1"' . (!empty($q['required']) ? ' checked' : '') . '> Required</label></div>'
       . '<button type="button" class="btn btn-sm iv-qdel" data-delq>Remove</button></li>';
    return $h;
}

function iv_page_gems(int $org): void
{
    $gems = iv_gems_all($org);
    $usage = iv_gem_usage($org);
    $sel = (string) ($_GET['id'] ?? '');
    $new = $sel === 'new' && iv_can_edit();
    $gid = $new ? 0 : ((int) $sel ?: (int) (array_key_first($gems) ?? 0));
    $gem = $gems[$gid] ?? null;
    $draft = $_SESSION['iv_gem_draft'] ?? null;
    unset($_SESSION['iv_gem_draft']);
    if ($draft && (int) ($draft['_id'] ?? -1) !== $gid) {
        $draft = null;
    }
    $try = $_SESSION['iv_gem_tried'] ?? null;
    unset($_SESSION['iv_gem_tried']);
    if ($try && (int) $try['gem'] !== $gid) {
        $try = null;
    }

    if (!$gems && !$new) {
        echo '<section class="iv-card iv-empty-card"><h2>No Gems yet</h2><p class="muted">A Gem is the AI\'s set-up for scoring ideas: its role, a weighted rubric, and the questions students answer. '
           . 'Every round uses one.</p>';
        if (iv_can_edit()) {
            echo '<div class="iv-actions"><form method="post">' . fabo_csrf_field() . '<input type="hidden" name="a" value="gem-start"><button class="btn btn-primary">Start with Gem 1 – Idea Evaluation</button></form>'
               . '<a class="btn" href="' . e(iv_url('gems', ['id' => 'new'])) . '">Make my own Gem</a></div>'
               . '<p class="muted sm">Gem 1 is the ready-made rubric for diploma and student ideas: 8 criteria out of 100 and 4 questions. You can change all of it.</p>';
        }
        echo '</section>';
        return;
    }

    // The Gems, side by side.
    echo '<nav class="iv-gemtabs" aria-label="Gems">';
    foreach ($gems as $id => $g) {
        $u = $usage[$id] ?? [];
        echo '<a href="' . e(iv_url('gems', ['id' => $id])) . '" class="iv-gemtab' . ($id === $gid && !$new ? ' on' : '') . ((int) $g['active'] !== 1 ? ' off' : '') . '"'
           . ($id === $gid && !$new ? ' aria-current="page"' : '') . '><strong>' . e($g['name']) . '</strong>'
           . '<span>' . count($g['criteria']) . ' criteria · ' . count($g['fields']) . ' questions · ' . e(iv_score($g['max'], $g['max'])) . '</span>'
           . '<span>' . ((int) $g['active'] !== 1 ? '<em>Off</em> · ' : '') . (count($u['rounds'] ?? []) ? count($u['rounds']) . ' round' . (count($u['rounds']) === 1 ? '' : 's') : 'No round yet') . '</span></a>';
    }
    if (iv_can_edit()) {
        echo '<a href="' . e(iv_url('gems', ['id' => 'new'])) . '" class="iv-gemtab add' . ($new ? ' on' : '') . '"><strong>+ New Gem</strong><span>Your own rubric and questions</span></a>';
    }
    echo '</nav>';

    if (!$gem && !$new) {
        echo '<section class="iv-card"><p class="muted">That Gem is not here. Pick one above.</p></section>';
        return;
    }

    echo '<div class="iv-grid2 wide-left"><div>';
    if (iv_can_edit()) {
        iv_gem_editor($org, $new ? null : $gem, $draft, $usage[$gid] ?? []);
    } else {
        iv_gem_view($gem);
    }
    echo '</div><div>';
    if ($gem) {
        iv_gem_side($org, $gem, $usage[$gid] ?? [], $try);
    } else {
        echo '<section class="iv-card"><h2>How a Gem works</h2><ol class="iv-steps">'
           . '<li><strong>Instructions</strong> tell the AI who it is and how strict to be.</li>'
           . '<li><strong>Criteria</strong> are what it marks, each up to its weight. The weights add up to the maximum score.</li>'
           . '<li><strong>Questions</strong> go on every department\'s form in rounds using the Gem. The AI reads the answers.</li>'
           . '<li>Pick the Gem on <a href="' . e(iv_url('rounds')) . '">Rounds &amp; timing</a>.</li></ol></section>';
    }
    echo '</div></div>';
}

/** The Gem's set-up as a form. $draft: what was typed before a save that failed. */
function iv_gem_editor(int $org, ?array $gem, ?array $draft, array $u): void
{
    $b = $gem ? $gem + ['criteria_text' => inc_gem_criteria_text($gem)] : iv_gem_blank();
    $name = (string) ($draft['name'] ?? $b['name']);
    $instr = (string) ($draft['instructions'] ?? $b['instructions']);
    $crit = (string) ($draft['criteria'] ?? $b['criteria_text']);
    $qs = $b['fields'];
    if ($draft && is_array($draft['q'] ?? null)) {
        $qs = array_values(array_filter($draft['q'], 'is_array'));
    }
    $active = $draft ? !empty($draft['active']) : (int) $b['active'] === 1;
    $forms = $gem ? count(iv_gem_forms($org, (int) $gem['id'])) : 0;

    echo '<form method="post" class="iv-form iv-card" id="gemForm">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="gem-save"><input type="hidden" name="id" value="' . (int) ($gem['id'] ?? 0) . '">'
       . '<div class="iv-card-h"><h2>' . ($gem ? 'Set-up' : 'New Gem') . '</h2>'
       . ($gem ? '<span class="iv-pill ' . ((int) $gem['active'] === 1 ? 's-validated">On' : 's-none">Off') . '</span>' : '') . '</div>'
       . '<label class="field wide"><span>Name</span><input name="name" required maxlength="120" value="' . e($name) . '" placeholder="Gem 2 – Validation"></label>'
       . '<label class="field wide"><span>Instructions for the AI (its role)</span><textarea name="instructions" rows="7" maxlength="8000">' . e($instr) . '</textarea>'
       . '<small>Who it is, whose ideas it reads, how strict to be. A Gem\'s instructions from gemini.google.com can be pasted here.</small></label>'
       . '<label class="field wide"><span>Scoring criteria — one per line: Name | weight | what it looks for</span>'
       . '<textarea name="criteria" rows="9" required data-crit>' . e($crit) . '</textarea>'
       . '<small>The weights add up to the maximum score: <strong data-crit-total>' . e(iv_gem_crit_total($crit)) . '</strong>. The AI marks each criterion up to its weight; the total is added up here, so no reply can go over.</small></label>'
       . '<h3>Questions students answer</h3><p class="muted sm">They go on every department\'s form in rounds using this Gem, marked <span class="iv-pill g-team">Set by the owner</span>. Heads cannot change or remove them; they can only add their own questions after them.</p>'
       . '<ol class="iv-qs" data-qs>';
    foreach (array_values($qs) as $n => $q) {
        echo iv_gem_q_row((string) $n, $q);
    }
    if (!$qs) {
        echo iv_gem_q_row('0', ['type' => 'long', 'required' => true]);
    }
    echo '</ol><template id="qTpl">' . iv_gem_q_row('__N__', ['type' => 'long', 'required' => true]) . '</template>'
       . '<button type="button" class="btn btn-sm" data-addq>+ Add a question</button>'
       . '<label class="iv-check"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . '> On — rounds can use it (switching off keeps it working in rounds already using it)</label>';
    if ($forms) {
        echo '<label class="iv-check"><input type="checkbox" name="sync" value="1" checked> Also put the new questions on the ' . $forms . ' department form' . ($forms === 1 ? '' : 's') . ' of rounds using this Gem '
           . '<span class="muted sm">(ideas already sent keep their answers)</span></label>';
    }
    if ($gem && !empty($u['checks'])) {
        echo '<p class="muted sm">Changing the rubric scores new checks only: the ' . fabo_thousands((int) $u['checks']) . ' check' . ((int) $u['checks'] === 1 ? '' : 's') . ' already made keep their marks.</p>';
    }
    echo '<p class="muted sm">' . ($gem ? 'If Incubation Management is on, this is its Gem too: a change here shows there. Its emails and stage are set there.'
                                       : 'It starts from Gem 1\'s rubric and questions: change any of it.') . '</p>';
    echo '<div class="iv-actions"><button class="btn btn-primary">' . ($gem ? 'Save Gem' : 'Create Gem') . '</button>'
       . ($gem ? '<a class="btn" href="' . e(iv_url('gems', ['id' => (int) $gem['id']])) . '">Undo changes</a>' : '') . '</div></form>';
}

/** Read-only: the rubric and questions. */
function iv_gem_view(array $gem): void
{
    echo '<section class="iv-card"><h2>' . e($gem['name']) . '</h2>';
    if (trim((string) $gem['instructions']) !== '') {
        echo '<details class="iv-run"><summary>Instructions for the AI</summary><p class="iv-note">' . nl2br(e($gem['instructions'])) . '</p></details>';
    }
    echo '<h3>Rubric</h3><table class="iv-table iv-rubric"><tbody>';
    foreach ($gem['criteria'] as $c) {
        echo '<tr><td><strong>' . e($c['name']) . '</strong>' . ($c['asks'] !== '' ? '<div class="muted sm">' . e($c['asks']) . '</div>' : '') . '</td><td class="n">' . e(iv_num((float) $c['weight'])) . '</td></tr>';
    }
    echo '<tr><td><strong>Total</strong></td><td class="n"><strong>' . e(iv_num((float) $gem['max'])) . '</strong></td></tr></tbody></table><h3>Questions</h3><ol>';
    foreach ($gem['fields'] as $f) {
        echo '<li>' . e($f['label']) . (!empty($f['required']) ? '' : ' <span class="muted">(optional)</span>') . (trim((string) ($f['help'] ?? '')) !== '' ? '<div class="muted sm">' . e($f['help']) . '</div>' : '') . '</li>';
    }
    echo '</ol></section>';
}

/** Where the Gem is used, its forms, a copy, and a trial run. */
function iv_gem_side(int $org, array $gem, array $u, ?array $try): void
{
    $gid = (int) $gem['id'];
    $rounds = $u['rounds'] ?? [];
    echo '<section class="iv-card"><h2>Where it is used</h2><div class="iv-kpis iv-kpis-sm">'
       . iv_kpi('Rounds', count($rounds)) . iv_kpi('Ideas', (int) ($u['ideas'] ?? 0)) . iv_kpi('AI checks', (int) ($u['checks'] ?? 0))
       . iv_kpi('Average', isset($u['share']) && $u['share'] !== null ? round($u['share'] * 100) . '%' : '—') . '</div>';
    if ($rounds) {
        echo '<ul class="iv-list">';
        foreach ($rounds as $r) {
            echo '<li><a href="' . e(iv_url('rounds', ['round' => (int) $r['id']])) . '">' . e($r['name']) . '</a> <span class="iv-pill r-' . e($r['status']) . '">' . e(ucfirst($r['status'])) . '</span></li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="muted sm">No round uses it yet. Pick it on <a href="' . e(iv_url('rounds')) . '">Rounds &amp; timing</a>.</p>';
    }
    [$total, $behind] = iv_gem_forms_behind($org, $gid, $gem);
    if ($total) {
        echo '<p class="sm">' . $total . ' department form' . ($total === 1 ? '' : 's') . ' ask its questions.'
           . ($behind ? ' <strong class="iv-warn">' . $behind . ' still ask an older version.</strong>' : ' All are up to date.') . '</p>';
        if ($behind && iv_can_edit()) {
            echo '<form method="post" data-confirm="Put this Gem\'s current questions on ' . $behind . ' form(s)? Heads\' own questions stay; ideas already sent keep their answers.">' . fabo_csrf_field()
               . '<input type="hidden" name="a" value="gem-sync"><input type="hidden" name="id" value="' . $gid . '"><button class="btn btn-sm">Update ' . $behind . ' form' . ($behind === 1 ? '' : 's') . '</button></form>';
        }
    }
    if (iv_can_edit()) {
        echo '<form method="post" class="iv-actions">' . fabo_csrf_field() . '<input type="hidden" name="a" value="gem-copy"><input type="hidden" name="id" value="' . $gid . '">'
           . '<button class="btn btn-sm">Make a copy</button><span class="muted sm">A copy starts switched off, to change without touching the rounds using this one.</span></form>';
    }
    echo '</section>';

    if (!iv_can_edit()) {
        return;
    }
    echo '<section class="iv-card" id="try"><h2>Try it on a sample idea</h2>';
    if ($try && $try['result']) {
        $r = $try['result'];
        $pct = $gem['max'] > 0 ? $r[0] / $gem['max'] * 100 : 0;
        echo '<div class="iv-run"><p><span class="iv-score" style="--p:' . round($pct) . '">' . e(iv_score($r[0], $gem['max'])) . '</span> <span class="muted sm">Trial only: nothing was saved.</span></p>';
        if ($r[2] !== '') {
            echo '<p class="iv-note">' . nl2br(e($r[2])) . '</p>';
        }
        echo '<table class="iv-table iv-rubric"><tbody>';
        foreach ($r[1] as $c) {
            $w = $c['max'] > 0 ? $c['score'] / $c['max'] * 100 : 0;
            echo '<tr><td><strong>' . e($c['name']) . '</strong>' . ($c['reason'] !== '' ? '<div class="muted sm">' . e($c['reason']) . '</div>' : '') . '</td>'
               . '<td class="n nowrap">' . e(iv_score($c['score'], $c['max'])) . '<div class="iv-meter"><i style="width:' . round($w) . '%"></i></div></td></tr>';
        }
        echo '</tbody></table>';
        if ($r[3]) {
            echo '<h3>What would make it better</h3><ul>';
            foreach ($r[3] as $p) {
                echo '<li>' . e($p) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    if (!iv_portal_ai_ready()) {
        echo '<p class="muted sm">AI is not set up on this server, so a Gem cannot be tried here.</p></section>';
        return;
    }
    $was = $try['post'] ?? [];
    echo '<p class="muted sm">Type answers as a student would, and see the marks and reasons this Gem gives. Not saved, and no idea\'s AI limit is used.</p>'
       . '<form method="post" class="iv-form" data-busy="Asking the AI… this can take half a minute.">' . fabo_csrf_field()
       . '<input type="hidden" name="a" value="gem-try"><input type="hidden" name="id" value="' . $gid . '">'
       . '<label class="field wide"><span>Idea title</span><input name="try_title" maxlength="190" value="' . e((string) ($was['try_title'] ?? '')) . '"></label>';
    foreach ($gem['fields'] as $f) {
        echo '<label class="field wide"><span>' . e($f['label']) . '</span><textarea name="try[' . e($f['uid']) . ']" rows="' . ($f['type'] === 'short' ? 1 : 3) . '" maxlength="5000">'
           . e((string) ($was['try'][$f['uid']] ?? '')) . '</textarea></label>';
    }
    echo '<div class="iv-actions"><button class="btn">Score this sample</button></div></form></section>';
}

/** "Name | 10 | ..." lines → their weights added up, as text. */
function iv_gem_crit_total(string $text): string
{
    $t = 0.0;
    foreach (preg_split('/\R/', $text) as $line) {
        $p = explode('|', $line);
        $t += isset($p[1]) ? max(0.0, (float) trim($p[1])) : 0.0;
    }
    return iv_num($t);
}

function iv_num(float $n): string
{
    return rtrim(rtrim(number_format($n, 1), '0'), '.');
}
