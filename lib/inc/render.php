<?php
/**
 * Incubation Management — draws an application form's fields.
 *
 * Shared by the form preview, the administrator's own entry of an
 * application, and the public application page. Each field carries its
 * condition as data-cond; assets/inc.js applies the same rules as
 * inc_form_visible() in the browser, hiding a field and disabling its inputs
 * (so a hidden required field never blocks the browser's own validation).
 * The server checks everything again regardless.
 */

require_once __DIR__ . '/forms.php';

function inc_render_fields(array $fields, array $values = [], array $errors = [], bool $disabled = false): string
{
    $h = '';
    foreach ($fields as $f) {
        if ((int) $f['active'] !== 1) {
            continue;
        }
        $uid = (string) $f['uid'];
        $cond = $f['cond'] ? ' data-cond="' . e(json_encode($f['cond'])) . '"' : '';
        if ($f['type'] === 'section') {
            $h .= '<div class="inc-sec" data-uid="' . e($uid) . '" data-section="1"' . $cond . '><h3>' . e($f['label']) . '</h3>'
                . ($f['help'] ? '<p>' . nl2br(e($f['help'])) . '</p>' : '') . '</div>';
            continue;
        }
        $id = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $uid);
        $name = 'f[' . $uid . ']';
        $v = $values[$uid] ?? '';
        $req = (int) $f['required'] === 1;
        $err = $errors[$uid] ?? '';
        $a = ($req ? ' required' : '') . ($disabled ? ' disabled' : '') . ($err ? ' aria-invalid="true" aria-describedby="' . e($id) . '_err"' : '');
        $ph = $f['placeholder'] ? ' placeholder="' . e($f['placeholder']) . '"' : '';
        $r = $f['rules'];
        $input = '';
        switch ($f['type']) {
            case 'textarea':
                $input = '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="4"' . $a . $ph
                       . (isset($r['maxlen']) ? ' maxlength="' . (int) $r['maxlen'] . '"' : '') . '>' . e(is_array($v) ? '' : $v) . '</textarea>';
                break;
            case 'select':
                $input = '<select id="' . e($id) . '" name="' . e($name) . '"' . $a . '><option value="">Choose…</option>';
                foreach (inc_field_choices($f) as $k => $lab) {
                    $input .= '<option value="' . e($k) . '"' . ((string) $v === (string) $k ? ' selected' : '') . '>' . e($lab) . '</option>';
                }
                $input .= '</select>';
                break;
            case 'multiselect':
                /* Tap to choose, as many as apply: a box of chips rather than a
                   multiple <select> nobody knows to Ctrl-click. Posted the same way. */
                $vals = is_array($v) ? array_map('strval', $v) : [];
                $input = '<div class="inc-choices chips" role="group" aria-labelledby="' . e($id) . '_l">';
                foreach (inc_field_choices($f) as $k => $lab) {
                    $input .= '<label><input type="checkbox" name="' . e($name) . '[]" value="' . e($k) . '"' . (in_array((string) $k, $vals, true) ? ' checked' : '')
                            . ($disabled ? ' disabled' : '') . '><span>' . e($lab) . '</span></label>';
                }
                $input .= '</div><div class="inc-hint">Choose all that apply.</div>';
                break;
            case 'radio':
            case 'yesno':
                $input = '<div class="inc-choices chips" role="radiogroup" aria-labelledby="' . e($id) . '_l">';
                foreach (inc_field_choices($f) as $k => $lab) {
                    $input .= '<label><input type="radio" name="' . e($name) . '" value="' . e($k) . '"' . ((string) $v === (string) $k ? ' checked' : '') . $a . '><span>' . e($lab) . '</span></label>';
                }
                $input .= '</div>';
                break;
            case 'checkbox':
                $vals = is_array($v) ? array_map('strval', $v) : [];
                // One option is a declaration to tick, not a choice: drawn as a plain tick box.
                $input = '<div class="inc-choices' . (count(inc_field_choices($f)) > 1 ? ' chips' : ' single') . '" role="group" aria-labelledby="' . e($id) . '_l">';
                foreach (inc_field_choices($f) as $k => $lab) {
                    $input .= '<label><input type="checkbox" name="' . e($name) . '[]" value="' . e($k) . '"' . (in_array((string) $k, $vals, true) ? ' checked' : '')
                            . ($disabled ? ' disabled' : '') . '><span>' . e($lab) . '</span></label>';
                }
                $input .= '</div>';
                break;
            case 'file':
                $acc = !empty($r['accept']) ? ' accept="' . e(implode(',', array_map(static fn($x) => '.' . $x, explode(',', $r['accept'])))) . '"' : '';
                $input = '<input type="file" id="' . e($id) . '" name="file_' . e($uid) . '"' . $a . $acc . '>';
                break;
            default:
                $type = ['email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date', 'url' => 'url'][$f['type']] ?? 'text';
                $extra = '';
                if ($f['type'] === 'number' || $f['type'] === 'date') {
                    $extra .= isset($r['min']) ? ' min="' . e((string) $r['min']) . '"' : '';
                    $extra .= isset($r['max']) ? ' max="' . e((string) $r['max']) . '"' : '';
                    $extra .= $f['type'] === 'number' ? ' step="any"' : '';
                }
                if ($f['type'] === 'text' && isset($r['maxlen'])) {
                    $extra .= ' maxlength="' . (int) $r['maxlen'] . '"';
                }
                $input = '<input type="' . $type . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e(is_array($v) ? '' : $v) . '"' . $a . $ph . $extra . '>';
        }
        $wide = in_array($f['type'], ['textarea', 'checkbox', 'radio', 'multiselect'], true) ? ' wide' : '';
        $h .= '<div class="inc-f' . $wide . ($err ? ' has-err' : '') . '" data-uid="' . e($uid) . '"' . ($req ? ' data-req="1"' : '') . $cond . '>'
            . '<label id="' . e($id) . '_l" for="' . e($id) . '">' . e($f['label']) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
            . ($f['help'] ? '<div class="inc-hint">' . nl2br(e($f['help'])) . '</div>' : '')
            . $input
            . ($err ? '<div class="inc-err" id="' . e($id) . '_err">' . e($err) . '</div>' : '')
            . '</div>';
    }
    return '<div class="inc-form">' . $h . '</div>';
}

/** Posted answers in the shape inc_form_validate() wants, and the uploads keyed by uid. */
function inc_posted_answers(array $fields): array
{
    $raw = (array) ($_POST['f'] ?? []);
    $files = [];
    foreach ($fields as $f) {
        if ($f['type'] === 'file' && isset($_FILES['file_' . $f['uid']])) {
            $files[$f['uid']] = $_FILES['file_' . $f['uid']];
        }
    }
    return [$raw, $files];
}

/** Splits a submission error into field errors, or a single message. */
function inc_submit_errors(InvalidArgumentException $e): array
{
    $m = $e->getMessage();
    if (str_starts_with($m, 'fields:')) {
        return [json_decode(substr($m, 7), true) ?: [], 'Please check the highlighted answers.'];
    }
    return [[], $m];
}
