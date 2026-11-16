<?php
/**
 * Event Organizers — what the public pages share: finding the organisation,
 * the page frame, the signed-in attendee.
 */

require_once __DIR__ . '/../ui.php';
require_once __DIR__ . '/../inc/render.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../throttle.php';

/** Opens the organisation named in ?o=. Returns it, or -1 when it has no Event Organizers. */
function eo_public_start(string $who = 'visitor'): int
{
    fabo_boot();
    inc_use_kind('eo');
    $org = max(0, (int) ($_GET['o'] ?? 0));
    if (!inc_org_enabled($org)) {
        return -1;
    }
    inc_start(['is_admin' => false, 'client' => ['name' => $who], 'access' => null], $org);
    eo_install();
    return $org;
}

function eo_brand(int $org): string
{
    return $org > 0 ? fabo_org_brand($org)[1] : fabo_app_name();
}

/** The link on this organisation's public pages. */
function eo_here(int $org, string $path): string
{
    return $path . ($org > 0 ? (str_contains($path, '?') ? '&' : '?') . 'o=' . $org : '');
}

/** The attendee signed in on this organisation's site, or null. */
function eo_current_user(int $org): ?array
{
    fabo_session();
    $id = (int) ($_SESSION['eo_user'][$org] ?? 0);
    return $id ? inc_one('SELECT * FROM inc_ev_users WHERE id = ? AND active = 1', 'i', [$id]) : null;
}

function eo_sign_in_user(int $org, array $u): void
{
    fabo_session();
    session_regenerate_id(true);
    $_SESSION['eo_user'][$org] = (int) $u['id'];
}

function eo_public_head(int $org, string $title, string $description = '', bool $noindex = false): void
{
    $brand = $org >= 0 ? eo_brand($org) : fabo_app_name();
    $u = $org >= 0 ? eo_current_user($org) : null;
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($noindex): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<title><?= e($title) ?> &middot; <?= e($brand) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= e($description) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(fabo_asset('assets/form.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/inc.css')) ?>">
<link rel="stylesheet" href="<?= e(fabo_asset('assets/eo.css')) ?>">
</head>
<body class="fm-body">
<header class="fm-top ev-top"><div class="brandmark"><span><?= e($brand) ?></span></div>
<?php if ($org >= 0): ?>
  <nav class="ev-nav" aria-label="Site">
    <a href="<?= e(eo_here($org, 'event.php')) ?>">Events</a>
    <a href="<?= e(eo_here($org, 'event.php?page=about')) ?>">About</a>
    <a href="<?= e(eo_here($org, 'event.php?page=contact')) ?>">Contact</a>
    <a href="<?= e(eo_here($org, 'event-account.php')) ?>"><?= $u ? e('My hub') : 'Sign in' ?></a>
  </nav>
<?php endif; ?>
</header>
<main class="fm-wrap" id="main"><div class="fm-card">
<?php
}

function eo_public_foot(int $org, bool $chat = true): void
{
    echo '</div>';
    if ($org >= 0) {
        echo '<p class="ev-legal"><a href="' . e(eo_here($org, 'event.php?page=terms')) . '">Terms</a> · <a href="' . e(eo_here($org, 'event.php?page=privacy')) . '">Privacy</a></p>';
    }
    echo '</main>';
    if ($chat && $org >= 0) {
        echo '<div id="ev-chat" data-url="' . e(eo_here($org, 'event-chat.php')) . '" data-csrf="' . e(fabo_csrf_token()) . '"></div>'
           . '<script src="' . e(fabo_asset('assets/eo-public.js')) . '" defer></script>';
    }
    echo '</body></html>';
}

/** A closed door, with the reason. */
function eo_public_stop(int $org, int $status, string $eyebrow, string $title, string $body): void
{
    http_response_code($status);
    eo_public_head($org, $title, '', true);
    echo '<div class="fm-head"><div class="fm-eyebrow">' . e($eyebrow) . '</div><h1>' . e($title) . '</h1><p class="fm-lead">' . e($body) . '</p></div>';
    eo_public_foot($org, false);
    exit;
}
