<?php
/**
 * Intake forms. Admin creates a link for a sheet; anyone holding the link can
 * submit one row at a time through a plain form. Rows land straight in the
 * sheet with an audit entry naming the form. No login, no session identity.
 */

require_once __DIR__ . '/endpoint.php';

/** A form whose sheet_key is this accepts a row for ANY sheet. */
const FABO_FORM_UNIVERSAL = '*';

function fabo_form_is_universal(array $form): bool
{
    return ($form['sheet_key'] ?? '') === FABO_FORM_UNIVERSAL;
}

/** Sheets a form may write to — every non-admin sheet with a grid. */
function fabo_form_sheet_keys(): array
{
    return array_values(array_filter(
        fabo_sheet_order(),
        static fn($k) => $k !== 'dashboard' && empty(fabo_sheet($k)['admin_only'])
    ));
}

/** The sheet a submission targets: the form's own, or the one chosen on it. */
function fabo_form_target(array $form, array $post): ?string
{
    if (!fabo_form_is_universal($form)) {
        return $form['sheet_key'];
    }
    $k = (string) ($post['sheet_key'] ?? '');
    return in_array($k, fabo_form_sheet_keys(), true) ? $k : null;
}

function fabo_form_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    return fabo_one('SELECT * FROM form_links WHERE token = ?', 's', [$token]);
}

/** 'ok' | 'off' | 'expired'. */
function fabo_form_state(array $f): string
{
    if (!(int) $f['active']) {
        return 'off';
    }
    if (!empty($f['expires_at']) && strtotime($f['expires_at']) < time()) {
        return 'expired';
    }
    return 'ok';
}

function fabo_form_new_token(): string
{
    do {
        $t = bin2hex(random_bytes(16));
    } while (fabo_one('SELECT id FROM form_links WHERE token = ?', 's', [$t]));
    return $t;
}

/**
 * Files sent with a submission, stored against the row once it exists.
 *
 * The row has to be written first — an attachment belongs to a row id — so
 * this runs after the insert rather than beside it. Anything that will not
 * store is reported and the rest are kept: losing four good logos because
 * the fifth was a .docx would be its own kind of rude.
 *
 * Returns [stored, problems].
 */
function fabo_form_store_files(string $sheetKey, int $rowId, string $formLabel): array
{
    require_once __DIR__ . '/uploads.php';
    $files = $_FILES['row_files'] ?? null;
    if (!$files || !isset($files['name'])) {
        return [0, []];
    }
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $kept  = 0;
    $bad   = [];
    /* A form anyone can open must not be a way to fill the server's disk:
       a sensible number of files per submission, and no more. */
    $max = 10;
    if (count($names) > $max) {
        $bad[] = 'Only the first ' . $max . ' files were kept.';
    }

    for ($i = 0; $i < min(count($names), $max); $i++) {
        $one = is_array($files['name'])
            ? ['name' => $files['name'][$i], 'type' => $files['type'][$i],
               'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i],
               'size' => $files['size'][$i]]
            : $files;
        if (($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        [$ok, $msg, $stored] = fabo_store_upload($one);
        if (!$ok || !$stored) {
            $bad[] = $one['name'] . ': ' . $msg;
            continue;
        }
        fabo_exec('INSERT INTO attachments
                     (sheet_key, row_id, original_name, stored_name, mime, size_bytes,
                      caption, uploaded_by)
                   VALUES (?,?,?,?,?,?,?,?)', 'sissssss',
            [$sheetKey, $rowId, $stored['original_name'], $stored['stored_name'],
             $stored['mime'], (string) (int) $stored['size'], '',
             'Form: ' . $formLabel]);
        $kept++;
    }
    return [$kept, $bad];
}

/** A column's heading on a form: grouped columns carry their group, so a bare
 *  "Expected" reads "Total No. of Participants — Expected". */
function fabo_form_label(array $c): string
{
    $g = trim((string) ($c[3]['group'] ?? ''));
    return $g !== '' ? $g . ' — ' . $c[0] : (string) $c[0];
}

/** Is this column a single file or image, answered with a file picker of its own? */
function fabo_form_is_file_col(array $c): bool
{
    return in_array($c[2], ['file', 'image'], true);
}

/**
 * Files sent for single file / image columns, each stored and written into
 * its own cell -- the cell holds the attachment, the way the grid's upload
 * button leaves it. Runs once the row exists. Returns [stored, problems].
 */
function fabo_form_store_cell_files(array $sheet, string $sheetKey, int $rowId, string $formLabel): array
{
    require_once __DIR__ . '/uploads.php';
    $kept = 0;
    $bad  = [];
    foreach (fabo_form_fields($sheet) as $c) {
        if (!fabo_form_is_file_col($c)) {
            continue;
        }
        $one = $_FILES['cell_' . $c[1]] ?? null;
        if (!$one || is_array($one['name'] ?? null)
            || ($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        [$ok, $msg, $stored] = fabo_store_upload($one);
        if (!$ok || !$stored) {
            $bad[] = $one['name'] . ': ' . $msg;
            continue;
        }
        if ($c[2] === 'image' && !fabo_is_image((string) $stored['mime'])) {
            fabo_delete_stored((string) $stored['stored_name']);
            $bad[] = $one['name'] . ': ' . fabo_form_label($c) . ' takes an image.';
            continue;
        }
        $aid = (int) fabo_exec('INSERT INTO attachments
                 (sheet_key, row_id, original_name, stored_name, mime, size_bytes, caption, uploaded_by)
               VALUES (?,?,?,?,?,?,?,?)', 'sissssss',
            [$sheetKey, $rowId, $stored['original_name'], $stored['stored_name'],
             $stored['mime'], (string) (int) $stored['size'], '', 'Form: ' . $formLabel]);
        fabo_exec('UPDATE `' . $sheet['table'] . '` SET `' . $c[1] . '` = ? WHERE id = ?',
                  'ii', [$aid, $rowId]);
        $kept++;
    }
    return [$kept, $bad];
}

/** The input columns a form shows: everything a person types, in sheet order. */
function fabo_form_fields(array $sheet): array
{
    $out = [];
    foreach ($sheet['columns'] as $c) {
        if ($c[2] === 'computed' || $c[2] === 'id') {
            continue;
        }
        /* A gallery is not a typed answer: it is the file picker at the end
           of the form, and the files land as attachments on the row. */
        if ($c[2] === 'images') {
            continue;
        }
        // Sending a row for approval is something the team does from the sheet,
        // once the row exists. It is never a question to ask on an intake form.
        if ($c[1] === 'approval_to') {
            continue;
        }
        /* A live list (committees, clients...) is built from the portal's own
           data. On a form anyone can open it listed our internal names to
           strangers and let them pick one, so it is never asked here. */
        if ($c[2] === 'select' && function_exists('fabo_is_dynamic_list')
            && fabo_is_dynamic_list((string) ($c[3]['list'] ?? ''))) {
            continue;
        }
        /* On a requisition, the queue works these out. Showing them would
           invite somebody to promise themselves a date the queue has not
           agreed to. Scoped to that sheet by its table, because `status` is
           an ordinary question to ask on any of the others. */
        if (($sheet['table'] ?? '') === 'requisitions'
            && in_array($c[1], ['queue_position', 'promised_start', 'promised_due',
                                'turnaround_hours', 'extended_count', 'extension_reason',
                                'delivered_on', 'deliverable_link', 'status', 'raised_on',
                                // Who picks the work up is the desk's call, not the asker's.
                                'owner',
                                /* Not questions for the person asking: the brand is the
                                   desk's to set, and Confirmation is the desk's own note
                                   of whether the confirmation email went. */
                                'brand', 'confirm_state',
                                // Not asked on the form; the column stays on the sheet.
                                'theme'], true)) {
            continue;
        }
        $out[] = $c;
    }
    return $out;
}

/**
 * Inserts a submitted row. Returns [ok, message, businessId].
 * Every value is coerced to its column type and clamped to its dropdown.
 */
function fabo_form_submit(array $form, array $post): array
{
    $key = fabo_form_target($form, $post);
    if ($key === null) {
        return [false, 'Choose which sheet this entry belongs to.', '', null];
    }
    $sheet = fabo_sheet($key);
    if (!$sheet) {
        return [false, 'This form no longer points at a sheet.', '', null];
    }

    $cols = [];
    $vals = [];
    $types = '';
    $lists = fabo_sheet_lists($sheet);

    $required = fabo_form_required($sheet);
    foreach (fabo_form_fields($sheet) as $c) {
        [, $field, $type] = $c;
        $label = fabo_form_label($c);
        /* A single file or image is answered with its own picker and written
           into its cell once the row exists -- see fabo_form_store_cell_files. */
        if (fabo_form_is_file_col($c)) {
            continue;
        }
        $raw = $post[$field] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        if ($type === 'checkbox') {
            $raw = in_array(strtolower($raw), ['1', 'on', 'yes', 'true'], true) ? '1' : '0';
        }

        if ($raw === '' && in_array($field, $required, true)) {
            return [false, "“{$label}” is required.", '', null];
        }
        /* An address is the one field on a requisition the portal cannot do
           without: the whole promise is delivered to it. A typo here is a
           person who never hears back, so it is checked rather than trusted. */
        if ($raw !== '' && fabo_form_is_email_field($field)
            && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [false, "“{$raw}” is not an email address. Please check {$label}.", '', null];
        }
        if ($type === 'select' && $raw !== '') {
            $allowed = $lists[$c[3]['list']] ?? [];
            if ($allowed && !in_array($raw, $allowed, true)) {
                return [false, "“{$raw}” is not an option for {$label}.", '', null];
            }
        }
        $v = fabo_coerce($type, $raw);
        $cols[]  = '`' . $field . '`';
        $vals[]  = $v;
        $types  .= $type === 'int' ? 'i' : (in_array($type, ['money', 'dec'], true) ? 'd' : 's');
    }

    /* Filed under the organisation the form belongs to, so the row appears on
       their sheet. Without this a client's form put rows in no one's sheet:
       a client only ever sees the rows filed under them. */
    if ((int) ($form['client_id'] ?? 0) > 0 && !in_array('`client_id`', $cols, true)) {
        $cols[] = '`client_id`';
        $vals[] = (int) $form['client_id'];
        $types .= 'i';
    }

    $bizId = '';
    if (!empty($sheet['id_field'])) {
        $bizId  = fabo_next_id($sheet);
        $cols[] = '`' . $sheet['id_field'] . '`';
        $vals[] = $bizId;
        $types .= 's';
    }

    /* A requisition arrives already knowing two things about itself: the day
       it was raised, and that it is waiting. Asking the person to fill those
       in would only give them a way to get them wrong. */
    if ($key === 'requisitions') {
        foreach ([['raised_on', fabo_today()], ['status', 'Queued']] as [$f, $v]) {
            $at = array_search('`' . $f . '`', $cols, true);
            if ($at === false) {
                $cols[] = '`' . $f . '`';
                $vals[] = $v;
                $types .= 's';
            } elseif (trim((string) $vals[$at]) === '') {
                $vals[$at] = $v;
            }
        }
    }

    $sql = 'INSERT INTO `' . $sheet['table'] . '` (' . implode(', ', $cols) . ') VALUES ('
         . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $id  = fabo_exec($sql, $types, $vals);

    // An event sent in as already Completed is an approved one, as anywhere else.
    if (function_exists('fabo_signoff_on_complete')) {
        fabo_signoff_on_complete($key, (int) $id);
    }

    $who = ['is_admin' => false, 'client' => ['id' => null, 'name' => 'Form: ' . $form['label']]];
    fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
               VALUES (?,?,?,?,?,?,?)', 'sisisss',
              ['Intake form “' . $form['label'] . '”', null, $sheet['table'], $id, '(row)', null, 'submitted']);
    fabo_exec('UPDATE form_links SET submit_count = submit_count + 1, last_submitted_at = NOW() WHERE id = ?',
              'i', [(int) $form['id']]);

    /* A requisition joins the queue the moment it lands, and the queue tells
       the person who asked when their work starts. Everything the submitter
       sees about their slot comes from this. */
    [$filesKept, $fileProblems] = fabo_form_store_files($key, (int) $id, (string) $form['label']);
    [$cellKept, $cellProblems]  = fabo_form_store_cell_files($sheet, $key, (int) $id, (string) $form['label']);
    $filesKept   += $cellKept;
    $fileProblems = array_merge($fileProblems, $cellProblems);

    /* The Master Activity Log keeps a line for every row on the detail
       sheets; a row that arrives by form is one of them. */
    if (is_file(__DIR__ . '/masterlog.php')) {
        require_once __DIR__ . '/masterlog.php';
        if (function_exists('fabo_master_sync')) {
            fabo_master_sync($key, (int) $id);
        }
    }

    $queue = null;
    if ($key === 'requisitions') {
        require_once __DIR__ . '/requisitions.php';
        $out   = fabo_req_accept((int) $id);
        $queue = [
            'position' => (int) ($out['row']['queue_position'] ?? 0),
            'starts'   => (string) ($out['row']['promised_start'] ?? ''),
            'due'      => (string) ($out['row']['promised_due'] ?? ''),
            'type'     => (string) ($out['row']['req_type'] ?? ''),
            'to'       => (string) ($out['row']['raised_by_email'] ?? ''),
            'emailed'  => (bool) ($out['requester'] ?? false),
            'team'     => (int) ($out['team'] ?? 0),
        ];
    }

    return [
        true,
        'Thank you — your entry has been recorded' . ($bizId ? " as {$bizId}" : '') . '.',
        $bizId,
        ['sheet' => $key, 'row_id' => (int) $id, 'label' => $sheet['label'],
         'queue' => $queue, 'files' => $filesKept, 'file_problems' => $fileProblems],
    ];
}

/** Does this column hold an email address? Named by convention across the sheets. */
function fabo_form_is_email_field(string $field): bool
{
    return $field === 'email' || str_ends_with($field, '_email') || str_contains($field, 'email');
}

/** Which fields a form insists on: the sheet's name-like column. */
function fabo_form_required(array $sheet): array
{
    // A requisition with no type has no turnaround, and one with no address
    // has nobody to tell about its slot. Both are the queue's business.
    if (($sheet['table'] ?? '') === 'requisitions') {
        return ['req_type', 'raised_by', 'raised_by_email', 'event_name'];
    }
    $req = [];
    foreach ($sheet['columns'] as $c) {
        if (in_array($c[1], ['store_name', 'activity_name', 'title_topic', 'task_page', 'project_title',
                             'campaign_name', 'influencer_name', 'title_description', 'event_name',
                             'occasion'], true)) {
            $req[] = $c[1];
        }
    }
    return $req;
}
