<?php
/**
 * A CRM's Form tab: its one application form -- the link to share, the
 * questions, where applications go and who gets them, and the
 * acknowledgement email the applicant receives.
 *
 * Included by admin/crm.php inside the tab switch, so it works with that
 * page's $board, $viewer, $fields, $stages, $u, $hid, $who, $mayEdit and
 * $mayDesign. Changing the form needs design rights on the CRM (it can add
 * fields to it); anyone who can edit records sees and shares the link.
 */

require_once __DIR__ . '/crm_forms.php';
require_once __DIR__ . '/crm_mail.php';

if (!function_exists('fabo_cf_item_row')) {
    /** One row of the question list. $n names its inputs; order is the order on the page. */
    function fabo_cf_item_row($n, array $it, ?array $f): string
    {
        $h = static fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $p = 'item[' . $n . ']';
        $isHead = $it['kind'] === 'heading';
        $out  = '<div class="cf-item' . ($isHead ? ' heading' : '') . '">';
        $out .= '<div class="cf-move"><button type="button" class="btn btn-sm" data-mv="up" aria-label="Move up">↑</button>'
              . '<button type="button" class="btn btn-sm" data-mv="down" aria-label="Move down">↓</button></div>';
        $out .= '<div class="cf-main"><input type="hidden" name="' . $p . '[kind]" value="' . ($isHead ? 'heading' : 'field') . '">';
        if ($isHead) {
            $out .= '<input type="text" name="' . $p . '[label]" value="' . $h($it['label']) . '" maxlength="200" placeholder="Section heading, e.g. Your education" aria-label="Section heading">'
                  . '<input type="text" class="cf-helpin" name="' . $p . '[help]" value="' . $h($it['help']) . '" maxlength="500" placeholder="A line under the heading (optional)" aria-label="Line under the heading">'
                  . '<div class="cf-meta">Section heading</div>';
        } else {
            $out .= '<input type="hidden" name="' . $p . '[field_key]" value="' . $h($it['field_key']) . '">'
                  . '<input type="text" name="' . $p . '[label]" value="' . $h($it['label']) . '" maxlength="200" aria-label="Question">'
                  . '<input type="text" class="cf-helpin" name="' . $p . '[help]" value="' . $h($it['help']) . '" maxlength="500" placeholder="Help under the question (optional)" aria-label="Help text">'
                  . '<div class="cf-meta"><span>' . $h($f['label'] ?? '') . ' · <span class="cf-type">' . $h(fabo_crm_type_label((string) ($f['type'] ?? 'text'))) . '</span></span>'
                  . (!empty($f['required']) ? '<span>required in the CRM</span>' : '') . '</div>';
        }
        $out .= '</div><div class="cf-side">';
        if (!$isHead) {
            $req = !empty($it['required']) || !empty($f['required']);
            $out .= '<label class="cf-tick"><input type="checkbox" name="' . $p . '[required]" value="1"' . ($req ? ' checked' : '')
                  . (!empty($f['required']) ? ' disabled' : '') . '> Required</label>';
        }
        $out .= '<label class="cf-tick"><input type="checkbox" name="' . $p . '[remove]" value="1"> Remove</label></div></div>';
        return $out;
    }
}

$cform = fabo_cf_for_board($board, $mayDesign, $who);
?>
<?php if (!$cform): ?>
  <div class="card"><h2>Application form</h2>
    <div class="empty">This CRM has no application form yet. The person who runs it can set one up here.</div></div>
<?php else:
    $cid       = (int) $board['id'];
    $byKey     = [];
    foreach ($fields as $f) {
        $byKey[(string) $f['field_key']] = $f;
    }
    $items     = fabo_cf_items((int) $cform['id']);
    $onForm    = array_flip(array_filter(array_column($items, 'field_key')));
    $assignees = fabo_cf_assignees($board);
    $people    = fabo_cf_people($cform);
    $url       = fabo_cf_url($cform);
    $state     = fabo_cf_state($cform);
    $postTo    = $u(['crm' => $cid]);
    $tagsAck   = fabo_crm_mail_tags($fields) + ['Reference' => 'Their application number'];
    $missingReq = $noAccess = [];
    $emailAsked = false;
    foreach ($fields as $f) {
        if (!empty($f['required']) && $f['type'] !== 'autonumber' && !isset($onForm[$f['field_key']])) {
            $missingReq[] = $f['label'];
        }
        if ($f['type'] === 'email' && isset($onForm[$f['field_key']])) {
            $emailAsked = true;
        }
    }
    foreach (fabo_crm_assignable($board) as $pid => $pn) {
        if (!isset($assignees[$pid])) {
            $noAccess[] = $pn;
        }
    }
    $formHid = static fn(string $a) => $hid($a, 'form');
?>
  <div class="card cf-share">
    <div class="cf-share-main">
      <h2><?= fabo_crm_icon('inbox', 18) ?> Your application form
        <?php if ($state !== 'ok'): ?> <span class="pill view">closed</span><?php endif; ?></h2>
      <p class="crm-hint">Share this one link. Every application lands here as a new <?= e(mb_strtolower($board['record_label'])) ?>,
        in <b><?= e(($stageById[(int) $cform['stage_id']]['name'] ?? '') ?: 'the first stage') ?></b>
        <?php $aw = fabo_cf_assign_words($cform, $board); ?>· <?= $aw === 'Not assigned' ? 'left unassigned' : 'assigned: ' . e($aw) ?>.</p>
      <div class="tokenlink" id="cfUrl"><?= e($url) ?></div>
      <div class="cf-acts">
        <button class="btn btn-sm btn-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('cfUrl').textContent);this.textContent='Copied'">Copy link</button>
        <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">Open the form</a>
        <a class="btn btn-sm" href="https://wa.me/?text=<?= e(rawurlencode($cform['title'] . ' — apply here: ' . $url)) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
        <?php if ($mayDesign): ?>
          <form method="post" action="<?= e($postTo) ?>" style="margin:0"><?= $formHid('form_toggle') ?>
            <button class="btn btn-sm"><?= (int) $cform['active'] ? 'Close the form' : 'Open the form again' ?></button></form>
          <form method="post" action="<?= e($postTo) ?>" style="margin:0"><?= $formHid('form_rotate') ?>
            <button class="btn btn-sm" onclick="return confirm('Make a new link? The current one stops working straight away.')">New link</button></form>
        <?php endif; ?>
      </div>
    </div>
    <div class="cf-stat"><b><?= number_format((int) $cform['submit_count']) ?></b><span>applications received</span>
      <?php if ($cform['last_submitted_at']): ?><small>last <?= e(fabo_crm_ago((string) $cform['last_submitted_at'])) ?></small><?php endif; ?>
      <?php if ((int) $cform['submit_count']): ?><a href="<?= e($u(['crm' => $cid, 'tab' => 'table', 'sort' => 'created'])) ?>">See them</a><?php endif; ?></div>
  </div>

  <?php if (!$mayDesign): ?>
    <p class="crm-hint">Only the person who runs this CRM can change the form.</p>
  <?php else: ?>
  <?php if ($missingReq): ?>
    <div class="flash err">Required in the CRM but not on the form: <b><?= e(implode(', ', $missingReq)) ?></b>.
      Applications will arrive without them — add them below.</div>
  <?php endif; ?>

  <div class="cf-grid">
  <!-- ================================================= questions -->
  <form method="post" action="<?= e($postTo) ?>" class="card" id="questions">
    <?= $formHid('form_items') ?>
    <h2>Questions</h2>
    <p class="crm-hint">In the order applicants see them. Reword any question; ↑ ↓ to reorder.</p>
    <div id="cfItems">
      <?php foreach ($items as $i => $it):
          $f = $it['kind'] === 'field' ? ($byKey[(string) $it['field_key']] ?? null) : null;
          if ($it['kind'] === 'field' && !$f) { continue; } ?>
        <?= fabo_cf_item_row($i, $it, $f) ?>
      <?php endforeach; ?>
    </div>
    <template id="cfRowField"><?= fabo_cf_item_row('__N__', ['kind' => 'field', 'field_key' => '__KEY__', 'label' => '__LABEL__', 'help' => '', 'required' => 0],
                                                    ['type' => 'text', 'label' => '__LABEL__', 'required' => 0]) ?></template>
    <template id="cfRowHead"><?= fabo_cf_item_row('__N__', ['kind' => 'heading', 'field_key' => null, 'label' => '', 'help' => '', 'required' => 0], null) ?></template>
    <div class="cf-add">
      <label for="cfAddField" class="crm-vh">Add a question</label>
      <select id="cfAddField">
        <option value="">+ Add a question…</option>
        <?php foreach ($fields as $f): if ($f['type'] === 'autonumber') { continue; } ?>
          <option value="<?= e($f['field_key']) ?>" data-label="<?= e($f['label']) ?>" data-type="<?= e(fabo_crm_type_label($f['type'])) ?>"<?= isset($onForm[$f['field_key']]) ? ' disabled' : '' ?>><?= e($f['label']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm" type="button" id="cfAddHead">+ Section heading</button>
    </div>
    <button class="btn btn-primary" style="margin-top:14px">Save questions</button>

  </form>

  <div class="cf-side-col">
  <!-- ================================================= acknowledgement -->
  <form method="post" action="<?= e($postTo) ?>" class="card" id="ack">
    <?= $formHid('form_ack') ?>
    <h2>Acknowledgement email</h2>
    <label class="cf-tick cf-big"><input type="checkbox" name="ack_on" value="1"<?= (int) $cform['ack_on'] ? ' checked' : '' ?>>
      Email the applicant as soon as they apply</label>
    <?php if (!$emailAsked): ?>
      <p class="flash err" style="margin:8px 0">The form does not ask for an email address, so there is nobody to send it to. Add the Email question.</p>
    <?php endif; ?>
    <div class="sb-field" style="margin-top:10px"><label for="ackS">Subject</label>
      <input id="ackS" name="ack_subject" maxlength="200" value="<?= e((string) $cform['ack_subject']) ?>" data-merge></div>
    <div class="sb-field" style="margin-top:10px"><label for="ackB">Message</label>
      <textarea id="ackB" name="ack_body" rows="8" data-merge><?= e((string) $cform['ack_body']) ?></textarea></div>
    <?= fabo_crm_mail_insert($tagsAck) ?>
    <button class="btn btn-primary" style="margin-top:10px">Save email</button>
  </form>

  <!-- ================================================= settings -->
  <form method="post" action="<?= e($postTo) ?>" class="card">
    <?= $formHid('form_settings') ?>
    <h2>Settings</h2>
    <div class="sb-field"><label for="cfT">Form title</label><input id="cfT" name="title" maxlength="160" required value="<?= e($cform['title']) ?>"></div>
    <div class="sb-field" style="margin-top:10px"><label for="cfI">Introduction <small>(above the questions)</small></label>
      <textarea id="cfI" name="intro" rows="2"><?= e((string) $cform['intro']) ?></textarea></div>
    <div class="sb-field" style="margin-top:10px"><label for="cfY">Message after applying</label>
      <textarea id="cfY" name="thanks" rows="2"><?= e((string) $cform['thanks']) ?></textarea></div>
    <div class="sb-field" style="margin-top:10px"><label for="cfS">New applications start in</label>
      <select id="cfS" name="stage_id">
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $cform['stage_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
      </select></div>
    <fieldset class="cf-assign">
      <legend>Assign new applications to</legend>
      <label><input type="radio" name="assign_mode" value="none"<?= $cform['assign_mode'] === 'none' ? ' checked' : '' ?>> Nobody</label>
      <label><input type="radio" name="assign_mode" value="one"<?= $cform['assign_mode'] === 'one' ? ' checked' : '' ?>> Always the same person</label>
      <div class="cf-sub" data-for="one">
        <select name="assign_one" aria-label="Person">
          <option value="0">— choose —</option>
          <?php foreach ($assignees as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= $cform['assign_mode'] === 'one' && ($people[0] ?? 0) === $pid ? ' selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?>
        </select></div>
      <label><input type="radio" name="assign_mode" value="rotate"<?= $cform['assign_mode'] === 'rotate' ? ' checked' : '' ?>> Take turns among…</label>
      <div class="cf-sub" data-for="rotate">
        <?php foreach ($assignees as $pid => $pn): ?>
          <label class="cf-tick"><input type="checkbox" name="assign_many[]" value="<?= (int) $pid ?>"<?= $cform['assign_mode'] === 'rotate' && in_array($pid, $people, true) ? ' checked' : '' ?>> <?= e($pn) ?></label>
        <?php endforeach; ?>
      </div>
      <?php if (!$assignees): ?>
        <p class="crm-hint">Nobody can be assigned yet: give your team access to this CRM first (Access tab).</p>
      <?php elseif ($noAccess): ?>
        <p class="crm-hint">Not listed because they cannot open this CRM: <?= e(implode(', ', $noAccess)) ?>.</p>
      <?php endif; ?>
    </fieldset>
    <input type="hidden" name="active" value="<?= (int) $cform['active'] ?>">
    <button class="btn btn-primary">Save settings</button>
  </form>

  <!-- ================================================= new question -->
  <form method="post" action="<?= e($postTo) ?>" class="card" id="newq">
    <?= $formHid('form_question') ?>
    <h2>A new question</h2>
    <p class="crm-hint">For something the CRM does not hold yet: it becomes a CRM field and goes to the end of the form.
      Save any changes to the questions first.</p>
    <div class="sb-field"><label for="cfQL">Question</label><input id="cfQL" name="label" required maxlength="120" placeholder="e.g. Which campus do you prefer?"></div>
    <div class="sb-row" style="margin-top:10px">
      <div class="sb-field grow"><label for="cfQT">Answer type</label>
        <select id="cfQT" name="type">
          <?php foreach (fabo_crm_types() as $tk => $td): if ($tk === 'autonumber') { continue; } ?><option value="<?= e($tk) ?>"><?= e($td[0]) ?></option><?php endforeach; ?>
        </select></div>
      <div class="sb-field"><label>&nbsp;</label><label class="cf-tick"><input type="checkbox" name="required" value="1"> Required</label></div>
    </div>
    <div class="sb-field" style="margin-top:10px"><label for="cfQO">Choices <small>(for a dropdown or choice — one per line)</small></label>
      <textarea id="cfQO" name="options" rows="3"></textarea></div>
    <button class="btn" style="margin-top:10px">Add question</button>
  </form>
  </div>
  </div>

  <script>
  (function () {
    var box = document.getElementById('cfItems'), n = 1000;
    box.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-mv]');
      if (!b) { return; }
      var row = b.closest('.cf-item');
      if (b.getAttribute('data-mv') === 'up' && row.previousElementSibling) { box.insertBefore(row, row.previousElementSibling); }
      if (b.getAttribute('data-mv') === 'down' && row.nextElementSibling) { box.insertBefore(row.nextElementSibling, row); }
      b.focus();
    });
    box.addEventListener('change', function (ev) {
      if (ev.target.matches('input[name$="[remove]"]')) { ev.target.closest('.cf-item').classList.toggle('removed', ev.target.checked); }
    });
    function add(tplId, key, label, type) {
      var html = document.getElementById(tplId).innerHTML.split('__N__').join(String(n++))
        .split('__KEY__').join(key || '').split('__LABEL__').join(label || '');
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var row = wrap.firstChild;
      if (type) { var t = row.querySelector('.cf-type'); if (t) { t.textContent = type; } }
      box.appendChild(row);
      var inp = row.querySelector('input[type=text]');
      if (inp) { inp.focus(); }
    }
    var sel = document.getElementById('cfAddField');
    sel.addEventListener('change', function () {
      var o = sel.options[sel.selectedIndex];
      if (!o.value) { return; }
      add('cfRowField', o.value, o.getAttribute('data-label'), o.getAttribute('data-type'));
      o.disabled = true;
      sel.selectedIndex = 0;
    });
    document.getElementById('cfAddHead').addEventListener('click', function () { add('cfRowHead'); });
    function syncAssign() {
      var m = (document.querySelector('input[name=assign_mode]:checked') || {}).value;
      document.querySelectorAll('.cf-sub').forEach(function (d) { d.hidden = d.getAttribute('data-for') !== m; });
    }
    document.querySelectorAll('input[name=assign_mode]').forEach(function (r) { r.addEventListener('change', syncAssign); });
    syncAssign();
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
