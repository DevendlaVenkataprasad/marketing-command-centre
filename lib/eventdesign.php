<?php
/**
 * Design & publicity support for an event.
 *
 * Whoever adds an upcoming event can answer "Request Design & Publicity
 * Support" with Yes. That opens the few questions the Design & Requisition
 * sheet needs and the event does not already answer (the design_* columns on
 * the Events sheet). Nothing is asked twice: the event's name, dates, venue,
 * partners, hub / spoke, brand and SPOC are read from the event itself.
 *
 * Nothing goes to the design team while the event waits for its decision.
 * When it is approved (fabo_set_approval(), which the committee's decision
 * runs through) the requisition is raised on the Design & Requisition sheet,
 * joins the queue, and everyone is told: the design team, the SPOC (with the
 * date the queue promises), and the event's owner. The requisition's ID is
 * written back on the event, so it is raised once only.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sheets.php';

/** The questions Yes opens, as field => label (the Design Requisition columns of the Events sheet). */
function fabo_evdesign_fields(): array
{
    return [
        'design_req_type'     => 'Type of Creative',
        'design_required_for' => 'Creatives Required For',
        'design_qty'          => 'No. of Creatives',
        'design_theme'        => 'Theme',
        'design_mode'         => 'Online / Offline',
        'design_content'      => 'Content to go on the Creative',
        'design_reg_link'     => 'Registration Link / QR',
        'design_register_by'  => 'Last Date to Register',
        'design_required_by'  => 'Creatives Needed By',
    ];
}

/** Has the event asked for design support? */
function fabo_evdesign_wanted(array $row): bool
{
    return strcasecmp(trim((string) ($row['design_support'] ?? '')), 'Yes') === 0;
}

/**
 * A new event's values, checked for design support (called from
 * fabo_evstage_check_new(), so both + Add row boxes say the same thing).
 *
 * Yes needs the three things the design team cannot start without: what to
 * make, by when, and an email for the SPOC, who is told when it will be
 * ready. Anything else is optional. Without Yes, design answers are dropped.
 */
function fabo_evdesign_check(array $values): array
{
    if (!fabo_evdesign_wanted($values)) {
        return array_diff_key($values, fabo_evdesign_fields());
    }
    if (trim((string) ($values['design_req_type'] ?? '')) === '') {
        throw new InvalidArgumentException('Design & Publicity Support: choose the Type of Creative.');
    }
    $by = trim((string) ($values['design_required_by'] ?? ''));
    if ($by === '') {
        throw new InvalidArgumentException('Design & Publicity Support: say when the creatives are needed by.');
    }
    if (strtotime($by) && strtotime($by) < strtotime(date('Y-m-d'))) {
        throw new InvalidArgumentException('Design & Publicity Support: "Creatives Needed By" has already passed.');
    }
    $mail = trim((string) ($values['spoc_email'] ?? ''));
    if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Design & Publicity Support: give the SPOC Email — the SPOC is told when the creatives will be ready.');
    }
    return $values;
}

/** Has the event been approved (by its committee, or otherwise)? */
function fabo_evdesign_approved(array $row): bool
{
    if (strcasecmp(trim((string) ($row['approval_status'] ?? '')), 'Approved') === 0) {
        return true;
    }
    try {
        $a = fabo_one("SELECT state FROM approvals WHERE sheet_key = 'events' AND row_id = ?", 'i', [(int) $row['id']]);
        return ($a['state'] ?? '') === 'approved';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Raises the event's design requisition if it is due: support asked for, the
 * event approved, and none raised yet. Safe to call any number of times.
 * Returns the requisition ID raised, or '' when there was nothing to do.
 */
function fabo_evdesign_raise(int $eventId): string
{
    $e = fabo_one('SELECT * FROM `events` WHERE id = ?', 'i', [$eventId]);
    if (!$e || !fabo_evdesign_wanted($e) || trim((string) ($e['design_req_ref'] ?? '')) !== ''
        || !fabo_evdesign_approved($e) || trim((string) ($e['design_req_type'] ?? '')) === '') {
        return '';
    }
    require_once __DIR__ . '/endpoint.php';
    require_once __DIR__ . '/requisitions.php';
    $sheet = fabo_sheet('requisitions');
    if (!$sheet) {
        return '';
    }
    // Claim the event first, so two approvals landing together raise one requisition.
    $claimed = fabo_exec('UPDATE `events` SET design_req_ref = ? WHERE id = ? AND (design_req_ref IS NULL OR design_req_ref = "")',
                         'si', ['Raising…', $eventId]);
    if ($claimed < 1) {
        return '';
    }
    try {
        $str = static fn($k) => trim((string) ($e[$k] ?? ''));
        $spoc = $str('spoc_name');
        $v = [
            'raised_on'         => fabo_today(),
            'raised_by'         => $spoc !== '' ? $spoc : ($str('owner') !== '' ? $str('owner') : 'Event ' . $str('event_id')),
            'raised_by_phone'   => $str('spoc_contact'),
            'raised_by_email'   => $str('spoc_email'),
            'spoke'             => $str('hub_spoke'),
            'spoc_name'         => $spoc,
            'spoc_contact'      => $str('spoc_contact'),
            'brand'             => $str('brand'),
            'req_type'          => $str('design_req_type'),
            'required_for'      => $str('design_required_for') !== '' ? $str('design_required_for') : 'Event',
            'qty'               => $e['design_qty'] !== null && $e['design_qty'] !== '' ? (int) $e['design_qty'] : null,
            'event_name'        => $str('event_name'),
            'theme'             => $str('design_theme'),
            'event_start'       => $str('start_date') ?: null,
            'event_end'         => $str('end_date') ?: null,
            'partners'          => $str('partners') !== '' ? $str('partners') : $str('partner_details'),
            'venue'             => $str('location'),
            'mode'              => $str('design_mode'),
            'content'           => $str('design_content'),
            'registration_link' => $str('design_reg_link'),
            'register_by'       => $str('design_register_by') ?: null,
            'required_by'       => $str('design_required_by') ?: null,
            'status'            => 'Queued',
            'remarks'           => 'Raised from event ' . $str('event_id') . ' when it was approved.',
        ];
        // Only the columns the Design & Requisition sheet has (an organisation may have taken some off).
        $have = [];
        foreach ($sheet['columns'] as $c) {
            $have[$c[1]] = $c[2];
        }
        $cols = [];
        $vals = [];
        $types = '';
        foreach ($v as $f => $x) {
            if (!isset($have[$f]) || $x === null || $x === '') {
                continue;
            }
            $cols[] = '`' . $f . '`';
            $vals[] = $x;
            $types .= $have[$f] === 'int' ? 'i' : 's';
        }
        $reqId = fabo_next_id($sheet);
        if ($reqId !== '') {
            $cols[] = '`' . $sheet['id_field'] . '`';
            $vals[] = $reqId;
            $types .= 's';
        }
        if ((int) ($e['client_id'] ?? 0) > 0) {
            $cols[] = '`client_id`';
            $vals[] = (int) $e['client_id'];
            $types .= 'i';
        }
        $id = fabo_exec('INSERT INTO `' . $sheet['table'] . '` (' . implode(', ', $cols) . ') VALUES ('
                        . implode(', ', array_fill(0, count($cols), '?')) . ')', $types, $vals);
        fabo_exec('UPDATE `events` SET design_req_ref = ? WHERE id = ?', 'si', [$reqId !== '' ? $reqId : 'REQ #' . $id, $eventId]);
        fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value) VALUES (?,?,?,?,?,?,?)',
                  'sisisss', ['Event approval', (int) ($e['client_id'] ?? 0) ?: null, $sheet['table'], $id, '(row)', null,
                              'raised from event ' . $str('event_id')]);
        if (is_file(__DIR__ . '/masterlog.php')) {
            require_once __DIR__ . '/masterlog.php';
            fabo_master_sync('requisitions', $id);
        }
        // Into the queue, and the design team and the SPOC are told (fabo_req_accept).
        $out = fabo_req_accept($id);
        fabo_evdesign_tell_owner($e, $reqId, $out['row'] ?? []);
        return $reqId;
    } catch (Throwable $ex) {
        // Let a later approval or edit try again.
        fabo_exec('UPDATE `events` SET design_req_ref = NULL WHERE id = ? AND design_req_ref = ?', 'is', [$eventId, 'Raising…']);
        error_log('FABO event design requisition: ' . $ex->getMessage());
        return '';
    }
}

/** The event's owner hears that the design team has it, in the portal and by email. */
function fabo_evdesign_tell_owner(array $e, string $reqId, array $req): void
{
    $owner = trim((string) ($e['owner'] ?? ''));
    if ($owner === '') {
        return;
    }
    require_once __DIR__ . '/notify.php';
    require_once __DIR__ . '/mailtpl.php';
    require_once __DIR__ . '/requisitions.php';
    if (!function_exists('e')) {
        require_once __DIR__ . '/ui.php';
    }
    $title = trim((string) ($e['event_name'] ?? '')) ?: (string) ($e['event_id'] ?? 'Your event');
    $due   = trim((string) ($req['promised_due'] ?? ''));
    $head  = 'Design requisition ' . $reqId . ' raised for ' . $title;
    $body  = 'The event is approved, so its creatives are now with the design team'
           . ($due !== '' ? '; the queue promises them by ' . date('d M Y', strtotime($due)) : '') . '.';
    $org   = (int) ($e['client_id'] ?? 0);
    $mine  = $org > 0 ? '(id = ' . $org . ' OR parent_id = ' . $org . ')' : "(kind = 'team' AND parent_id IS NULL)";
    foreach (fabo_all('SELECT * FROM clients WHERE owner_name = ? AND revoked_at IS NULL AND ' . $mine, 's', [$owner]) as $p) {
        try {
            fabo_notify((int) $p['id'], $head, $body, fabo_row_link((int) $p['id'], 'events', (int) $e['id']));
            $to = fabo_event_mail_off('event-design') ? '' : trim((string) ($p['email'] ?? ''));   // the notice above still goes
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)
                && strcasecmp($to, trim((string) ($e['spoc_email'] ?? ''))) !== 0) {
                fabo_req_post($to, $head, 'The design team has your event',
                    fabo_mail_p('Hi ' . e((string) $p['name']) . ',') . fabo_mail_p(e($body))
                    . fabo_mail_rows(fabo_req_summary($req)) . fabo_mail_button('Open the portal'), $org);
            }
        } catch (Throwable $ex) {
            error_log('FABO event design owner notice: ' . $ex->getMessage());
        }
    }
}
