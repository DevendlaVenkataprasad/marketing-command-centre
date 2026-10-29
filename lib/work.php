<?php
/**
 * Work items. A "work item" is any row on any sheet that names an owner.
 * Team members are matched to rows through their Owner / Team value, so
 * assigning work is simply setting a row's Owner — no second bookkeeping.
 */

require_once __DIR__ . '/grid.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/brand.php';

/**
 * Where each sheet keeps its owner, due date, completion date and title.
 * A sheet without a completion date is "done" by status alone.
 */
function fabo_work_sources(): array
{
    /*
     * The Master Activity Log is deliberately NOT here.
     *
     * It used to be, and that was right while it was a sheet somebody typed
     * into. It is now written from every sheet below, so counting it as well
     * counted all of this twice — a person with four jobs showed eight, and
     * the balance between people was meaningless.
     *
     * The detail sheets are the source. They are also where a person's work
     * actually is, so an item here links to the row rather than to a line
     * about the row.
     */
    return [
        'content'      => ['owner',       'planned_publish_date', 'actual_publish_date', 'title_topic'],
        'website'      => ['owner',       'due_date',             'completion_date',     'task_page'],
        'design'       => ['designer',    'due_date',             'completion_date',     'project_title'],
        'campaigns'    => ['owner',       'end_date',             null,                  'campaign_name'],
        'influencers'  => ['owner',       'content_due_date',     'content_live_date',   'influencer_name'],
        'btl'          => ['coordinator', 'activity_date',        null,                  'title_description'],
        'events'       => ['owner',       'start_date',           null,                  'event_name'],
        /* Requisitions name an owner and have a promised date, so they are
           work somebody is carrying like any other. They were simply never
           added. */
        'requisitions' => ['owner',       'promised_due',         'delivered_on',        'event_name'],

        /* These three carry no owner column at all, so nothing on them can be
           assigned to anybody — which is why they were left out, and why a
           person who works mainly here looked idle. They are counted now, on
           the strength of who actually entered each row. A null owner column
           means exactly that: nobody is named, so ask the audit instead. */
        'calendar'     => [null,          'occasion_date',        null,                  'occasion'],
        'webcontent'   => [null,          'scheduled_date',       'published_at',        'content_topic'],
        'store'        => [null,          'opening_date',         null,                  'store_name'],
    ];
}

/**
 * Every work item visible to $viewer, optionally only those owned by $owner.
 * Each item: sheet, key, id, biz_id, title, owner, status, due, done, closed,
 * overdue, due_soon, tone.
 */
function fabo_work_items(array $viewer, ?string $owner = null): array
{
    $today   = fabo_today();
    $weekEnd = date('Y-m-d', strtotime($today . ' +7 days'));
    $items   = [];

    foreach (fabo_work_sources() as $key => [$ownerF, $dueF, $doneF, $titleF]) {
        if (fabo_permission($viewer, $key) === 'none') {
            continue;
        }
        $sheet = fabo_sheet($key);
        foreach (fabo_rows($key, $viewer) as $r) {
            /* No owner column on this sheet: the row belongs to whoever typed
               it, which fabo_work_for_member() works out from the audit. */
            $o = $ownerF === null ? '' : trim((string) ($r[$ownerF] ?? ''));
            if ($owner !== null && $o !== $owner) {
                continue;
            }
            $status = (string) ($r['status'] ?? '');
            $due    = $r[$dueF] ?? null;
            $done   = $doneF ? ($r[$doneF] ?? null) : null;
            $closed = fabo_is_closed($status) || ($doneF && $done);
            $items[] = [
                'sheet'    => $sheet['label'],
                'key'      => $key,
                'id'       => (int) $r['id'],
                'biz_id'   => $sheet['id_field'] ? (string) ($r[$sheet['id_field']] ?? '') : '',
                'title'    => (string) ($r[$titleF] ?? ''),
                'owner'    => $o,
                'status'   => $status,
                'due'      => $due,
                'done'     => $done,
                'closed'   => $closed,
                'overdue'  => !$closed && $due && $due < $today,
                'due_soon' => !$closed && $due && $due >= $today && $due <= $weekEnd,
                'brand'    => (string) ($r['brand'] ?? ''),
            ];
        }
    }
    usort($items, static function ($a, $b) {
        if ($a['closed'] !== $b['closed']) { return $a['closed'] ? 1 : -1; }
        return strcmp((string) $a['due'], (string) $b['due']);
    });
    return $items;
}

/**
 * What somebody's open work is, by the sheet it sits on.
 *
 * The counts alone say how much a person is carrying but not what any of it
 * is, which is the question actually asked of a team list: four open jobs
 * could be four campaigns or four posters, and those are different weeks.
 *
 * Returns [sheet label => open items], heaviest first. Closed work is left
 * out — it is what somebody is carrying now that matters here.
 */
function fabo_work_by_sheet(array $items): array
{
    $out = [];
    foreach ($items as $i) {
        if (!empty($i['closed'])) {
            continue;
        }
        $label = (string) ($i['sheet'] ?? '');
        if ($label === '') {
            continue;
        }
        $out[$label] = ($out[$label] ?? 0) + 1;
    }
    arsort($out);
    return $out;
}

/**
 * The rows one person entered, as "table:id" keys.
 *
 * Read from the audit, which records who created each row. This is what makes
 * work on a sheet with no owner column attributable at all, and what stops a
 * person who fills sheets in daily from reading as idle because nobody went
 * back and typed their name into an Owner cell.
 */
function fabo_work_rows_entered(int $memberId): array
{
    static $cache = [];
    if ($memberId <= 0) {
        return [];
    }
    if (isset($cache[$memberId])) {
        return $cache[$memberId];
    }
    $out = [];
    try {
        foreach (fabo_all(
            'SELECT DISTINCT table_name, row_id FROM activity_audit
              WHERE client_id = ? AND field = "(row)" AND new_value = "added"',
            'i', [$memberId]) as $r) {
            $out[$r['table_name'] . ':' . (int) $r['row_id']] = true;
        }
    } catch (Throwable $e) {
        // No audit table yet. Nothing is attributable that way, which is how
        // it used to be.
    }
    return $cache[$memberId] = $out;
}

/**
 * One person's work: what was assigned to them, and what they actually did.
 *
 * Assignment alone was too narrow. A row counts as somebody's if their Owner
 * value is on it OR if they are the one who entered it — filling a row in is
 * the work, and labelling it afterwards is bookkeeping that does not happen.
 *
 * $items is the whole list, read once by the caller, because working it out
 * per person would walk every sheet again for each of them.
 */
function fabo_work_for_member(array $items, array $member): array
{
    $owner = trim((string) ($member['owner_name'] ?? ''));
    $mine  = fabo_work_rows_entered((int) ($member['id'] ?? 0));

    return array_values(array_filter($items, static function ($i) use ($owner, $mine) {
        if ($owner !== '' && (string) ($i['owner'] ?? '') === $owner) {
            return true;
        }
        $sheet = fabo_sheet((string) ($i['key'] ?? ''));
        $table = (string) ($sheet['table'] ?? '');
        return $table !== '' && isset($mine[$table . ':' . (int) ($i['id'] ?? 0)]);
    }));
}

/**
 * What a team member has actually done in the portal, and how recently.
 *
 * Work assigned to somebody says what they are meant to be doing. This says
 * what they have been doing — rows started, cells changed, which sheets they
 * touched and when they were last here. A member with ten jobs and no edits
 * in a month is a different conversation from one with ten jobs and daily
 * activity, and the team list should not make them look the same.
 *
 * Read from the audit trail, which records the id of whoever made each
 * change. Admin edits carry no id, so they are not attributed to anybody.
 */
function fabo_team_activity(int $memberId): array
{
    $blank = ['edits' => 0, 'rows' => 0, 'sheets' => 0, 'last' => null,
              'days' => null, 'state' => 'never'];
    if ($memberId <= 0) {
        return $blank;
    }
    try {
        $r = fabo_one(
            'SELECT COUNT(*) edits,
                    SUM(field = "(row)" AND new_value = "added") rows_added,
                    COUNT(DISTINCT table_name) sheets,
                    MAX(changed_at) last
               FROM activity_audit WHERE client_id = ?', 'i', [$memberId]);
    } catch (Throwable $e) {
        return $blank;
    }
    if (!$r || (int) $r['edits'] === 0) {
        return $blank;
    }
    $last = (string) ($r['last'] ?? '');
    $days = $last !== '' ? (int) floor((time() - strtotime($last)) / 86400) : null;

    /* Three states rather than a date alone: a date makes the reader do the
       arithmetic, and the question being asked is simply whether somebody is
       still working. */
    $state = 'idle';
    if ($days === null)      { $state = 'never'; }
    elseif ($days <= 7)      { $state = 'active'; }
    elseif ($days <= 30)     { $state = 'quiet'; }

    return [
        'edits'  => (int) $r['edits'],
        'rows'   => (int) ($r['rows_added'] ?? 0),
        'sheets' => (int) $r['sheets'],
        'last'   => $last !== '' ? $last : null,
        'days'   => $days,
        'state'  => $state,
    ];
}

/** How that state should read, and in what colour. */
function fabo_team_activity_words(array $a): array
{
    switch ($a['state']) {
        case 'active': return ['working', 'good'];
        case 'quiet':  return ['gone quiet', 'warn'];
        case 'idle':   return ['not lately', 'risk'];
        default:       return ['never', 'mute'];
    }
}

/** Counts for a set of items. */
function fabo_work_summary(array $items): array
{
    $s = ['assigned' => count($items), 'completed' => 0, 'open' => 0, 'overdue' => 0, 'due_soon' => 0, 'sheets' => []];
    foreach ($items as $it) {
        if ($it['closed']) { $s['completed']++; } else { $s['open']++; }
        if ($it['overdue'])  { $s['overdue']++; }
        if ($it['due_soon']) { $s['due_soon']++; }
        $s['sheets'][$it['key']] = ($s['sheets'][$it['key']] ?? 0) + 1;
    }
    $s['pct_done'] = $s['assigned'] ? (int) round($s['completed'] / $s['assigned'] * 100) : 0;
    return $s;
}

/** Team members (kind = team), with their sheet access. */
/**
 * Our own team.
 *
 * A client's staff are team members too, but they are that client's people
 * and belong on that client's desk — parent_id is what tells the two apart.
 * Without this the admin Team page listed every client's staff alongside
 * ours, which is both confusing and none of our business to show.
 *
 * Pass a client id to get THAT organisation's staff instead.
 */
function fabo_team_members(?int $ofClient = null): array
{
    $rows = $ofClient === null
        ? fabo_all("SELECT * FROM clients WHERE kind = 'team' AND parent_id IS NULL
                    ORDER BY name")
        : fabo_all("SELECT * FROM clients WHERE kind = 'team' AND parent_id = ?
                    ORDER BY name", 'i', [$ofClient]);

    $out = [];
    foreach ($rows as $m) {
        $m['access'] = fabo_client_access((int) $m['id']);
        $out[] = $m;
    }
    return $out;
}

/** Prefilled message for sending someone their link. */
function fabo_link_message(array $person, string $url): array
{
    $isTeam  = ($person['kind'] ?? 'client') === 'team';
    $brand   = fabo_owner_brand($person);
    $subject = $isTeam ? 'Your ' . $brand . ' work portal link'
                       : 'Your ' . $brand . ' marketing reporting link';
    $body    = "Hi " . $person['name'] . ",\n\n"
             . ($isTeam
                ? "Here is your personal link to the marketing portal. Open it to see the sheets assigned to you and your work list:\n"
                : "Here is your personal link to the marketing reporting portal. It shows the sheets shared with you:\n")
             . $url . "\n\n"
             . "Keep this link private — it is your access.\n"
             . (!empty($person['expires_at'])
                ? "It is valid until " . date('d M Y', strtotime($person['expires_at'])) . ".\n" : '')
             . fabo_sign_off($brand);
    return [$subject, $body];
}
