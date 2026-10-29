<?php
/**
 * Per-row checklists.
 *
 * Each sheet has a list of things that must be true before a row is really
 * done — "Sign Board", "Google Maps Creation", and so on. Items can be marked
 * **mandatory**: a row is not complete until every mandatory item is ticked.
 *
 * The items themselves live in `checklist_template` and are fully editable in
 * the portal — add, rename, reorder, mark mandatory, hide or delete — from the
 * checklist panel on any row. The tick and the written description live per row.
 */

require_once __DIR__ . '/db.php';

/**
 * The starting checklist for each sheet: [item, mandatory].
 * Used only to seed a sheet the first time; after that the list is yours.
 */
function fabo_checklist_defaults(): array
{
    return [
        // The store-launch list, as handed over. The first nine are mandatory.
        'store' => [
            ['Sign Board', 1],
            ['Landing Page (New)', 1],
            ['Google Maps Creation', 1],
            ['Location Update on Website', 1],
            ['In-Store Branding', 1],
            ['Launch Event', 1],
            ['Influencer', 1],
            ['Social Media Announcement', 1],
            ['Post Event Campaign', 1],
            ['Google Business Profile verified', 0],
            ['Local SEO keywords added', 0],
            ['Contact number / WhatsApp live', 0],
            ['Store photos uploaded', 0],
            ['Staff briefed', 0],
        ],
        'events' => [
            ['Launch Event', 1],
            ['Social Media Announcement', 1],
            ['Influencer', 1],
            ['Post Event Campaign', 1],
            ['Venue booked', 0],
            ['Invites sent', 0],
            ['Creatives approved', 0],
            ['Vendors confirmed', 0],
            ['Run sheet ready', 0],
            ['Photography arranged', 0],
            ['Lead capture ready', 0],
            ['Post-event report done', 0],
        ],
        'content' => [
            ['Brief approved', 1], ['Draft written', 1], ['Proofread', 1], ['Published', 1],
            ['SEO keywords included', 0], ['Images / creatives ready', 0], ['Links checked', 0],
            ['Meta title & description set', 0], ['Scheduled', 0], ['Shared on social', 0],
        ],
        'design' => [
            ['Brief received', 1], ['Concept approved', 1], ['Final files exported', 1],
            ['Brand guidelines followed', 0], ['Copy proofread', 0],
            ['Print specifications checked', 0], ['Uploaded to drive', 0],
            ['Handed to vendor / team', 0],
        ],
        'website' => [
            ['Page live', 1], ['Location Update on Website', 1],
            ['Meta title & description set', 0], ['H1 correct', 0],
            ['Images compressed with alt text', 0], ['Internal links added', 0],
            ['Schema markup added', 0], ['Mobile layout checked', 0],
            ['Submitted to Search Console', 0], ['Analytics tracking confirmed', 0],
        ],
        'campaigns' => [
            ['Landing Page (New)', 1], ['Conversion tracking installed', 1],
            ['Creatives approved', 1], ['Campaign live', 1],
            ['Audience defined', 0], ['Budget approved', 0], ['UTM tags added', 0],
            ['Ad copy approved', 0], ['Lead form tested', 0],
        ],
        'influencers' => [
            ['Agreement signed', 1], ['Content approved before posting', 1], ['Post live', 1],
            ['Brief shared', 0], ['Disclosure / #ad included', 0], ['Tracking link used', 0],
            ['Payment released', 0], ['Performance captured', 0],
        ],
        'btl' => [
            ['Venue / permission confirmed', 1], ['Materials printed', 1],
            ['Lead capture form ready', 1],
            ['Staff briefed', 0], ['Standee / banner installed', 0], ['Photos captured', 0],
            ['Leads uploaded to the sheet', 0], ['Feedback collected', 0],
        ],
        'master' => [
            ['Owner assigned', 1], ['Budget approved', 1], ['Signed off', 1],
            ['Brief documented', 0], ['Deliverable link added', 0], ['Reviewed', 0],
        ],
        'calendar' => [
            ['Creative designed', 1], ['Caption written', 1], ['Posted', 1],
            ['Content angle finalised', 0], ['Hashtags added', 0], ['Scheduled', 0],
        ],
    ];
}

function fabo_has_checklist(string $sheetKey): bool
{
    return isset(fabo_checklist_defaults()[$sheetKey]);
}

/**
 * A sheet's checklist items, in order, as rows of the template table.
 * Seeds from the defaults on first use so the list is immediately editable.
 */
function fabo_checklist_template(string $sheetKey, bool $includeHidden = false): array
{
    $rows = fabo_all('SELECT * FROM checklist_template WHERE sheet_key = ?
                      ORDER BY sort_order, id', 's', [$sheetKey]);
    if (!$rows) {
        foreach (fabo_checklist_defaults()[$sheetKey] ?? [] as $i => [$item, $req]) {
            fabo_exec('INSERT INTO checklist_template (sheet_key, item, required, sort_order, active)
                       VALUES (?,?,?,?,1)
                       ON DUPLICATE KEY UPDATE item = item', 'ssii',
                      [$sheetKey, $item, $req, ($i + 1) * 10]);
        }
        $rows = fabo_all('SELECT * FROM checklist_template WHERE sheet_key = ?
                          ORDER BY sort_order, id', 's', [$sheetKey]);
    }
    if (!$includeHidden) {
        $rows = array_values(array_filter($rows, static fn($r) => (int) $r['active'] === 1));
    }
    return $rows;
}

/** Just the item names a row may legitimately tick. */
function fabo_checklist_names(string $sheetKey): array
{
    return array_map(static fn($r) => $r['item'], fabo_checklist_template($sheetKey));
}

/* --------------------------------------------------- template editing */

function fabo_checklist_add(string $sheetKey, string $item, bool $required): array
{
    $item = trim($item);
    if ($item === '') {
        return [false, 'Type the item first.'];
    }
    if (fabo_one('SELECT id FROM checklist_template WHERE sheet_key = ? AND item = ?', 'ss', [$sheetKey, $item])) {
        return [false, '“' . $item . '” is already on this checklist.'];
    }
    $max = (int) (fabo_one('SELECT MAX(sort_order) AS m FROM checklist_template WHERE sheet_key = ?',
                           's', [$sheetKey])['m'] ?? 0);
    fabo_exec('INSERT INTO checklist_template (sheet_key, item, required, sort_order, active)
               VALUES (?,?,?,?,1)', 'ssii', [$sheetKey, mb_substr($item, 0, 190), $required ? 1 : 0, $max + 10]);
    return [true, 'Added.'];
}

function fabo_checklist_rename(string $sheetKey, int $id, string $item): array
{
    $item = trim($item);
    if ($item === '') {
        return [false, 'The item needs a name.'];
    }
    $cur = fabo_one('SELECT item FROM checklist_template WHERE id = ? AND sheet_key = ?', 'is', [$id, $sheetKey]);
    if (!$cur) {
        return [false, 'That item is not on this checklist.'];
    }
    if ($cur['item'] !== $item
        && fabo_one('SELECT id FROM checklist_template WHERE sheet_key = ? AND item = ?', 'ss', [$sheetKey, $item])) {
        return [false, '“' . $item . '” is already on this checklist.'];
    }
    fabo_exec('UPDATE checklist_template SET item = ? WHERE id = ?', 'si', [mb_substr($item, 0, 190), $id]);
    // Carry the ticks and notes over to the new name.
    fabo_exec('UPDATE IGNORE checklist_items SET item = ? WHERE sheet_key = ? AND item = ?',
              'sss', [$item, $sheetKey, $cur['item']]);
    return [true, 'Renamed. Ticks and descriptions moved with it.'];
}

function fabo_checklist_delete(string $sheetKey, int $id): array
{
    $cur = fabo_one('SELECT item FROM checklist_template WHERE id = ? AND sheet_key = ?', 'is', [$id, $sheetKey]);
    if (!$cur) {
        return [false, 'That item is not on this checklist.'];
    }
    fabo_exec('DELETE FROM checklist_template WHERE id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM checklist_items WHERE sheet_key = ? AND item = ?', 'ss', [$sheetKey, $cur['item']]);
    return [true, 'Removed from every row on this sheet.'];
}

function fabo_checklist_flag(string $sheetKey, int $id, string $field): array
{
    if (!in_array($field, ['required', 'active'], true)) {
        return [false, 'Unknown setting.'];
    }
    $n = fabo_exec('UPDATE checklist_template SET `' . $field . '` = 1 - `' . $field . '`
                    WHERE id = ? AND sheet_key = ?', 'is', [$id, $sheetKey]);
    return [true, 'Updated.'];
}

function fabo_checklist_move(string $sheetKey, int $id, string $dir): array
{
    $rows = fabo_checklist_template($sheetKey, true);
    foreach ($rows as $i => $r) {
        if ((int) $r['id'] !== $id) {
            continue;
        }
        $j = $dir === 'up' ? $i - 1 : $i + 1;
        if (!isset($rows[$j])) {
            return [true, ''];
        }
        foreach ($rows as $k => $rr) {          // normalise, then swap
            fabo_exec('UPDATE checklist_template SET sort_order = ? WHERE id = ?', 'ii',
                      [($k + 1) * 10, (int) $rr['id']]);
        }
        fabo_exec('UPDATE checklist_template SET sort_order = ? WHERE id = ?', 'ii', [($j + 1) * 10, $id]);
        fabo_exec('UPDATE checklist_template SET sort_order = ? WHERE id = ?', 'ii',
                  [($i + 1) * 10, (int) $rows[$j]['id']]);
        return [true, ''];
    }
    return [false, 'That item is not on this checklist.'];
}

/* ------------------------------------------------------------ per row */

/** Saved state for one row: item => ['done'=>bool, 'note'=>string, ...]. */
function fabo_checklist_state(string $sheetKey, int $rowId): array
{
    $out = [];
    foreach (fabo_all('SELECT item, done, note, updated_by, updated_at FROM checklist_items
                       WHERE sheet_key = ? AND row_id = ?', 'si', [$sheetKey, $rowId]) as $r) {
        $out[$r['item']] = [
            'done' => (int) $r['done'] === 1,
            'note' => (string) ($r['note'] ?? ''),
            'by'   => (string) ($r['updated_by'] ?? ''),
            'when' => $r['updated_at'] ? date('d M Y, H:i', strtotime($r['updated_at'])) : '',
        ];
    }
    return $out;
}

/**
 * Progress for many rows at once. Counts overall and mandatory separately,
 * because a row with every optional box ticked is still not ready to go.
 */
function fabo_checklist_progress(string $sheetKey, array $rowIds): array
{
    $tmpl = fabo_checklist_template($sheetKey);
    $total = count($tmpl);
    $must  = array_values(array_map(static fn($r) => $r['item'],
                array_filter($tmpl, static fn($r) => (int) $r['required'] === 1)));
    $names = array_map(static fn($r) => $r['item'], $tmpl);

    $out = [];
    foreach ($rowIds as $id) {
        $out[(int) $id] = ['done' => 0, 'total' => $total,
                           'must_done' => 0, 'must' => count($must), 'notes' => 0];
    }
    if (!$rowIds || $total === 0) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($rowIds), '?'));
    foreach (fabo_all('SELECT row_id, item, done, note FROM checklist_items
                       WHERE sheet_key = ? AND row_id IN (' . $in . ')',
                      's' . str_repeat('i', count($rowIds)),
                      array_merge([$sheetKey], array_map('intval', $rowIds))) as $r) {
        $id   = (int) $r['row_id'];
        $item = (string) $r['item'];
        if (!isset($out[$id]) || !in_array($item, $names, true)) {
            continue;                       // an item that has since been removed
        }
        if ((int) $r['done'] === 1) {
            $out[$id]['done']++;
            if (in_array($item, $must, true)) {
                $out[$id]['must_done']++;
            }
        }
        if (($r['note'] ?? '') !== '') {
            $out[$id]['notes']++;
        }
    }
    return $out;
}

function fabo_checklist_set(string $sheetKey, int $rowId, string $item, bool $done, string $who): void
{
    fabo_exec('INSERT INTO checklist_items (sheet_key, row_id, item, done, updated_by)
               VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE done = VALUES(done), updated_by = VALUES(updated_by)',
              'sisis', [$sheetKey, $rowId, $item, $done ? 1 : 0, $who]);
}

function fabo_checklist_note(string $sheetKey, int $rowId, string $item, string $note, string $who): void
{
    $note = trim($note);
    fabo_exec('INSERT INTO checklist_items (sheet_key, row_id, item, note, updated_by)
               VALUES (?,?,?,?,?)
               ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by)',
              'sisss', [$sheetKey, $rowId, $item, $note === '' ? null : $note, $who]);
}

/**
 * Tone for the grid cell. A row with a mandatory item still outstanding reads
 * amber however many optional boxes are ticked; red once nothing mandatory is done
 * but work has started elsewhere.
 */
function fabo_checklist_tone(array $p): string
{
    $total = (int) $p['total'];
    if ($total === 0) { return ''; }
    $done  = (int) $p['done'];
    $must  = (int) ($p['must'] ?? 0);
    $mdone = (int) ($p['must_done'] ?? 0);

    if ($done === 0)                 { return 'tone-muted'; }
    if ($must > 0 && $mdone < $must) { return $mdone === 0 ? 'tone-risk' : 'tone-warn'; }
    if ($done >= $total)             { return 'tone-good'; }
    return 'tone-info';               // everything mandatory done, some optional left
}
