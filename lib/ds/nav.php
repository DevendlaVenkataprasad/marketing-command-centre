<?php
/**
 * Digital Signatures in the sidebar: one group, its pages nested under it
 * while one is open, each shown only to someone who may open it. Light
 * enough for lib/ui.php to draw on every page.
 */

require_once __DIR__ . '/core.php';

function ds_nav_html(array $viewer, string $current): string
{
    $org = ds_org_of($viewer);
    if (!empty($viewer['is_admin'])) {
        fabo_session();
        $org = (int) ($_SESSION['ds_admin_org'] ?? 0);
    }
    if ($org < 0) {
        return '';
    }
    $grant = !empty($viewer['is_admin']) ? 'edit' : ds_org_grant($org);
    if ($grant === 'none') {
        return '';
    }
    $was = [$GLOBALS['ds_org'] ?? null, $GLOBALS['ds_viewer'] ?? null, $GLOBALS['ds_grant'] ?? null, $GLOBALS['ds_settings_cache'] ?? null];
    $GLOBALS['ds_org'] = $org;
    $GLOBALS['ds_settings_cache'] = null;
    $GLOBALS['ds_viewer'] = $viewer;
    $GLOBALS['ds_grant'] = $grant;
    $page = str_starts_with($current, 'ds-') ? substr($current, 3) : '';
    $parent = ['doc' => 'documents', 'studio' => 'documents', 'sign' => 'inbox', 'workflow' => 'admin', 'workflows' => 'admin', 'signatories' => 'admin', 'categories' => 'admin',
               'providers' => 'admin', 'trust' => 'admin', 'roles' => 'admin', 'delegations' => 'admin', 'settings' => 'admin', 'audit' => 'admin', 'done' => 'inbox', 'track' => 'verify'][$page] ?? $page;
    $h = '';
    try {
        $mine = 0;
        try {
            $mine = (int) (fabo_one('SELECT COUNT(*) n FROM ds_signature_requests WHERE org_id = ? AND person_id = ? AND status = "pending"', 'ii',
                                    [$org, !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0)])['n'] ?? 0);
        } catch (Throwable $e) {
        }
        $items = [
            'dashboard' => ['Dashboard', true],
            'inbox'     => ['My signatures' . ($mine ? ' <span class="badge">' . $mine . '</span>' : ''), true],
            'documents' => ['Documents', true],
            'new'       => ['New document', ds_can('docs.create')],
            'verify'    => ['Verify', ds_can('verify.use')],
            'reports'   => ['Reports', ds_can('reports.view')],
            'admin'     => ['Administration', ds_can_any(['workflows.manage', 'signatories.manage', 'categories.manage', 'providers.manage', 'roles.manage',
                                                          'settings.manage', 'audit.view', 'monitor.manage', 'delegations.manage'])],
        ];
        foreach ($items as $p => [$label, $show]) {
            if (!$show) {
                continue;
            }
            $on = $parent === $p;
            $h .= '<a class="hr-sub' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="sign.php?p=' . $p . '">' . $label . '</a>';
        }
    } catch (Throwable $e) {
        $h = '<a class="hr-sub" href="sign.php">Open Digital Signatures</a>';
    } finally {
        [$GLOBALS['ds_org'], $GLOBALS['ds_viewer'], $GLOBALS['ds_grant'], $GLOBALS['ds_settings_cache']] = $was;
    }
    return fabo_nav_group('Digital Signatures', 'sign', $page !== '', $h);
}
