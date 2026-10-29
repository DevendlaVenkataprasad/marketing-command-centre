<?php
/**
 * Finance in the sidebar: one group, its desks listed by finance function,
 * each shown only to someone who may open it. Kept light so lib/ui.php can
 * draw it on every page: it reads the grant and the viewer's roles, and
 * nothing else of the module.
 */

require_once __DIR__ . '/core.php';

/** The sidebar sections: label => [page => [label, permissions (any)]]. Null permissions: everyone with the portal. */
function fp_nav_sections(): array
{
    return [
        'Overview'    => ['dashboard' => ['Dashboard', ['dashboard.view']], 'me' => ['My finance', null], 'approvals' => ['Approvals', null], 'reports' => ['Reports', ['reports.view']]],
        'Accounting'  => ['journals' => ['Journal entries', ['gl.view']], 'ledger' => ['General ledger', ['gl.view']], 'accounts' => ['Chart of accounts', ['gl.view']],
                          'close' => ['Period close', ['gl.view']]],
        'Payables'    => ['bills' => ['Vendor bills', ['ap.view']], 'vendors' => ['Vendors', ['ap.view']], 'pos' => ['Purchase orders', ['proc.view']],
                          'prs' => ['Requisitions', ['proc.view']]],
        'Receivables' => ['invoices' => ['Customer invoices', ['ar.view']], 'receipts' => ['Receipts', ['ar.view']], 'customers' => ['Customers', ['ar.view']]],
        'Treasury'    => ['payments' => ['Payments', ['pay.view']], 'banks' => ['Bank & reconciliation', ['pay.view', 'bank.reconcile']], 'forecast' => ['Cash forecast', ['pay.view']]],
        'Planning'    => ['budgets' => ['Budgets & forecasts', ['budget.view']]],
        'Expenses'    => ['claims' => ['Expense claims', ['claims.view']]],
        'Assets & tax'=> ['assets' => ['Fixed assets', ['assets.view']], 'tax' => ['Tax & compliance', ['tax.view']], 'controls' => ['Audit & controls', ['controls.manage', 'audit.view']]],
        'Setup'       => ['settings' => ['Settings', ['settings.manage']]],
    ];
}

/** Detail pages light up the list they belong to. */
function fp_nav_parent(string $page): string
{
    return ['journal' => 'journals', 'bill' => 'bills', 'vendor' => 'vendors', 'po' => 'pos', 'pr' => 'prs', 'invoice' => 'invoices', 'customer' => 'customers',
            'receipt' => 'receipts', 'payment' => 'payments', 'batch' => 'payments', 'budget' => 'budgets', 'transfer' => 'budgets', 'claim' => 'claims', 'advance' => 'me', 'asset' => 'assets',
            'search' => 'dashboard'][$page] ?? $page;
}

/**
 * The Finance group for this viewer, or '' when Finance is not theirs.
 * $billing adds the portal's own billing desk: ours (Client billing) for
 * the administrator; "Billed by us" for a client head who holds Finance.
 */
function fp_nav_html(array $viewer, string $current): string
{
    $org = fp_org_of($viewer);
    if (!empty($viewer['is_admin'])) {
        fabo_session();
        $org = (int) ($_SESSION['fp_admin_org'] ?? 0);
    }
    if ($org < 0) {
        return '';
    }
    $grant = !empty($viewer['is_admin']) ? 'edit' : fp_org_grant($org);
    if ($grant === 'none') {
        return '';
    }
    // Look at the organisation's roles without disturbing a page that is using another.
    $was = [$GLOBALS['fp_org'] ?? null, $GLOBALS['fp_viewer'] ?? null, $GLOBALS['fp_grant'] ?? null, $GLOBALS['fp_settings_cache'] ?? null];
    fp_use($org);
    $GLOBALS['fp_viewer'] = $viewer;
    $GLOBALS['fp_grant'] = $grant;
    $page = str_starts_with($current, 'fin-') ? fp_nav_parent(substr($current, 4)) : '';
    $h = '';
    try {
        // A client's team member sees Finance only once assigned to it.
        if (!fp_has_access()) {
            return '';
        }
        foreach (fp_nav_sections() as $section => $items) {
            $links = '';
            foreach ($items as $p => [$label, $perms]) {
                if ($perms !== null && !fp_can_any($perms)) {
                    continue;
                }
                if ($p === 'me' && !empty($viewer['is_admin'])) {
                    continue;
                }
                $on = $page === $p;
                $links .= '<a class="hr-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="fin.php?p=' . $p . '">' . e($label) . '</a>';
            }
            if ($links !== '') {
                $h .= '<div class="fp-navsec">' . e($section) . '</div>' . $links;
            }
        }
    } catch (Throwable $e) {
        $h = '<a class="hr-sub" href="fin.php">Open Finance</a>';
    } finally {
        $GLOBALS['fp_org'] = $was[0];
        $GLOBALS['fp_viewer'] = $was[1];
        $GLOBALS['fp_grant'] = $was[2];
        $GLOBALS['fp_settings_cache'] = $was[3];
    }
    // The portal's own billing: what we invoice our clients, or (for a client) what we have billed them.
    $billOn = in_array($current, ['finance', 'invoices'], true);
    if (!empty($viewer['is_admin'])) {
        $h .= '<div class="fp-navsec">Client billing</div><a class="hr-sub' . ($billOn ? ' on' : '') . '" href="finance.php">Invoices, filings &amp; spend log</a>';
    } elseif (($viewer['client']['kind'] ?? '') === 'client' && fabo_module_permission($viewer, 'finance') !== 'none') {
        $h .= '<div class="fp-navsec">From us</div><a class="hr-sub' . ($billOn ? ' on' : '') . '" href="' . e(fabo_link($viewer, 'finance.php')) . '">Billed by us &amp; filings</a>';
    }
    return fabo_nav_group('Finance', 'wallet', $page !== '' || $billOn, $h);
}
