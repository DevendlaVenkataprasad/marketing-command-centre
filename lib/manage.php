<?php
/**
 * Who may run a management desk, and whose things they are running.
 *
 * Sheet Management and Requisition Forms were ours alone. A client who runs
 * their own organisation needs the same two desks for their own sheets —
 * their column names, their dropdowns, their turnaround times — without ever
 * reaching ours or another client's.
 *
 * One idea makes that safe, and it is the same one the grid already uses:
 * a SCOPE. The administrator works at scope 0, the shared set that every
 * client starts from. A client works at the id of their own organisation,
 * and every read and write on these desks carries that number. Nothing else
 * about the pages differs, which is why they are the same pages rather than
 * a second copy that drifts.
 *
 * See fabo_customiser() in lib/lists.php, which answers the same question
 * for dropdown values and returns the same number.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lists.php';

/** The rights these desks are granted by, and what each one opens. */
function fabo_manage_desks(): array
{
    return [
        'sheets' => [
            'can_manage_sheets',
            'Sheet Management',
            'sheets.php',
        ],
        'forms' => [
            'can_manage_forms',
            'Requisition Forms',
            'forms.php',
        ],
        'intake' => [
            'can_manage_intake',
            'Intake Forms',
            'intake.php',
        ],
    ];
}

/**
 * May this viewer run this desk?
 *
 * A team member never can, for the same reason they cannot create people:
 * these are decisions for whoever heads the organisation, and a team member
 * belongs to one rather than running it.
 */
function fabo_may_manage(?array $viewer, string $desk): bool
{
    if (!$viewer) {
        return false;
    }
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    /* Intake Forms is granted like a sheet -- No access / View only / Can
       edit, in the "Sheet and module access" table -- not as a tick-box. */
    if ($desk === 'intake') {
        return fabo_module_permission($viewer, 'intake') !== 'none';
    }
    $right = fabo_manage_desks()[$desk][0] ?? '';
    if ($right === '') {
        return false;
    }
    $c = $viewer['client'] ?? [];
    if (($c['kind'] ?? 'client') === 'team') {
        return false;
    }
    /* These desks change the whole organisation's setup -- its committees,
       budget heads, requisition types and where their notifications go --
       so they belong to its head. A sub-account works at its parent's scope,
       so holding the right there meant re-pointing the parent's settings. */
    if ((int) ($c['parent_id'] ?? 0) !== 0) {
        return false;
    }
    return !empty($c[$right]);
}

/**
 * May this viewer choose what their dashboards draw?
 *
 * The head of any organisation may, without the "Manage their own sheets"
 * right: a chart changes no data and no sheet, only what the dashboard
 * shows, and only for their own organisation. A team member may not, for
 * the same reason they run no desk. We always may.
 */
function fabo_may_chart(?array $viewer): bool
{
    if (!$viewer) {
        return false;
    }
    if (!empty($viewer['is_admin'])) {
        return true;
    }
    $c = $viewer['client'] ?? [];
    if (($c['kind'] ?? 'client') === 'team') {
        return false;
    }
    /* The HEAD of an organisation, as the comment above says. A client-kind
       sub-account resolves to its parent's scope, so without this every one
       of them -- committee-only accounts included -- could rewrite or delete
       the parent organisation's charts. */
    if ((int) ($c['parent_id'] ?? 0) !== 0) {
        return false;
    }
    require_once __DIR__ . '/lists.php';
    return fabo_customiser($viewer) > 0;
}

/** May this viewer make and change intake forms, not only see them? */
function fabo_intake_may_edit(array $viewer): bool
{
    return !empty($viewer['is_admin']) || fabo_module_permission($viewer, 'intake') === 'edit';
}

/** Where this viewer manages dashboard charts: the full desk, or charts alone. */
function fabo_chart_desk(array $viewer): string
{
    if (!empty($viewer['is_admin']) || fabo_may_manage($viewer, 'sheets')) {
        return 'sheets.php';
    }
    return fabo_may_chart($viewer) ? 'charts.php' : '';
}

/**
 * Open a management desk: work out who is asking and what they may touch.
 *
 * Returns [viewer, scope]. Scope is 0 for us — the shared set — and the
 * organisation's id for a client. Every query on the page that follows is
 * expected to carry it; a query that does not is a leak between tenants, so
 * the scope is returned rather than looked up again further down.
 *
 * A root-level wrapper (sheets.php, forms.php) resolves the client first and
 * leaves them in $GLOBALS['fabo_manage_viewer']; with nothing there, this is
 * the administrator's own copy of the page and the usual admin gate applies.
 */
function fabo_manage_open(string $desk): array
{
    $viewer = $GLOBALS['fabo_manage_viewer'] ?? null;

    if ($viewer === null) {
        fabo_require_admin();
        return [fabo_admin_viewer(), 0];
    }

    /* charts.php opens this desk held to its Dashboard tab, which any head
       of an organisation may use -- see fabo_may_chart(). */
    $chartsOnly = $desk === 'sheets' && !empty($GLOBALS['fabo_charts_only']);
    if ($chartsOnly ? !fabo_may_chart($viewer) : !fabo_may_manage($viewer, $desk)) {
        $label = fabo_manage_desks()[$desk][1] ?? 'this desk';
        fabo_deny('Your account cannot manage ' . $label . '.', 403);
    }

    /* The same number the grid resolves for dropdown values, so a column
       renamed on this desk and an option added from inside a cell land in
       the same place. */
    $scope = fabo_customiser($viewer);
    if ($scope <= 0) {
        fabo_deny('Your account has no organisation of its own to manage.', 403);
    }

    /* Whose definitions the sheet functions should resolve, set here rather
       than left to fabo_head().

       The head runs last, after the page has already read its index and run
       whatever the form asked for. Leaving it until then means the desk
       spends the whole request looking at OUR sheets: a client's own built
       sheets missing from the list, their renamed columns showing our
       wording, and a rename aimed at a column the sheet does not appear to
       have. Setting it at the gate makes every line below this one read the
       organisation the page belongs to. */
    fabo_view_owner($scope);

    return [$viewer, $scope];
}

/**
 * A link that stays on the desk the viewer is actually on.
 *
 * The two copies live at admin/sheets.php and sheets.php, so the file name
 * is the same either way; what differs is that a client following a link
 * from an emailed dashboard has to keep their token on it.
 */
function fabo_manage_link(array $viewer, string $page, array $q = []): string
{
    if (!empty($viewer['is_admin'])) {
        return $page . ($q ? '?' . http_build_query($q) : '');
    }
    return fabo_link($viewer, $page, $q);
}

/**
 * Where to send somebody after they have done something, with whatever
 * identified them still attached.
 *
 * $back is a relative target the page worked out for itself, sometimes with
 * a query string of its own ("sheets.php?tab=options&list=Brand"). For us it
 * is already right. For a client who arrived on a link rather than a session,
 * it is one redirect away from the sign-in page, because the token lives in
 * the URL and a bare file name does not have one.
 */
function fabo_manage_back(array $viewer, string $back): string
{
    if (!empty($viewer['is_admin']) || fabo_signed_in()) {
        return $back;
    }
    $page = $back;
    $q    = [];
    if (($at = strpos($back, '?')) !== false) {
        $page = substr($back, 0, $at);
        parse_str(substr($back, $at + 1), $q);
    }
    return fabo_link($viewer, $page, $q);
}

/**
 * The sheets this desk may show.
 *
 * Ours shows everything. A client's shows the sheets they were granted, and
 * nothing else — managing the columns of a sheet you cannot open is not a
 * thing anybody needs to do, and offering it would say more about what
 * exists than they are entitled to know.
 */
function fabo_manage_sheets(array $viewer, int $scope): array
{
    $all = fabo_sheets();
    if ($scope === 0) {
        return $all;
    }
    $mine = [];
    foreach ($all as $key => $sheet) {
        if ($key === 'dashboard') {
            continue;
        }
        if (fabo_permission($viewer, $key) === 'edit') {
            $mine[$key] = $sheet;
        }
    }
    return $mine;
}

/**
 * Guard one sheet key against the desk's scope.
 *
 * Called before anything is written against a sheet the URL named, because
 * a scope alone does not stop somebody typing another sheet's key into the
 * address bar.
 */
function fabo_manage_sheet_ok(array $viewer, int $scope, string $key): bool
{
    if ($scope === 0) {
        return true;
    }
    return $key !== '' && fabo_permission($viewer, $key) === 'edit';
}
