<?php
/**
 * Computed columns. Nothing here is ever stored or accepted from input —
 * every value is derived at read time from the stored columns.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/approvals.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/checklist.php';
require_once __DIR__ . '/budget.php';

function fabo_today(): string
{
    return date('Y-m-d');
}

/* ---------------------------------------------------------------- dates */

function fabo_month_label(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    return $t ? date('M-Y', $t) : '';
}

function fabo_quarter_label(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    if (!$t) { return ''; }
    return 'Q' . (int) ceil((int) date('n', $t) / 3) . '-' . date('Y', $t);
}

function fabo_year_label(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    return $t ? date('Y', $t) : '';
}

function fabo_day_label(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    return $t ? date('D', $t) : '';
}

/**
 * Excel WEEKNUM(date, 1): weeks start Sunday, the week holding Jan 1 is week 1.
 */
function fabo_week_number(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    if (!$t) { return ''; }
    $jan1 = mktime(0, 0, 0, 1, 1, (int) date('Y', $t));
    $days = (int) floor(($t - $jan1) / 86400);
    $w1   = (int) date('w', $jan1) + 1;   // Sun=1 .. Sat=7
    return (string) ((int) floor(($days + $w1 - 1) / 7) + 1);
}

function fabo_is_closed(?string $status): bool
{
    return $status !== null && in_array($status, FABO_CLOSED_STATUSES, true);
}

function fabo_days_to_deadline(?string $due, ?string $status): string
{
    if (fabo_is_closed($status)) { return '-'; }
    if (!$due) { return ''; }
    $t = strtotime($due);
    if (!$t) { return ''; }
    $today = strtotime(fabo_today());
    return (string) ((int) round(($t - $today) / 86400));
}

function fabo_rag_status(?string $due, ?string $status): string
{
    if (fabo_is_closed($status)) { return FABO_RAG_DONE; }
    if (!$due) { return ''; }
    $t = strtotime($due);
    if (!$t) { return ''; }
    $today = strtotime(fabo_today());
    if ($today > $t) { return FABO_RAG_RED; }
    if (($t - $today) / 86400 <= FABO_RAG_AMBER_DAYS) { return FABO_RAG_AMBER; }
    return FABO_RAG_GREEN;
}

function fabo_upcoming_flag(?string $d): string
{
    if (!$d) { return ''; }
    $t = strtotime($d);
    if (!$t) { return ''; }
    $today = strtotime(fabo_today());
    if ($t <  $today)                { return 'Past'; }
    if ($t === $today)               { return 'Today'; }
    if ($t <= $today + 7  * 86400)   { return 'This Week'; }
    if ($t <= $today + 28 * 86400)   { return 'Next 4 Weeks'; }
    return 'Later';
}

/* ------------------------------------------------------------- division */

/** Every division guards against zero: returns null, never an error. */
function fabo_div($num, $den)
{
    $den = (float) $den;
    if ($den == 0.0) { return null; }
    return (float) $num / $den;
}

/** Spend-weighted blended ROI for a group. Never the average of row ROIs. */
function fabo_blended_roi($revenue_sum, $spend_sum)
{
    return fabo_div((float) $revenue_sum - (float) $spend_sum, $spend_sum);
}

/* ------------------------------------------------- linked-detail lookup */

/**
 * Index of every detail row the Master Log can resolve against, keyed by
 * its business ID. Built once per request.
 */
function fabo_link_index(): array
{
    static $idx = null;
    if ($idx !== null) { return $idx; }

    $idx = [];

    foreach (fabo_all('SELECT campaign_id, budget_allocated, budget_spent, leads_generated,
                              revenue_attributed FROM dm_campaigns') as $r) {
        if ($r['campaign_id'] === null || $r['campaign_id'] === '') { continue; }
        $idx['dm'][$r['campaign_id']] = [
            'budget_allocated'   => $r['budget_allocated'],
            'budget_spent'       => $r['budget_spent'],
            'leads_generated'    => $r['leads_generated'],
            'revenue_attributed' => $r['revenue_attributed'],
            'roi_pct'            => fabo_div((float) $r['revenue_attributed'] - (float) $r['budget_spent'],
                                             $r['budget_spent']),
        ];
    }

    foreach (fabo_all('SELECT influencer_id, agreed_budget, amount_paid, leads_clicks,
                              revenue_attributed FROM dm_influencers') as $r) {
        if ($r['influencer_id'] === null || $r['influencer_id'] === '') { continue; }
        $idx['dm'][$r['influencer_id']] = [
            'budget_allocated'   => $r['agreed_budget'],
            'budget_spent'       => $r['amount_paid'],
            'leads_generated'    => $r['leads_clicks'],
            'revenue_attributed' => $r['revenue_attributed'],
            'roi_pct'            => fabo_div((float) $r['revenue_attributed'] - (float) $r['amount_paid'],
                                             $r['amount_paid']),
        ];
    }

    // BTL carries no revenue column, so revenue and ROI stay blank there.
    foreach (fabo_all('SELECT btl_id, budget_allocated, budget_spent, leads_walkins
                       FROM btl_activities') as $r) {
        if ($r['btl_id'] === null || $r['btl_id'] === '') { continue; }
        $idx['btl'][$r['btl_id']] = [
            'budget_allocated'   => $r['budget_allocated'],
            'budget_spent'       => $r['budget_spent'],
            'leads_generated'    => $r['leads_walkins'],
            'revenue_attributed' => null,
            'roi_pct'            => null,
        ];
    }

    return $idx;
}

function fabo_resolve_link(?string $function, ?string $linkId): array
{
    $blank = ['budget_allocated' => null, 'budget_spent' => null, 'leads_generated' => null,
              'revenue_attributed' => null, 'roi_pct' => null];
    if (!$linkId || $function === null) { return $blank; }
    $where = fabo_link_functions()[$function] ?? null;
    if ($where === null) {
        return $blank;   // Content, Website and Design rows stay blank.
    }
    $idx = fabo_link_index();
    return $idx[$where][$linkId] ?? $blank;
}

/* --------------------------------------------------- store roll-up data */

/**
 * Source rows the Store Tracker matches its Match Keyword against.
 * Adding a store is adding a row with a distinct keyword — no code change.
 */
function fabo_store_sources(): array
{
    static $src = null;
    if ($src !== null) { return $src; }
    $src = [
        'btl'      => fabo_all('SELECT brand, store_location, budget_spent, leads_walkins
                                FROM btl_activities'),
        'design'   => fabo_all('SELECT brand, store_location FROM design_tracker'),
        'campaign' => fabo_all('SELECT brand, location, budget_spent, leads_generated
                                FROM dm_campaigns'),
    ];
    return $src;
}

function fabo_kw_hit(?string $haystack, string $needle): bool
{
    if ($needle === '' || $haystack === null || $haystack === '') { return false; }
    return stripos($haystack, $needle) !== false;
}

function fabo_store_rollup(array $store): array
{
    $kw    = trim((string) ($store['match_keyword'] ?? ''));
    $brand = (string) ($store['brand'] ?? '');
    $src   = fabo_store_sources();

    $btlCount = 0; $btlSpend = 0.0; $btlLeads = 0;
    foreach ($src['btl'] as $r) {
        if ($r['brand'] === $brand && fabo_kw_hit($r['store_location'], $kw)) {
            $btlCount++;
            $btlSpend += (float) $r['budget_spent'];
            $btlLeads += (int)   $r['leads_walkins'];
        }
    }
    $designCount = 0;
    foreach ($src['design'] as $r) {
        if ($r['brand'] === $brand && fabo_kw_hit($r['store_location'], $kw)) {
            $designCount++;
        }
    }
    $dmSpend = 0.0; $dmLeads = 0;
    foreach ($src['campaign'] as $r) {
        if ($r['brand'] === $brand && fabo_kw_hit($r['location'], $kw)) {
            $dmSpend += (float) $r['budget_spent'];
            $dmLeads += (int)   $r['leads_generated'];
        }
    }

    return [
        'btl_activities' => $btlCount,
        'btl_spend'      => $btlSpend,
        'btl_leads'      => $btlLeads,
        'design_assets'  => $designCount,
        'dm_spend'       => $dmSpend,
        'dm_leads'       => $dmLeads,
        'total_spend'    => $btlSpend + $dmSpend,
        'total_leads'    => $btlLeads + $dmLeads,
    ];
}

/* ------------------------------------------------------- the entry point */

/**
 * Returns the row with every computed column filled in. Any value the
 * caller supplied for a computed field is discarded.
 */
function fabo_compute(string $key, array $row): array
{
    $sheet = fabo_sheet($key);
    if (!$sheet) { return $row; }

    $driver = $sheet['date_driver'] ? ($row[$sheet['date_driver']] ?? null) : null;

    if ($key === 'master') {
        $row = array_merge($row, fabo_resolve_link($row['function_name'] ?? null,
                                                   $row['linked_detail_id'] ?? null));
    }
    if ($key === 'store') {
        $row = array_merge($row, fabo_store_rollup($row));
    }

    foreach ($sheet['columns'] as $c) {
        if ($c[2] !== 'computed') { continue; }
        $calc = $c[3]['calc'] ?? '';
        switch ($calc) {
            case 'department':
                // Which department sent this row in, if any. Looked up by
                // name so the grid reads as words rather than an id.
                $row[$c[1]] = fabo_department_name($row['department_id'] ?? null);
                break;
            case 'month':   $row[$c[1]] = fabo_month_label($driver);   break;
            case 'quarter': $row[$c[1]] = fabo_quarter_label($driver); break;
            case 'week':    $row[$c[1]] = fabo_week_number($driver);   break;
            case 'year':    $row[$c[1]] = fabo_year_label($driver);    break;
            case 'day':     $row[$c[1]] = fabo_day_label($driver);     break;
            case 'upcoming':$row[$c[1]] = fabo_upcoming_flag($driver); break;
            case 'days':
                $row[$c[1]] = fabo_days_to_deadline($driver, $row['status'] ?? null);
                break;
            case 'days_plain':
                $row[$c[1]] = fabo_days_to_deadline($driver, null);
                break;
            case 'rag':
                $row[$c[1]] = fabo_rag_status($driver, $row['status'] ?? null);
                break;
            case 'content_er':
                $row[$c[1]] = fabo_div((int) ($row['likes'] ?? 0)
                                     + (int) ($row['comments_count'] ?? 0)
                                     + (int) ($row['shares'] ?? 0), $row['reach_views'] ?? 0);
                break;
            case 'budget_remaining':
                $row[$c[1]] = ($row['budget_allocated'] === null && $row['budget_spent'] === null)
                    ? null
                    : (float) $row['budget_allocated'] - (float) $row['budget_spent'];
                break;
            case 'cpl':
                $row[$c[1]] = fabo_div($row['budget_spent'] ?? 0, $row['leads_generated'] ?? 0);
                break;
            case 'roi_spent':
                $row[$c[1]] = fabo_div((float) ($row['revenue_attributed'] ?? 0)
                                     - (float) ($row['budget_spent'] ?? 0), $row['budget_spent'] ?? 0);
                break;
            case 'roi_paid':
                $row[$c[1]] = fabo_div((float) ($row['revenue_attributed'] ?? 0)
                                     - (float) ($row['amount_paid'] ?? 0), $row['amount_paid'] ?? 0);
                break;
            case 'ctr':
                $row[$c[1]] = fabo_div($row['clicks'] ?? 0, $row['impressions'] ?? 0);
                break;
            case 'assets':
            case 'approval':
            case 'committee':
            case 'checklist':
            case 'budget':
            case 'results':
                // Filled for the whole sheet by fabo_fill_assets_approval() (results: fabo_evres_fill()).
                $row[$c[1]] = $row[$c[1]] ?? '';
                break;
            case 'score':
                // Relative to the whole sheet: fabo_compute_all() fills it.
                $row[$c[1]] = $row[$c[1]] ?? '';
                break;
            // link_* and store_roll are filled above.
        }
    }
    return $row;
}

function fabo_compute_all(string $key, array $rows): array
{
    foreach ($rows as $i => $r) {
        $rows[$i] = fabo_compute($key, $r);
    }
    if ($key === 'campaigns') {
        $rows = fabo_score_campaigns($rows);
    }
    $rows = fabo_fill_assets_approval($key, $rows);
    return $rows;
}

/**
 * Fills the Assets count and the Approval columns for a sheet that has them.
 * Both are real, live values: file counts from `attachments`, decisions from
 * `approvals`. Nothing is stored on the sheet row itself.
 */
function fabo_fill_assets_approval(string $key, array $rows): array
{
    if ($key === 'events' && $rows) {
        /* Where each event's results stand (the Results column), for the
           grid, the views and the shared sheet alike -- and after a save,
           which refreshes the row through here. See lib/evresults.php. */
        require_once __DIR__ . '/evresults.php';
        $rows = fabo_evres_fill($rows);
    }
    $sheet = fabo_sheet($key);
    if (!$sheet || !$rows) {
        return $rows;
    }
    $wantsAssets    = false;
    $wantsApproval  = false;
    $wantsChecklist = false;
    $wantsBudget    = false;
    $wantsCommittee = false;
    foreach ($sheet['columns'] as $c) {
        $calc = $c[3]['calc'] ?? '';
        if ($calc === 'committee') { $wantsCommittee = true; }
        if ($calc === 'assets')    { $wantsAssets = true; }
        if ($calc === 'approval')  { $wantsApproval = true; }
        if ($calc === 'checklist') { $wantsChecklist = true; }
        if ($calc === 'budget')    { $wantsBudget = true; }
    }
    /* The paperclip on every row shows how many files it has, whether or not
       the sheet also has a column counting them. */
    if (!empty($sheet['assets_label'])) {
        $wantsAssets = true;
    }
    if (!$wantsAssets && !$wantsApproval && !$wantsChecklist && !$wantsBudget && !$wantsCommittee) {
        return $rows;
    }
    $ids    = array_map(static fn($r) => (int) $r['id'], $rows);
    $counts = $wantsAssets    ? fabo_attachment_counts($key, $ids) : [];
    $appr   = $wantsApproval  ? fabo_approvals_for($key, $ids)     : [];
    $reqs   = $wantsApproval  ? fabo_requests_for_rows($key, $ids)  : [];
    $check  = $wantsChecklist ? fabo_checklist_progress($key, $ids): [];
    /* One query for the whole column rather than one per row: a sheet of two
       hundred events would otherwise ask two hundred times for one cell. */
    $budget = $wantsBudget ? fabo_budget_progress($key, $ids, fabo_view_owner()) : [];
    $cmte   = [];
    if ($wantsCommittee) {
        require_once __DIR__ . '/committees.php';
        $cmte = fabo_cm_status_for($ids);
    }

    foreach ($rows as $i => $r) {
        $id = (int) $r['id'];
        if ($wantsAssets) {
            $rows[$i]['asset_count'] = $counts[$id] ?? 0;
        }
        if ($wantsApproval) {
            $a = $appr[$id] ?? null;
            $state = $a['state'] ?? 'pending';
            /* A completed event is an approved one. Only while nobody has
               decided otherwise: a recorded rejection still reads Rejected. */
            if ($key === 'events' && $state === 'pending'
                && fabo_event_is_complete($r['status'] ?? null)) {
                $state = 'approved';
            }
            $rows[$i]['approval_state']   = fabo_approval_label($state);
            $rows[$i]['approval_remarks'] = $a['remarks'] ?? '';

            // Who this row was sent to, and who has answered. A tick reads at a
            // glance; the name keeps it unambiguous once several are involved.
            $asked = $reqs[$id] ?? [];
            $names = [];
            foreach ($asked as $p) {
                $names[] = $p['name'] . ($p['answered'] ? " \u{2713}" : '');
            }
            $rows[$i]['approval_requested'] = implode(', ', $names);

            $rows[$i]['_tone'] = $rows[$i]['_tone'] ?? [];
            $rows[$i]['_tone']['approval_state'] = 'tone-' . fabo_approval_tone($state);
            if ($asked) {
                $waiting = count(array_filter($asked, static fn($p) => !$p['answered']));
                $rows[$i]['_tone']['approval_requested'] = $waiting ? 'tone-warn' : 'tone-good';
            }
            if ($state === 'rejected') {
                $rows[$i]['_tone']['approval_remarks'] = 'tone-risk';
            }
        }
        if ($wantsCommittee) {
            /* Where the committee stands, at a glance: decided or not, and
               how many of its members have answered. */
            $s = $cmte[$id] ?? null;
            $rows[$i]['committee_state'] = $s ? $s['label'] : '';
            $rows[$i]['_committee'] = $s;
            if ($s) {
                $rows[$i]['_tone'] = $rows[$i]['_tone'] ?? [];
                $rows[$i]['_tone']['committee_state'] = $s['tone'];
            }
        }
        if ($wantsBudget) {
            $p = $budget[$id] ?? ['total' => 0.0, 'over' => 0, 'filled' => 0, 'files' => 0];
            /* The total asked for, and nothing about what was allowed. A team
               member reads this cell, so the allocation must not be in it —
               only the figure they themselves entered, and its colour. */
            $rows[$i]['budget_required'] = $p['filled'] ? (float) $p['total'] : '';
            $rows[$i]['_budget'] = $p;
            $rows[$i]['_tone'] = $rows[$i]['_tone'] ?? [];
            $t = fabo_budget_tone($p);
            if ($t !== '') {
                $rows[$i]['_tone']['budget_required'] = 'tone-' . $t;
            }
        }
        if ($wantsChecklist) {
            $p = $check[$id] ?? ['done' => 0, 'total' => 0, 'notes' => 0, 'must' => 0, 'must_done' => 0];
            $rows[$i]['checklist'] = $p['total'] ? $p['done'] . '/' . $p['total'] : '';
            $rows[$i]['_checklist'] = $p;
            $rows[$i]['_tone'] = $rows[$i]['_tone'] ?? [];
            $t = fabo_checklist_tone($p);
            if ($t !== '') {
                $rows[$i]['_tone']['checklist'] = $t;
            }
        }
    }
    return $rows;
}

/**
 * DM Campaigns performance score. Ranks every campaign on each metric,
 * awards quartile points, sums to 0-100, grades it, and records a tone per
 * metric cell (row['_tone']) so the grid colours leads / CPL / conversions /
 * ROI relative to the other campaigns. A row lacking a metric earns 0 for it.
 */
function fabo_score_campaigns(array $rows): array
{
    $metrics = fabo_score_metrics();
    foreach ($rows as $i => $r) {
        $rows[$i]['_score'] = 0;
        $rows[$i]['_tone']  = $rows[$i]['_tone'] ?? [];
    }
    foreach ($metrics as $field => $higherBetter) {
        $vals = [];
        foreach ($rows as $i => $r) {
            $v = $r[$field] ?? null;
            if ($v !== null && $v !== '') { $vals[$i] = (float) $v; }
        }
        if (!$vals) { continue; }
        $m = count($vals);
        foreach ($vals as $i => $v) {
            // Position = how many campaigns are strictly better, so ties share a quartile.
            $pos = 0;
            foreach ($vals as $w) {
                if ($higherBetter ? $w > $v : $w < $v) { $pos++; }
            }
            $q = (int) floor($pos * 4 / $m);          // 0..3
            $rows[$i]['_score'] += FABO_SCORE_QUARTILE_POINTS[$q];
            $rows[$i]['_tone'][$field] = 'tone-' . FABO_QUARTILE_TONES[$q];
        }
    }
    foreach ($rows as $i => $r) {
        $hasAny = false;
        foreach ($metrics as $field => $_) {
            if (($r[$field] ?? null) !== null && $r[$field] !== '') { $hasAny = true; break; }
        }
        if (!$hasAny) {
            $rows[$i]['performance_score'] = '';
            continue;
        }
        $score = (int) $r['_score'];
        foreach (fabo_score_grades() as [$min, $grade, $tone]) {
            if ($score >= $min) {
                $rows[$i]['performance_score'] = $grade . ' - ' . $score;
                $rows[$i]['_tone']['performance_score'] = 'tone-' . $tone;
                break;
            }
        }
    }
    return $rows;
}

/** Score one campaign row in the context of all campaigns (used after a save). */
function fabo_score_row(array $row): array
{
    $all = fabo_compute_all('campaigns', fabo_all('SELECT * FROM dm_campaigns'));
    foreach ($all as $r) {
        if ((int) $r['id'] === (int) $row['id']) { return $r; }
    }
    return $row;
}

/** Re-fills assets + approval for one row (used after a save). */
function fabo_refresh_row(string $key, array $row): array
{
    $out = fabo_fill_assets_approval($key, [$row]);
    return $out[0] ?? $row;
}

/**
 * A department's name from its id, cached for the length of the request.
 *
 * A grid asks this once per row, and the answer is the same for every row a
 * department contributed, so the lookup happens once per department however
 * many rows are on screen.
 */
function fabo_department_name($id): string
{
    static $names = null;
    $id = (int) $id;
    if ($id <= 0) {
        return '';
    }
    if ($names === null) {
        $names = [];
        try {
            foreach (fabo_all('SELECT id, name FROM event_departments') as $d) {
                $names[(int) $d['id']] = (string) $d['name'];
            }
        } catch (Throwable $e) {
            $names = [];          // the table is not there yet
        }
    }
    return $names[$id] ?? '';
}
