<?php
/**
 * Digital Signatures — documents: the repository, versions, who may see
 * what, attachments and comments.
 *
 * A document has versions; each version is the PDF as uploaded, kept
 * unchanged. Signing works on the document's working file, which starts as
 * the current version and grows by incremental updates (stamps, signature
 * fields, signatures). When a signing cycle starts, the version is locked:
 * uploading a new version afterwards cancels the cycle in progress and the
 * document must be sent again, so nobody's signature ever ends up on bytes
 * they did not see.
 *
 * WHO SEES A DOCUMENT. Its owner and creator; everyone routed to it in any
 * cycle (and anyone signing on their behalf); people and departments it was
 * shared with; and, by role, the organisation (Read every document) or
 * their departments (Read documents of their departments). A Confidential
 * document is not visible department-wide, and a Restricted one only to the
 * people on it and the super administrator.
 */

function ds_doc_statuses(): array
{
    return [
        'draft'             => ['Draft', ''],
        'submitted'         => ['Submitted', 'blue'],
        'under_review'      => ['Under review', 'blue'],
        'pending_first'     => ['Pending first signature', 'amber'],
        'pending'           => ['Pending signature', 'amber'],
        'partial'           => ['Partially signed', 'violet'],
        'signed'            => ['Fully signed', 'green'],
        'rejected'          => ['Rejected', 'red'],
        'changes_requested' => ['Changes requested', 'orange'],
        'reinitiated'       => ['Reinitiated', 'blue'],
        'expired'           => ['Expired', 'grey'],
        'cancelled'         => ['Cancelled', 'grey'],
    ];
}

/** Statuses in which signatures are being collected. */
function ds_open_statuses(): array
{
    return ['submitted', 'under_review', 'pending_first', 'pending', 'partial'];
}

function ds_priorities(): array
{
    return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
}

function ds_confidentiality(): array
{
    return ['internal' => 'Internal', 'confidential' => 'Confidential', 'restricted' => 'Restricted'];
}

function ds_request_statuses(): array
{
    return [
        'waiting'   => ['Waiting', ''],
        'pending'   => ['Awaiting action', 'amber'],
        'signed'    => ['Signed', 'green'],
        'approved'  => ['Approved', 'green'],
        'reviewed'  => ['Reviewed', 'green'],
        'carried'   => ['Carried over', 'green'],
        'rejected'  => ['Rejected', 'red'],
        'changes'   => ['Changes requested', 'orange'],
        'skipped'   => ['Not needed', 'grey'],
        'cancelled' => ['Cancelled', 'grey'],
        'expired'   => ['Expired', 'grey'],
    ];
}

function ds_done_statuses(): array
{
    return ['signed', 'approved', 'reviewed', 'carried'];
}

/* ============================================================ categories */

function ds_default_categories(): array
{
    return ['Official Letters', 'Agreements', 'Memorandums of Understanding (MoUs)', 'Work Orders', 'Purchase Orders', 'Approvals', 'Contracts',
            'Employee Documents', 'Vendor Documents', 'Financial Documents', 'Administrative Documents', 'Other'];
}

/** The organisation's categories (made from the standard list the first time): id => name. */
function ds_categories(bool $activeOnly = true): array
{
    $rows = fabo_all('SELECT id, name FROM ds_categories WHERE org_id = ? AND deleted_at IS NULL' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY sort, name', 'i', [ds_org()]);
    if (!$rows && !$activeOnly) {
        return [];
    }
    if (!$rows && !fabo_one('SELECT 1 FROM ds_categories WHERE org_id = ? LIMIT 1', 'i', [ds_org()])) {
        foreach (ds_default_categories() as $i => $n) {
            fabo_exec('INSERT IGNORE INTO ds_categories (org_id, name, sort) VALUES (?,?,?)', 'isi', [ds_org(), $n, ($i + 1) * 10]);
        }
        return ds_categories($activeOnly);
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }
    return $out;
}

function ds_category_name(?int $id): string
{
    return $id ? (ds_categories(false)[$id] ?? '—') : '—';
}

/* ================================================================ access */

function ds_doc(int $id): ?array
{
    return $id > 0 ? fabo_one('SELECT * FROM ds_documents WHERE id = ? AND org_id = ? AND deleted_at IS NULL', 'ii', [$id, ds_org()]) : null;
}

/** Everyone on a document: owner, creator, routed (and on whose behalf), shared with. */
function ds_doc_participants(int $docId): array
{
    $d = fabo_one('SELECT owner_id, created_by_id FROM ds_documents WHERE id = ?', 'i', [$docId]);
    $ids = $d ? [(int) $d['owner_id'], (int) $d['created_by_id']] : [];
    foreach (fabo_all('SELECT person_id, on_behalf_of_id FROM ds_signature_requests WHERE doc_id = ? AND external_id IS NULL', 'i', [$docId]) as $r) {
        $ids[] = (int) $r['person_id'];
        if ($r['on_behalf_of_id']) {
            $ids[] = (int) $r['on_behalf_of_id'];
        }
    }
    foreach (fabo_all('SELECT person_id FROM ds_permissions WHERE doc_id = ? AND person_id IS NOT NULL', 'i', [$docId]) as $r) {
        $ids[] = (int) $r['person_id'];
    }
    return array_values(array_unique($ids));
}

function ds_doc_can_view(array $doc): bool
{
    if ((int) $doc['org_id'] !== ds_org()) {
        return false;
    }
    if (ds_is_super()) {
        return true;
    }
    $me = ds_me();
    if (in_array($me, ds_doc_participants((int) $doc['id']), true)) {
        return true;
    }
    if ($doc['confidentiality'] === 'restricted') {
        return false;
    }
    if (ds_member_grant() !== 'none' || ds_can_all_docs()) {
        return true;
    }
    if ($doc['confidentiality'] === 'confidential') {
        return false;
    }
    $dept = (int) ($doc['department_id'] ?? 0);
    if ($dept && fabo_one('SELECT 1 FROM ds_permissions WHERE doc_id = ? AND department_id = ?', 'ii', [(int) $doc['id'], $dept])
        && ds_person_dept($me) === $dept) {
        return true;
    }
    $scope = ds_dept_scope_for_read();
    return $dept > 0 && $scope !== [] && ($scope === null || in_array($dept, $scope, true));
}

/** Holds "Read every document" (an organisation-wide read). */
function ds_can_all_docs(): bool
{
    if (ds_is_super() || ds_member_grant() !== 'none') {
        return true;
    }
    $m = ds_matrix();
    foreach (array_keys(ds_roles_of(ds_me())) as $r) {
        if (in_array('docs.view_all', $m[$r] ?? [], true)) {
            return true;
        }
    }
    return false;
}

/** Departments readable through "Read documents of their departments": [] none, null all. */
function ds_dept_scope_for_read(): ?array
{
    if (!ds_can('docs.view_dept')) {
        return [];
    }
    return ds_scope_depts('docs.view_dept');
}

/** May the viewer change this document (edit, new version, send, cancel, reinitiate)? */
function ds_doc_can_manage(array $doc): bool
{
    if (!ds_writable() || (int) $doc['org_id'] !== ds_org()) {
        return false;
    }
    if (ds_is_super()) {
        return true;
    }
    $me = ds_me();
    if ($me > 0 && ($me === (int) $doc['owner_id'] || $me === (int) $doc['created_by_id'])) {
        return true;
    }
    if (fabo_one('SELECT 1 FROM ds_permissions WHERE doc_id = ? AND person_id = ? AND access = "manage"', 'ii', [(int) $doc['id'], $me])) {
        return true;
    }
    if (!ds_can('docs.manage') || $doc['confidentiality'] === 'restricted') {
        return false;
    }
    $scope = ds_scope_depts('docs.manage');
    return $scope === null || in_array((int) $doc['department_id'], $scope, true);
}

/**
 * The WHERE that limits a document list to what the viewer may see, as
 * [sql, types, params] over alias d.
 */
function ds_visible_sql(): array
{
    if (ds_is_super()) {
        return ['d.org_id = ? AND d.deleted_at IS NULL', 'i', [ds_org()]];
    }
    $me = ds_me();
    $part = '(d.owner_id = ? OR d.created_by_id = ? OR EXISTS (SELECT 1 FROM ds_signature_requests r WHERE r.doc_id = d.id AND (r.person_id = ? OR r.on_behalf_of_id = ?))'
          . ' OR EXISTS (SELECT 1 FROM ds_permissions p WHERE p.doc_id = d.id AND p.person_id = ?))';
    $types = 'iiiii';
    $params = [$me, $me, $me, $me, $me];
    if (ds_can_all_docs()) {
        $sql = '(' . $part . ' OR d.confidentiality <> "restricted")';
    } else {
        $scope = ds_dept_scope_for_read();
        $own = ds_person_dept($me);
        $extra = '';
        if ($own) {
            $extra .= ' OR (d.confidentiality = "internal" AND EXISTS (SELECT 1 FROM ds_permissions p2 WHERE p2.doc_id = d.id AND p2.department_id = ?) AND d.department_id = ?)';
            $types .= 'ii';
            $params[] = $own;
            $params[] = $own;
        }
        if ($scope === null) {
            $extra .= ' OR d.confidentiality = "internal"';
        } elseif ($scope) {
            $extra .= ' OR (d.confidentiality = "internal" AND d.department_id IN (' . implode(',', array_map('intval', $scope)) . '))';
        }
        $sql = '(' . $part . $extra . ')';
    }
    return ['d.org_id = ? AND d.deleted_at IS NULL AND ' . $sql, 'i' . $types, array_merge([ds_org()], $params)];
}

/** Opens a document for the viewer or stops with 404/403. Every open is in the trail. */
function ds_doc_open(int $id, bool $audit = false): array
{
    $doc = ds_doc($id);
    if (!$doc) {
        fabo_deny('That document does not exist.', 404);
    }
    if (!ds_doc_can_view($doc)) {
        ds_audit($id, 'document', $id, 'access_denied', 'Access refused to ' . $doc['ref_no'] . ' for ' . ds_actor());
        fabo_deny('You do not have access to this document.', 403);
    }
    if ($audit) {
        ds_audit($id, 'document', $id, 'view', 'Opened ' . $doc['ref_no']);
    }
    return $doc;
}

/* ============================================================== creating */

/** Reads one uploaded file field: [bytes, name] or null when none was sent. */
function ds_upload_bytes(string $field, int $index = -1): ?array
{
    $f = $_FILES[$field] ?? null;
    if (!$f) {
        return null;
    }
    $pick = static fn($k) => $index >= 0 ? ($f[$k][$index] ?? null) : $f[$k];
    $err = (int) $pick('error');
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'The file is larger than the server accepts.' : 'The upload did not complete. Try again.');
    }
    $tmp = (string) $pick('tmp_name');
    if (!is_uploaded_file($tmp) && empty($GLOBALS['ds_test_uploads'])) {
        throw new InvalidArgumentException('The upload did not complete. Try again.');
    }
    return [(string) file_get_contents($tmp), basename((string) $pick('name'))];
}

/** The document's fields from a form, validated. */
function ds_doc_fields(array $in, ?array $doc = null): array
{
    $cats = ds_categories();
    $depts = ds_departments();
    $out = [
        'title'           => ds_str($in['title'] ?? '', 255, true, 'Document title'),
        'category_id'     => (int) ($in['category_id'] ?? 0) ?: null,
        'department_id'   => (int) ($in['department_id'] ?? 0) ?: null,
        'unit'            => ds_str($in['unit'] ?? '', 120, false, 'Organisational unit') ?: null,
        'description'     => ds_str($in['description'] ?? '', 4000, false, 'Description') ?: null,
        'doc_date'        => ds_date($in['doc_date'] ?? '', false, 'Document date'),
        'valid_until'     => ds_date($in['valid_until'] ?? '', false, 'Validity / expiry date'),
        'owner_id'        => (int) ($in['owner_id'] ?? ($doc['owner_id'] ?? ds_me())),
        'priority'        => ds_pick($in['priority'] ?? 'normal', ds_priorities(), 'priority'),
        'confidentiality' => ds_pick($in['confidentiality'] ?? 'internal', ds_confidentiality(), 'confidentiality level'),
        'value_amount'    => ds_amount($in['value_amount'] ?? ''),
        'remarks'         => ds_str($in['remarks'] ?? '', 1000, false, 'Remarks') ?: null,
        'deadline'        => ds_date($in['deadline'] ?? '', false, 'Signing deadline'),
    ];
    if ($out['category_id'] !== null && !isset($cats[$out['category_id']]) && (int) ($doc['category_id'] ?? 0) !== $out['category_id']) {
        throw new InvalidArgumentException('Choose a valid document category.');
    }
    if ($out['department_id'] !== null && !isset($depts[$out['department_id']]) && (int) ($doc['department_id'] ?? 0) !== $out['department_id']) {
        throw new InvalidArgumentException('Choose a valid department.');
    }
    if (!ds_is_member($out['owner_id'])) {
        throw new InvalidArgumentException('The document owner must be someone in this organisation.');
    }
    if ($out['valid_until'] && $out['doc_date'] && $out['valid_until'] < $out['doc_date']) {
        throw new InvalidArgumentException('The validity date cannot be before the document date.');
    }
    if ($out['deadline'] && $out['deadline'] < date('Y-m-d')) {
        throw new InvalidArgumentException('The signing deadline cannot be in the past.');
    }
    // A department-scoped creator files documents into their own departments.
    if (!ds_is_super() && !ds_can_all_docs() && $out['department_id'] && ds_can('docs.manage')) {
        $scope = ds_scope_depts('docs.manage');
        if ($scope !== null && !in_array($out['department_id'], $scope, true) && ds_person_dept(ds_me()) !== $out['department_id']) {
            throw new InvalidArgumentException('You may file documents only into your own departments.');
        }
    }
    return $out;
}

/**
 * Creates a document from its fields and PDF. Returns the new id. The PDF
 * is scanned, stored encrypted, and becomes version 1 and the working file.
 */
function ds_doc_create(array $in, string $bytes, string $name): int
{
    ds_need('docs.create');
    $f = ds_doc_fields($in);
    [$bytes, $pages, $notes] = ds_scan_pdf($bytes, $name);
    return ds_tx(static function () use ($f, $bytes, $pages, $notes, $name) {
        $ref = ds_next_ref('doc');
        $code = ds_tracking_code();
        $id = fabo_exec('INSERT INTO ds_documents (org_id, ref_no, tracking_code, title, category_id, department_id, unit, description, doc_date, valid_until, owner_id,
                          created_by_id, created_by, priority, confidentiality, value_amount, remarks, deadline, status, last_activity_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"draft",NOW())', 'isssiissssiissssss',
                        [ds_org(), $ref, $code, $f['title'], $f['category_id'], $f['department_id'], $f['unit'], $f['description'], $f['doc_date'], $f['valid_until'],
                         $f['owner_id'], ds_me(), ds_actor(), $f['priority'], $f['confidentiality'], $f['value_amount'], $f['remarks'], $f['deadline']]);
        $fileId = ds_store($bytes, 'original', $name, $id);
        $vid = fabo_exec('INSERT INTO ds_document_versions (doc_id, version_no, file_id, sha256, pages, size, note, uploaded_by_id, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)',
                         'iiisiisis', [$id, 1, $fileId, hash('sha256', $bytes), $pages, strlen($bytes), $notes ? implode(' ', $notes) : null, ds_me(), ds_actor()]);
        fabo_exec('UPDATE ds_documents SET current_version_id = ?, working_file_id = ? WHERE id = ?', 'iii', [$vid, $fileId, $id]);
        $retain = ds_retention_years($f['category_id']);
        fabo_exec('UPDATE ds_documents SET retention_until = DATE_ADD(CURDATE(), INTERVAL ? YEAR) WHERE id = ?', 'ii', [$retain, $id]);
        ds_audit($id, 'document', $id, 'create', 'Created ' . $ref . ' "' . $f['title'] . '"', ['category' => ds_category_name($f['category_id']), 'department' => ds_dept_name($f['department_id'])]);
        ds_audit($id, 'version', $vid, 'upload', 'Uploaded version 1 (' . $name . ', ' . $pages . ' page' . ($pages === 1 ? '' : 's') . ')', ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]);
        return $id;
    });
}

function ds_doc_update(int $id, array $in, $version): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot change this document.', 403);
    }
    $f = ds_doc_fields($in, $doc);
    // Once signing has begun, what the signatories agreed to is fixed.
    if (!in_array($doc['status'], ['draft', 'reinitiated', 'rejected', 'changes_requested', 'cancelled', 'expired'], true)) {
        foreach (['title', 'category_id', 'value_amount', 'doc_date', 'valid_until'] as $k) {
            if ((string) $f[$k] !== (string) $doc[$k]) {
                throw new InvalidArgumentException('The title, category, value and dates cannot change while signatures are being collected. Cancel or wait for a decision first.');
            }
        }
    }
    ds_tx(static function () use ($id, $f, $version, $doc) {
        ds_bump($id, $version);
        fabo_exec('UPDATE ds_documents SET title=?, category_id=?, department_id=?, unit=?, description=?, doc_date=?, valid_until=?, owner_id=?, priority=?, confidentiality=?,
                   value_amount=?, remarks=?, deadline=? WHERE id = ?', 'siissssisssssi',
                  [$f['title'], $f['category_id'], $f['department_id'], $f['unit'], $f['description'], $f['doc_date'], $f['valid_until'], $f['owner_id'],
                   $f['priority'], $f['confidentiality'], $f['value_amount'], $f['remarks'], $f['deadline'], $id]);
        $changed = [];
        foreach ($f as $k => $v) {
            if ((string) $v !== (string) $doc[$k]) {
                $changed[$k] = ['from' => $doc[$k], 'to' => $v];
            }
        }
        ds_audit($id, 'document', $id, 'update', 'Edited the details of ' . $doc['ref_no'], $changed);
    });
    return 'Saved.';
}

/**
 * A new version of the PDF. Allowed while the document is a draft or has
 * come back (rejected, changes requested, reinitiated). During signing it
 * cancels the cycle in progress: the document is "Reinitiated" and must be
 * sent again; signatures on the old version stay in its history.
 */
function ds_doc_new_version(int $id, string $bytes, string $name, string $note, $version): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot change this document.', 403);
    }
    if (in_array($doc['status'], ['signed', 'cancelled', 'expired'], true)) {
        throw new InvalidArgumentException('A ' . strtolower(ds_doc_statuses()[$doc['status']][0]) . ' document cannot take a new version. Create a new document instead.');
    }
    ds_crypto_lock_check($doc, true);
    [$bytes, $pages, $notes] = ds_scan_pdf($bytes, $name);
    $inFlight = in_array($doc['status'], ds_open_statuses(), true);
    return ds_tx(static function () use ($doc, $id, $bytes, $pages, $notes, $name, $note, $version, $inFlight) {
        ds_bump($id, $version);
        $no = (int) fabo_one('SELECT COALESCE(MAX(version_no),0) n FROM ds_document_versions WHERE doc_id = ?', 'i', [$id])['n'] + 1;
        $fileId = ds_store($bytes, 'original', $name, $id);
        fabo_exec('UPDATE ds_document_versions SET superseded_at = NOW() WHERE doc_id = ? AND superseded_at IS NULL', 'i', [$id]);
        $vid = fabo_exec('INSERT INTO ds_document_versions (doc_id, version_no, file_id, sha256, pages, size, note, uploaded_by_id, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)',
                         'iiisiisis', [$id, $no, $fileId, hash('sha256', $bytes), $pages, strlen($bytes), mb_substr(trim($note . ' ' . implode(' ', $notes)), 0, 500) ?: null, ds_me(), ds_actor()]);
        fabo_exec('UPDATE ds_documents SET current_version_id = ?, working_file_id = ?, signed_file_id = NULL WHERE id = ?', 'iii', [$vid, $fileId, $id]);
        ds_audit($id, 'version', $vid, 'new_version', 'Uploaded version ' . $no . ' (' . $name . ')' . ($note !== '' ? ': ' . $note : ''), ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]);
        if ($inFlight) {
            $n = fabo_exec('UPDATE ds_signature_requests SET status = "cancelled", remarks = CONCAT(COALESCE(remarks,""), " [superseded by version ' . $no . ']")
                            WHERE doc_id = ? AND cycle = ? AND status IN ("waiting","pending")', 'ii', [$id, (int) $doc['cycle']]);
            fabo_exec('UPDATE ds_documents SET status = "reinitiated" WHERE id = ?', 'i', [$id]);
            ds_audit($id, 'document', $id, 'cycle_cancelled', 'Signing cycle ' . $doc['cycle'] . ' stopped: version ' . $no . ' replaces the locked version. ' . $n . ' pending request(s) cancelled; send it again to restart signing.');
            $people = array_map(static fn($r) => (int) $r['person_id'], fabo_all('SELECT DISTINCT person_id FROM ds_signature_requests WHERE doc_id = ? AND cycle = ?', 'ii', [$id, (int) $doc['cycle']]));
            ds_notify($people, 'changed', $doc['ref_no'] . ' was replaced by a new version', '"' . $doc['title'] . '" has a new version (' . $no . '). The signing in progress was stopped; you will be asked again when it is re-sent.', $id);
        }
        return 'Version ' . $no . ' uploaded.' . ($inFlight ? ' Signing was stopped: send the document again to restart it.' : '');
    });
}

function ds_doc_attach(int $id, string $bytes, string $name, string $title): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot change this document.', 403);
    }
    if (strlen($bytes) > max(1, (int) ds_setting('max_upload_mb')) * 1048576) {
        throw new InvalidArgumentException('The attachment is too large.');
    }
    require_once __DIR__ . '/../uploads.php';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain', 'csv' => 'text/csv'];
    if (!isset($allowed[$ext])) {
        throw new InvalidArgumentException('Attach a PDF, image, Word, Excel, CSV or text file.');
    }
    if ($ext === 'pdf') {
        [$bytes] = ds_scan_pdf($bytes, $name);
    }
    $fid = ds_store($bytes, 'attachment', $name, $id, $allowed[$ext]);
    $aid = fabo_exec('INSERT INTO ds_document_attachments (doc_id, file_id, title, created_by) VALUES (?,?,?,?)', 'iiss',
                     [$id, $fid, mb_substr($title !== '' ? $title : $name, 0, 255), ds_actor()]);
    ds_audit($id, 'attachment', $aid, 'attach', 'Attached "' . ($title !== '' ? $title : $name) . '"', ['sha256' => hash('sha256', $bytes)]);
    return 'Attachment added.';
}

function ds_doc_comment(int $id, string $body): string
{
    $doc = ds_doc_open($id);
    $body = ds_str($body, 4000, true, 'Comment');
    $cid = fabo_exec('INSERT INTO ds_comments (doc_id, person_id, author, body) VALUES (?,?,?,?)', 'iiss', [$id, ds_me(), ds_actor(), $body]);
    ds_audit($id, 'comment', $cid, 'comment', 'Commented on ' . $doc['ref_no'] . ': ' . mb_substr($body, 0, 160));
    $others = array_diff(ds_doc_participants($id), [ds_me()]);
    ds_notify($others, 'comment', ds_actor() . ' commented on ' . $doc['ref_no'], mb_substr($body, 0, 500), $id);
    return 'Comment added.';
}

/** Shares a document with a person or a department. */
function ds_doc_share(int $id, int $personId, int $deptId, string $access): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot share this document.', 403);
    }
    $access = $access === 'manage' ? 'manage' : 'view';
    if ($personId > 0) {
        if (!ds_is_member($personId)) {
            throw new InvalidArgumentException('Choose someone in this organisation.');
        }
        fabo_exec('DELETE FROM ds_permissions WHERE doc_id = ? AND person_id = ?', 'ii', [$id, $personId]);
        fabo_exec('INSERT INTO ds_permissions (doc_id, person_id, access, granted_by) VALUES (?,?,?,?)', 'iiss', [$id, $personId, $access, ds_actor()]);
        ds_audit($id, 'permission', $personId, 'share', 'Shared ' . $doc['ref_no'] . ' with ' . ds_person_name($personId) . ' (' . $access . ')');
        ds_notify([$personId], 'shared', $doc['ref_no'] . ' was shared with you', ds_actor() . ' shared "' . $doc['title'] . '" with you.', $id);
        return 'Shared with ' . ds_person_name($personId) . '.';
    }
    if ($deptId > 0 && isset(ds_departments()[$deptId])) {
        fabo_exec('DELETE FROM ds_permissions WHERE doc_id = ? AND department_id = ?', 'ii', [$id, $deptId]);
        fabo_exec('INSERT INTO ds_permissions (doc_id, department_id, access, granted_by) VALUES (?,?,"view",?)', 'iis', [$id, $deptId, ds_actor()]);
        ds_audit($id, 'permission', $deptId, 'share', 'Shared ' . $doc['ref_no'] . ' with the ' . ds_dept_name($deptId) . ' department');
        return 'Shared with ' . ds_dept_name($deptId) . '.';
    }
    throw new InvalidArgumentException('Choose a person or a department.');
}

function ds_doc_unshare(int $id, int $permId): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot change sharing on this document.', 403);
    }
    $p = fabo_one('SELECT * FROM ds_permissions WHERE id = ? AND doc_id = ?', 'ii', [$permId, $id]);
    if ($p) {
        fabo_exec('DELETE FROM ds_permissions WHERE id = ?', 'i', [$permId]);
        ds_audit($id, 'permission', $permId, 'unshare', 'Stopped sharing ' . $doc['ref_no'] . ' with ' . ($p['person_id'] ? ds_person_name((int) $p['person_id']) : ds_dept_name((int) $p['department_id'])));
    }
    return 'Sharing removed.';
}

function ds_doc_cancel(int $id, string $reason, $version): string
{
    $doc = ds_doc_open($id);
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot cancel this document.', 403);
    }
    if (in_array($doc['status'], ['signed', 'cancelled'], true)) {
        throw new InvalidArgumentException('This document is already ' . strtolower(ds_doc_statuses()[$doc['status']][0]) . '.');
    }
    $reason = ds_str($reason, 500, true, 'A reason');
    ds_tx(static function () use ($doc, $id, $reason, $version) {
        ds_bump($id, $version);
        fabo_exec('UPDATE ds_signature_requests SET status = "cancelled" WHERE doc_id = ? AND status IN ("waiting","pending")', 'i', [$id]);
        fabo_exec('UPDATE ds_signature_transactions SET status = "cancelled" WHERE doc_id = ? AND status IN ("initiated","awaiting")', 'i', [$id]);
        fabo_exec('UPDATE ds_documents SET status = "cancelled", closed_at = NOW() WHERE id = ?', 'i', [$id]);
        ds_audit($id, 'document', $id, 'cancel', 'Cancelled ' . $doc['ref_no'] . ': ' . $reason);
    });
    ds_notify(array_diff(ds_doc_participants($id), [ds_me()]), 'cancelled', $doc['ref_no'] . ' was cancelled', '"' . $doc['title'] . '" was cancelled by ' . ds_actor() . ': ' . $reason, $id);
    return 'Document cancelled.';
}

/** Soft delete: the document leaves every list but its files and trail are kept until retention ends. */
function ds_doc_delete(int $id): string
{
    $doc = ds_doc_open($id);
    ds_need('docs.delete');
    if (!ds_doc_can_manage($doc)) {
        fabo_deny('You cannot delete this document.', 403);
    }
    if (in_array($doc['status'], ds_open_statuses(), true)) {
        throw new InvalidArgumentException('Cancel the signing before deleting this document.');
    }
    if ((int) $doc['legal_hold'] === 1) {
        throw new InvalidArgumentException('This document is under legal hold and cannot be deleted.');
    }
    fabo_exec('UPDATE ds_documents SET deleted_at = NOW() WHERE id = ?', 'i', [$id]);
    ds_audit($id, 'document', $id, 'delete', 'Deleted ' . $doc['ref_no'] . ' (kept for retention until ' . ds_fmt_date($doc['retention_until']) . ')');
    return 'Document deleted.';
}

/* ============================================================== reading */

function ds_doc_versions(int $id): array
{
    return fabo_all('SELECT * FROM ds_document_versions WHERE doc_id = ? ORDER BY version_no DESC', 'i', [$id]);
}

function ds_doc_requests(int $id, ?int $cycle = null): array
{
    return $cycle === null
        ? fabo_all('SELECT * FROM ds_signature_requests WHERE doc_id = ? ORDER BY cycle DESC, stage_seq, seq, id', 'i', [$id])
        : fabo_all('SELECT * FROM ds_signature_requests WHERE doc_id = ? AND cycle = ? ORDER BY stage_seq, seq, id', 'ii', [$id, $cycle]);
}

function ds_doc_timeline(int $id, int $limit = 400): array
{
    return fabo_all('SELECT * FROM ds_audit WHERE org_id = ? AND doc_id = ? ORDER BY seq DESC LIMIT ' . (int) $limit, 'ii', [ds_org(), $id]);
}

function ds_doc_attachments(int $id): array
{
    return fabo_all('SELECT a.*, f.size, f.mime FROM ds_document_attachments a JOIN ds_files f ON f.id = a.file_id WHERE a.doc_id = ? AND a.deleted_at IS NULL ORDER BY a.id', 'i', [$id]);
}

function ds_doc_comments(int $id): array
{
    return fabo_all('SELECT * FROM ds_comments WHERE doc_id = ? AND deleted_at IS NULL ORDER BY id', 'i', [$id]);
}

/** Retention for a category (or the organisation's default), in years. */
function ds_retention_years(?int $categoryId): int
{
    if ($categoryId) {
        $r = fabo_one('SELECT retain_years FROM ds_retention_policies WHERE org_id = ? AND category_id = ? AND active = 1', 'ii', [ds_org(), $categoryId]);
        if ($r) {
            return max(1, (int) $r['retain_years']);
        }
    }
    $r = fabo_one('SELECT retain_years FROM ds_retention_policies WHERE org_id = ? AND category_id IS NULL AND active = 1', 'i', [ds_org()]);
    return max(1, (int) ($r['retain_years'] ?? ds_setting('retention_years')));
}

/**
 * An open crypto signing on the document (an eSign in progress, a DSC
 * download awaiting upload) holds the working file: the bytes being signed
 * must not change underneath it. Returns that transaction, or null; with
 * $throw, refuses instead.
 */
function ds_crypto_lock_check(array $doc, bool $throw = false, int $exceptRequest = 0): ?array
{
    $t = fabo_one('SELECT t.*, r.person_id FROM ds_signature_transactions t JOIN ds_signature_requests r ON r.id = t.request_id
                    WHERE t.doc_id = ? AND t.status IN ("initiated","awaiting") AND t.expires_at > NOW() AND t.request_id <> ? ORDER BY t.id DESC LIMIT 1',
                  'ii', [(int) $doc['id'], $exceptRequest]);
    if ($t && $throw) {
        throw new InvalidArgumentException(ds_person_name((int) $t['person_id']) . ' is signing this document with ' . ds_method_label($t['provider'])
            . ' right now. Try again after ' . date('H:i', ds_ts($t['expires_at'])) . ', or when they finish.');
    }
    return $t;
}
