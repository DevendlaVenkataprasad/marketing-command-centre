<?php
/**
 * Incubation Management — the form builder for one form: its settings, its draft
 * version's fields (add, edit, reorder, activate, conditions, validation),
 * a live preview, publishing, and the version history.
 */

$id = (int) ($_GET['id'] ?? 0);
$form = inc_form($id);
if (!$form) {
    fabo_deny('That form no longer exists.', 404);
}
$view = (string) ($_GET['view'] ?? 'build');
$self = inc_url('form', ['id' => $id] + ($view !== 'build' ? ['view' => $view] : []));
$flash = null;

/* The builder is always open. Editing a field of the published version starts
   the draft first (a copy, same uids) and changes that field's copy, so the
   live form never changes until the draft is published. */
$toDraft = static function (int $fieldId) use ($id): int {
    $f = inc_one('SELECT f.id, f.uid, v.status FROM inc_form_fields f JOIN inc_form_versions v ON v.id = f.version_id WHERE f.id = ? AND v.form_id = ?', 'ii', [$fieldId, $id]);
    if (!$f) {
        throw new InvalidArgumentException('That field no longer exists.');
    }
    if ($f['status'] === 'draft') {
        return (int) $f['id'];
    }
    $d = inc_form_draft($id);
    $copy = inc_one('SELECT id FROM inc_form_fields WHERE version_id = ? AND uid = ?', 'is', [(int) $d['id'], $f['uid']]);
    if (!$copy) {
        throw new InvalidArgumentException('That field is not in the draft any more.');
    }
    return (int) $copy['id'];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    if (in_array($a, ['move', 'delete'], true) || ($a === 'field' && inc_int_or_null($_POST['field_id'] ?? null))) {
        try {
            $_POST['field_id'] = (string) $toDraft((int) ($_POST['field_id'] ?? 0));
        } catch (Throwable $e) {
            // inc_action below reports it as it would any other refusal.
        }
    }
    $flash = inc_action(static function () use ($a, $id) {
        switch ($a) {
            case 'settings':
                inc_form_settings_save($id, $_POST);
                return 'Form settings saved.';
            case 'draft':
                inc_form_draft($id);
                return 'A draft version was started from the published one.';
            case 'field':
                $d = inc_form_draft($id);
                inc_form_field_save((int) $d['id'], inc_int_or_null($_POST['field_id'] ?? null), $_POST);
                return 'Field saved.';
            case 'move':
                inc_form_field_move((int) ($_POST['field_id'] ?? 0), (int) ($_POST['dir'] ?? 0));
                return 'Order changed.';
            case 'delete':
                inc_form_field_delete((int) ($_POST['field_id'] ?? 0));
                return 'Field removed from the draft. Earlier versions and their answers keep it.';
            case 'publish':
                inc_form_publish($id);
                return 'Published. New applications use this version; earlier applications keep theirs.';
        }
        throw new InvalidArgumentException('Unknown action.');
    }, $self);
    $form = inc_form($id);
}

$pub = inc_form_published($id);
$draft = inc_form_draft_version($id);
$versions = inc_all('SELECT v.*, (SELECT COUNT(*) FROM inc_applications a WHERE a.form_version_id = v.id) AS apps FROM inc_form_versions v WHERE v.form_id = ? ORDER BY version_no DESC', 'i', [$id]);
$working = $draft ?: $pub;
$fields = $working ? inc_form_fields((int) $working['id']) : [];
// A client organisation's form names it (o=), so the page opens their forms, not ours.
$publicUrl = rtrim(fabo_portal_url(), '/') . '/' . (inc_kind() === 'eo' ? 'event-apply.php' : 'incubation-apply.php') . '?f=' . $form['public_token']
           . (inc_org() > 0 ? '&o=' . inc_org() : '');

inc_layout_start($form['name'], e(inc_purpose_text($form['purpose'], $form['event_name'] ?? null)) . ' · ' . ($pub ? 'published v' . (int) $pub['version_no'] : 'not published') . ($draft ? ' · draft v' . (int) $draft['version_no'] : ''),
    ['Settings' => inc_url('settings', ['tab' => 'forms']), $form['name'] => null],
    '<a class="btn" href="' . e(inc_url('form', ['id' => $id, 'view' => $view === 'preview' ? 'build' : 'preview'])) . '">' . ($view === 'preview' ? 'Back to builder' : 'Preview') . '</a>', $flash);

if ($view === 'preview') {
    echo '<div class="inc-note">Preview of ' . ($draft ? 'the draft (v' . (int) $draft['version_no'] . ')' : 'the published version') . '. Conditions work as they will for applicants; nothing is saved.</div>'
       . '<section class="card inc-preview"><h2>' . e($form['name']) . '</h2>' . ($form['description'] ? '<p class="muted">' . nl2br(e($form['description'])) . '</p>' : '')
       . '<form onsubmit="return false" class="inc-applyform"' . ((int) ($form['stepwise'] ?? 0) === 1 ? ' data-steps="1"' : '') . '>' . inc_render_fields($fields)
       . '<div class="inc-formfoot"><button class="btn btn-primary" disabled>Submit (preview)</button></div></form></section>';
    inc_layout_end();
    return;
}

$canBuild = inc_can('configure');
$live = (int) $form['public_enabled'] === 1 && $pub && (int) $form['archived'] !== 1;
// A purpose's own starting questions (Settings -> Form purposes): a template, not a form anybody applies on.
$tplOf = inc_purpose_of_template($id);

if ($tplOf !== '') {
    echo '<section class="fb-status"><div class="fb-status-main"><span class="fb-pill">Starting questions</span><span class="fb-status-text">For every new <strong>'
       . e(inc_purpose_defs()[$tplOf]['label']) . '</strong> form. Nobody applies on this page: it is the purpose\'s template.</span></div></section>';
} else {
/* ---- Status bar: where the form stands, and its link, at a glance ---- */
$state = (int) $form['archived'] === 1 ? ['Archived', 'muted', 'Closed to new applications.']
       : ($draft ? ['Draft v' . (int) $draft['version_no'], 'warn', $pub ? 'Applicants still see v' . (int) $pub['version_no'] . ' until you publish.' : 'Not published yet.']
       : ($pub ? ['Live · v' . (int) $pub['version_no'], 'good', $live ? 'Taking applications through its public link.' : 'Published, but the public link is off.']
       : ['Not published', 'muted', 'Publish it to take applications.']));
echo '<section class="fb-status fb-' . $state[1] . '"><div class="fb-status-main"><span class="fb-pill">' . e($state[0]) . '</span>'
   . '<span class="fb-status-text">' . e($state[2]) . '</span></div>';
if ($live) {
    echo '<div class="fb-link"><input type="text" readonly value="' . e($publicUrl) . '" aria-label="Public link" onclick="this.select()">'
       . '<button type="button" class="btn btn-sm" onclick="var i=this.previousElementSibling;i.select();(navigator.clipboard?navigator.clipboard.writeText(i.value):Promise.resolve(document.execCommand(\'copy\'))).then(function(){});this.textContent=\'Copied\';var b=this;setTimeout(function(){b.textContent=\'Copy link\'},1600)">Copy link</button>'
       . '<a class="btn btn-sm" href="' . e($publicUrl) . '" target="_blank" rel="noopener">Open ↗</a></div>';
}
echo '</section>';

if ($draft) {
    $problems = inc_form_problems((int) $draft['id']);
    if ($problems) {
        echo '<div class="inc-warn"><strong>Fix these before publishing draft v' . (int) $draft['version_no'] . ':</strong><ul>';
        foreach ($problems as $p) {
            echo '<li>' . e($p) . '</li>';
        }
        echo '</ul></div>';
    } elseif ($canBuild) {
        echo '<div class="fb-publish"><div><strong>Draft v' . (int) $draft['version_no'] . ' is ready.</strong> '
           . '<span class="muted">New applications use it once published; ones already received keep their version.</span></div>'
           . inc_button('Publish v' . (int) $draft['version_no'], ['action' => 'publish'], 'btn btn-primary', 'Publish this version? New applications will use it.') . '</div>';
    }
}
}

echo '<div class="fb-layout"><div>';

/* ---- Fields ---- */
$types = inc_field_types();
$icons = ['text' => 'Aa', 'textarea' => '¶', 'email' => '@', 'phone' => '☏', 'number' => '#', 'date' => '31', 'select' => '▾',
          'multiselect' => '☰', 'radio' => '◉', 'checkbox' => '☑', 'yesno' => 'Y/N', 'url' => '://', 'file' => '⇪', 'section' => '§'];
$uidLabels = [];
foreach ($fields as $f) {
    if (!in_array($f['type'], ['section', 'file'], true)) {
        $uidLabels[$f['uid']] = $f['label'];
    }
}
$nQ = count(array_filter($fields, static fn($f) => $f['type'] !== 'section'));
echo '<section class="card fb-fields"><div class="fb-head"><div><h2>Questions</h2><p class="muted small">' . $nQ . ' question' . ($nQ === 1 ? '' : 's')
   . ($canBuild ? ' · click one to edit it' . (!$draft && $pub ? '. Changes go into a draft — applicants keep seeing v' . (int) $pub['version_no'] . ' until you publish.' : '.') : '') . '</p></div></div>';
if (!$fields) {
    echo '<div class="fb-empty">No questions yet. Add the first one below.</div>';
}
$ops = ['eq' => 'is', 'neq' => 'is not', 'in' => 'is one of', 'filled' => 'is answered', 'empty' => 'is blank'];
foreach ($fields as $i => $f) {
    $isSec = $f['type'] === 'section';
    $chips = '';
    if ((int) $f['required'] === 1 && !$isSec) {
        $chips .= '<span class="fb-chip req">Required</span>';
    }
    if ($f['maps_to']) {
        $chips .= '<span class="fb-chip info" title="The answer is copied onto the application">Saves to ' . e(inc_field_targets()[$f['maps_to']] ?? $f['maps_to']) . '</span>';
    }
    if (!empty($f['rules']['card'])) {
        $chips .= '<span class="fb-chip info" title="The answer shows on the board card">On card: ' . e((string) $f['rules']['card']) . '</span>';
    }
    if ($f['cond']) {
        $chips .= '<span class="fb-chip warn">Shown if ' . e($uidLabels[$f['cond']['field']] ?? $f['cond']['field']) . ' ' . e($ops[$f['cond']['op']] ?? '') . ' ' . e((string) ($f['cond']['value'] ?? '')) . '</span>';
    }
    if ((int) $f['active'] !== 1) {
        $chips .= '<span class="fb-chip muted">Hidden</span>';
    }
    echo '<details class="fb-q' . ($isSec ? ' sec' : '') . ((int) $f['active'] !== 1 ? ' off' : '') . '"><summary>'
       . '<span class="fb-ico" aria-hidden="true">' . e($icons[$f['type']] ?? '•') . '</span>'
       . '<span class="fb-q-main"><span class="fb-q-label">' . e($f['label']) . ((int) $f['required'] === 1 && !$isSec ? '<span class="fb-star" aria-label="required">*</span>' : '') . '</span>'
       . '<span class="fb-q-meta">' . e($types[$f['type']] ?? $f['type']) . ($chips !== '' ? ' ' . $chips : '') . '</span></span>'
       . ($canBuild ? '<span class="fb-q-edit">Edit</span>' : '') . '</summary>';
    if ($canBuild) {
        echo '<div class="fb-q-body"><div class="fb-q-tools">'
           . ($i > 0 ? inc_button('↑ Move up', ['action' => 'move', 'field_id' => $f['id'], 'dir' => -1]) : '')
           . ($i < count($fields) - 1 ? inc_button('↓ Move down', ['action' => 'move', 'field_id' => $f['id'], 'dir' => 1]) : '')
           . '<span class="fb-uid" title="Stable ID: answers stay attached to it across versions">ID: ' . e($f['uid']) . '</span>'
           . inc_button('Remove', ['action' => 'delete', 'field_id' => $f['id']], 'btn btn-sm btn-reject', 'Remove this question? It goes from the draft; the live version and answers already received keep it.') . '</div>'
           . inc_field_editor($f, $uidLabels) . '</div>';
    }
    echo '</details>';
}
if ($canBuild) {
    echo '<details class="fb-add"' . ($flash && $flash[0] === 'err' && empty($_POST['field_id']) && ($_POST['action'] ?? '') === 'field' ? ' open' : '') . '>'
       . '<summary><span class="fb-add-plus">+</span> Add a question</summary><div class="fb-q-body">' . inc_field_editor(null, $uidLabels) . '</div></details>';
}
echo '</section></div><aside class="fb-side">';

if ($tplOf !== '') {
    echo '<section class="card fb-settings"><h2>About these questions</h2><p>The starting questions of the purpose <strong>' . e(inc_purpose_defs()[$tplOf]['label']) . '</strong>.</p>'
       . '<p class="muted small">Every new form created with this purpose starts with a copy of them, which you can then change for that form. Forms already made are not affected. '
       . 'Changes here are saved as you make them; there is nothing to publish.</p>'
       . '<p><a class="btn" href="' . e(inc_url('settings', ['tab' => 'forms'])) . '#purposes">← Back to Form purposes</a></p></section>';
} else {
/* ---- Settings, in three groups, one Save ---- */
$sw = static fn(string $name, bool $on, string $title, string $sub) => '<label class="fb-switch"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '>'
    . '<span class="fb-track" aria-hidden="true"></span><span><strong>' . e($title) . '</strong><small>' . e($sub) . '</small></span></label>';
echo '<section class="card fb-settings">' . inc_form_open() . '<input type="hidden" name="action" value="settings">'
   . '<h2>Settings</h2>'
   . '<div class="fb-group"><h3>Basics</h3>'
   . inc_field('Form name', '<input type="text" name="name" value="' . e($form['name']) . '" maxlength="160" required>')
   . '<div class="fb-fixed"><span class="muted small">Purpose</span> <span class="fb-chip muted">' . e(inc_purposes()[$form['purpose']] ?? '') . '</span><span class="muted small">set when the form was created</span></div>'
   . (inc_purpose_event_label($form['purpose']) !== ''
      ? inc_field(inc_purpose_event_label($form['purpose']), '<input type="text" name="event_name" value="' . e((string) ($form['event_name'] ?? '')) . '" maxlength="190" required>',
                  'New applications are filed under this name.', true)
      : '')
   . inc_field('Description for applicants', inc_textarea('description', (string) $form['description'], 3, 'placeholder="A line or two shown above the questions"'))
   . '</div>'
   . '<div class="fb-group"><h3>Where applications go</h3>'
   . inc_field('Assign to team', inc_select('dept_team_id', inc_team_picker(), $form['dept_team_id'] ?? null, 'None'),
               'Applications always land in the main pipeline. With a team chosen, each one is also put on that team’s Department desk and tagged with its name on the board. Choosing a team here also sends it the applications already received.')
   . inc_field('Applicants are', inc_select('default_type_id', inc_masters('applicant_type'), $form['default_type_id'] ?? null, 'Not set'), 'Recorded as the applicant type (Student, Startup, Mentor…) on each application from this form.')
   . inc_field('Default source', inc_select('default_source_id', inc_masters('source'), $form['default_source_id'], 'None'), 'Recorded on each application from this form.')
   . (inc_gems(true) || !empty($form['gem_id'])
      ? inc_field('Gem form in the acknowledgement', inc_select('gem_id', array_column(inc_gems(), 'name', 'id'), $form['gem_id'] ?? null, 'None — plain acknowledgement'),
                  'Applicants get its personal link by email; the application moves to the Gem\'s stage once they answer it.')
      : '')
   . '</div>'
   . '<div class="fb-group"><h3>Availability</h3>'
   . $sw('public_enabled', (int) $form['public_enabled'] === 1, 'Public link', 'Anyone with the link can apply.')
   . $sw('block_duplicates', (int) $form['block_duplicates'] === 1, 'One application per email', 'Refuse a second live application from the same email.')
   . $sw('archived', (int) $form['archived'] === 1, 'Archived', 'Closed to new applications.')
   . '</div>'
   . '<div class="fb-group"><h3>Layout</h3>'
   . $sw('stepwise', (int) ($form['stepwise'] ?? 0) === 1, 'Step by step', 'Each section heading starts a new step, with Back and Next — easier for long forms.')
   . '</div>'
   . '<div class="fb-save"><button class="btn btn-primary">Save settings</button></div></form></section>';

/* ---- Versions ---- */
echo '<section class="card fb-versions"><h2>Versions</h2><ol>';
foreach ($versions as $v) {
    $tone = ['published' => 'good', 'draft' => 'warn'][$v['status']] ?? 'muted';
    echo '<li><span class="fb-pill fb-' . $tone . '">v' . (int) $v['version_no'] . ' · ' . e(ucfirst($v['status'])) . '</span>'
       . '<span class="small muted">' . e($v['published_at'] ? inc_fmt_date($v['published_at']) . ' · ' . $v['published_by'] : 'not published') . '</span>'
       . '<span class="small">' . (int) $v['apps'] . ' application' . ((int) $v['apps'] === 1 ? '' : 's') . '</span></li>';
}
echo '</ol><p class="muted small">Each application keeps the version it was submitted with.</p></section>';
}
echo '</aside></div>';
inc_layout_end();

/** The editor for one field (or a new one when $f is null). */
function inc_field_editor(?array $f, array $uidLabels): string
{
    $r = $f['rules'] ?? [];
    $c = $f['cond'] ?? [];
    $type = $f['type'] ?? 'text';
    $deps = $uidLabels;
    if ($f) {
        unset($deps[$f['uid']]);
    }
    return inc_form_open('class="inc-fieldedit"') . '<input type="hidden" name="action" value="field">' . ($f ? '<input type="hidden" name="field_id" value="' . (int) $f['id'] . '">' : '')
         . '<div class="inc-row">' . inc_field('Type', inc_select('type', inc_field_types(), $type, '', 'data-ftype'))
         . inc_field('Label', '<input type="text" name="label" value="' . e($f['label'] ?? '') . '" maxlength="255" required>', '', true, 'grow') . '</div>'
         . '<div class="inc-row">' . inc_field('Placeholder', '<input type="text" name="placeholder" value="' . e((string) ($f['placeholder'] ?? '')) . '" maxlength="255">', '', false, 'grow')
         . inc_field('Fills', inc_select('maps_to', inc_field_targets(), $f['maps_to'] ?? '', 'Only stored as an answer'), 'Copies the answer onto the application record.') . '</div>'
         . inc_field('Help text / description', inc_textarea('help', (string) ($f['help'] ?? ''), 2))
         . '<div data-show="text textarea email phone number date select multiselect radio checkbox yesno url">'
         . inc_field('Show on board card as', '<input type="text" name="card_label" value="' . e((string) ($r['card'] ?? '')) . '" maxlength="40" placeholder="e.g. Expertise">',
                     'A short label: the answer then shows on the application\'s card on the board. Leave blank to keep it off the card.') . '</div>'
         . '<div class="inc-row"><label class="check"><input type="checkbox" name="required" value="1"' . (!empty($f['required']) ? ' checked' : '') . '> Required</label>'
         . '<label class="check"><input type="checkbox" name="active" value="1"' . (!$f || (int) $f['active'] === 1 ? ' checked' : '') . '> Active</label></div>'
         . '<fieldset class="inc-fs" data-show="select multiselect radio checkbox"><legend>Options</legend>'
         . inc_field('Options come from', inc_select('option_source', inc_option_sources(), $f['option_source'] ?? ''))
         . inc_field('Options (one per line)', inc_textarea('options', implode("\n", $f['options'] ?? []), 4)) . '</fieldset>'
         . '<fieldset class="inc-fs" data-show="number date text textarea phone file"><legend>Validation</legend><div class="inc-row">'
         . '<label data-show="number date">Minimum <input type="text" name="rule_min" value="' . e((string) ($r['min'] ?? '')) . '" size="10"></label>'
         . '<label data-show="number date">Maximum <input type="text" name="rule_max" value="' . e((string) ($r['max'] ?? '')) . '" size="10"></label>'
         . '<label data-show="text textarea">Min length <input type="number" name="rule_minlen" min="0" value="' . e((string) ($r['minlen'] ?? '')) . '"></label>'
         . '<label data-show="text textarea">Max length <input type="number" name="rule_maxlen" min="1" value="' . e((string) ($r['maxlen'] ?? '')) . '"></label>'
         . '<label data-show="text phone">Pattern (regex) <input type="text" name="rule_pattern" value="' . e((string) ($r['pattern'] ?? '')) . '"></label>'
         . '<label data-show="file">Allowed types <input type="text" name="rule_accept" value="' . e((string) ($r['accept'] ?? '')) . '" placeholder="pdf,pptx"></label>'
         . '<label data-show="file">Max size (MB) <input type="number" name="rule_max_mb" min="1" max="25" value="' . e((string) ($r['max_mb'] ?? '')) . '"></label>'
         . '</div><p class="muted small" data-show="date">Dates as YYYY-MM-DD.</p></fieldset>'
         . '<fieldset class="inc-fs"><legend>Show only when (conditional visibility)</legend><div class="inc-row">'
         . inc_select('cond_field', $deps, $c['field'] ?? '', 'Always shown')
         . inc_select('cond_op', ['eq' => 'equals', 'neq' => 'does not equal', 'in' => 'is one of (a|b)', 'filled' => 'is answered', 'empty' => 'is blank'], $c['op'] ?? 'eq')
         . '<input type="text" name="cond_value" value="' . e((string) ($c['value'] ?? '')) . '" placeholder="value (e.g. Yes)"></div>'
         . '<p class="muted small">A field can depend only on a field above it. On a section heading, the condition applies to every field until the next heading. Hidden fields are never required and their answers are not saved.</p></fieldset>'
         . '<button class="btn btn-sm btn-primary">' . ($f ? 'Save field' : 'Add field') . '</button></form>';
}
