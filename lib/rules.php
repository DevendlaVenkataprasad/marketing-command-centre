<?php
/**
 * Two different kinds of "constant" live here, kept apart on purpose.
 *
 * 1. DATA — brands, business units, store types, states. These are read from
 *    the Lists sheet at runtime. Adding a brand or a state is a row in Lists;
 *    the dashboard, the store roll-ups, the exports and the client scope
 *    dropdowns all pick it up with no code change.
 *
 * 2. THE WORKBOOK'S RULES — which statuses close a task, which Functions carry
 *    a linked detail, which rows count towards launch readiness. These are the
 *    formulas the workbook itself encodes, not configuration, so they are
 *    declared once here rather than scattered as magic strings.
 */

require_once __DIR__ . '/lists.php';

/**
 * The validated categorical palette. Slot ORDER is the colourblind-safety
 * mechanism, not decoration — re-validate before re-ordering.
 */
const FABO_SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100',
                     '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
const FABO_BLUE   = '#2a78d6';   // slot 1
const FABO_ORANGE = '#eb6834';   // slot 2
const FABO_AQUA   = '#1baf7a';   // slot 3
const FABO_NEUTRAL = '#898781';

/* =====================================================================
   1. DATA — driven by the Lists sheet
   ===================================================================== */

/** Values that mean "this row belongs to every brand / unit", not to one. */
const FABO_SCOPE_ANY = ['Both', 'All', ''];

/**
 * The brands the portal reports on, in Lists order.
 * 'Both' is a row marker, never a brand of its own, so it is excluded.
 */
function fabo_brands(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $b = array_values(array_filter(
        fabo_list_values('Brand'),
        static fn($v) => !in_array($v, FABO_SCOPE_ANY, true)
    ));
    if (!$b) {
        // Lists is empty: fall back to whatever brands the data actually uses.
        $b = array_column(
            fabo_all("SELECT DISTINCT brand FROM master_log
                      WHERE brand IS NOT NULL AND brand <> '' AND brand <> 'Both'
                      ORDER BY brand"),
            'brand'
        );
    }
    return $b;
}

/** The business units, same treatment. */
function fabo_business_units(): array
{
    return array_values(array_filter(
        fabo_list_values('Business Unit'),
        static fn($v) => !in_array($v, FABO_SCOPE_ANY, true)
    ));
}

function fabo_store_types(): array
{
    return fabo_list_values('Store Type');
}

function fabo_states(): array
{
    return fabo_list_values('State');
}

/**
 * A brand's chart colour: categorical slots in their validated fixed order,
 * assigned by position and never cycled. Past the last slot a brand takes the
 * neutral ink rather than a repeated hue.
 */
function fabo_brand_color(string $brand): string
{
    $i = array_search($brand, fabo_brands(), true);
    if ($i === false || $i >= count(FABO_SERIES)) {
        return FABO_NEUTRAL;
    }
    return FABO_SERIES[$i];
}

/* =====================================================================
   2. THE WORKBOOK'S RULES
   ===================================================================== */

/** A task in one of these is finished: Days to Deadline shows '-', RAG 'Done'.
    Delivered and Rejected are how a requisition closes (fabo_req_closed_statuses). */
const FABO_CLOSED_STATUSES = ['Completed', 'Cancelled', 'Delivered', 'Rejected'];

/** The status the dashboard's "Completed" tile and columns count. */
const FABO_STATUS_COMPLETED = 'Completed';

/** The status the dashboard's "In Progress" tile counts. */
const FABO_STATUS_IN_PROGRESS = 'In Progress';

/** The status treated as "not begun" when scoring launch readiness. */
const FABO_STATUS_NOT_STARTED = 'Not Started';

/** RAG labels, exactly as the workbook writes them. */
const FABO_RAG_RED   = 'Red - Overdue';
const FABO_RAG_AMBER = 'Amber - Due Soon';
const FABO_RAG_GREEN = 'Green - On Track';
const FABO_RAG_DONE  = 'Done';

/** Amber window: due within this many days counts as due soon. */
const FABO_RAG_AMBER_DAYS = 3;

/**
 * Master Log Functions that carry a Linked Detail ID, and where it resolves.
 * Anything not listed here (Content, Website, Design) stays blank, as in the
 * workbook's INDEX/MATCH.
 */
function fabo_link_functions(): array
{
    return [
        'Digital Marketing' => 'dm',    // DM Campaigns, then DM Influencers
        'BTL Activities'    => 'btl',   // BTL Activities
    ];
}

/**
 * The three things the workbook checks before a new store opens. Each is
 * [source sheet, the column that classifies the row, the value to match,
 *  the free-text column the store's Match Keyword is searched in].
 */
function fabo_readiness_checks(): array
{
    return [
        'Store Design Status' =>
            ['design',    'design_type',   'Store Design',                 'store_location'],
        'Launch BTL Activity Status' =>
            ['btl',       'activity_type', 'On-Ground Promo - New Store',  'store_location'],
        'Pre-Launch Awareness Campaign' =>
            ['campaigns', 'objective',     'Awareness',                    'location'],
    ];
}

/** Readiness verdicts. All three moving = On Track; any not begun = At Risk. */
const FABO_READY_ON_TRACK    = 'On Track';
const FABO_READY_AT_RISK     = 'At Risk';
const FABO_READY_IN_PROGRESS = 'In Progress';

/* =====================================================================
   3. SEMANTIC COLOUR TONES
   A value maps to a tone, never to a raw colour, so the whole portal
   colours the same meaning the same way. Tones: good, info, warn, risk,
   muted, neutral. Unknown values fall back to neutral.
   ===================================================================== */

function fabo_tone_map(): array
{
    return [
        'status' => [
            'Not Started'    => 'muted',
            'In Progress'    => 'info',
            'In Review'      => 'info',
            'Approved'       => 'good',
            'Published/Live' => 'good',
            'Completed'      => 'good',
            'On Hold'        => 'warn',
            'Delayed'        => 'risk',
            'Cancelled'      => 'muted',
            // Store Tracker statuses
            'Live'           => 'good',
            'Launching Soon' => 'info',
            'Closed'         => 'muted',
            // Social Media Calendar content status
            'Not Planned'    => 'muted',
            'Planned'        => 'info',
        ],
        'priority' => [
            'Urgent' => 'risk',
            'High'   => 'warn',
            'Medium' => 'info',
            'Low'    => 'muted',
        ],
        'rag' => [
            FABO_RAG_RED   => 'risk',
            FABO_RAG_AMBER => 'warn',
            FABO_RAG_GREEN => 'good',
            FABO_RAG_DONE  => 'muted',
        ],
        'readiness' => [
            FABO_READY_ON_TRACK    => 'good',
            FABO_READY_AT_RISK     => 'risk',
            FABO_READY_IN_PROGRESS => 'warn',
        ],
        'upcoming' => [
            'Past'         => 'muted',
            'Today'        => 'risk',
            'This Week'    => 'warn',
            'Next 4 Weeks' => 'info',
            'Later'        => 'neutral',
        ],
    ];
}

/** Which tone family a grid field belongs to. */
function fabo_tone_family(string $field): ?string
{
    switch ($field) {
        case 'status':
        case 'content_status':  return 'status';
        case 'priority':        return 'priority';
        case 'rag_status':      return 'rag';
        case 'upcoming_flag':   return 'upcoming';
        default:                return null;
    }
}

function fabo_tone(string $family, ?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return fabo_tone_map()[$family][$value] ?? 'neutral';
}

/** Tone for a Days-to-Deadline value: overdue is risk, imminent is warn. */
function fabo_days_tone($days): string
{
    if ($days === '' || $days === null || $days === '-') {
        return '';
    }
    $d = (int) $days;
    if ($d < 0) { return 'risk'; }
    if ($d <= FABO_RAG_AMBER_DAYS) { return 'warn'; }
    return '';
}

/* =====================================================================
   4. CAMPAIGN PERFORMANCE SCORING (DM Campaigns)
   Each of four metrics is ranked against every other campaign that has
   the metric; quartile position earns points. Total 0-100 becomes a grade.
   ===================================================================== */

/** field => higher-is-better. Four metrics, 25 points each. */
function fabo_score_metrics(): array
{
    return [
        'leads_generated' => true,
        'cost_per_lead'   => false,   // lower is better
        'conversions'     => true,
        'roi_pct'         => true,
    ];
}

/** Points by quartile, top first. */
const FABO_SCORE_QUARTILE_POINTS = [25, 18, 12, 6];

/** Grade thresholds on the 0-100 total, top first: [min, grade, tone]. */
function fabo_score_grades(): array
{
    return [
        [80, 'A', 'good'],
        [60, 'B', 'info'],
        [40, 'C', 'warn'],
        [0,  'D', 'risk'],
    ];
}

/** Tone for a metric's quartile position (0 = top). */
const FABO_QUARTILE_TONES = ['good', 'info', 'warn', 'risk'];
