<?php
/**
 * Whose name a page or a message carries.
 *
 * The portal itself is the Marketing Command Centre. A client, though, should
 * never see our name on their own dashboard or in mail we send on their behalf
 * — they see their organisation. Everything that prints a name goes through
 * here so the two can never drift apart.
 */

require_once __DIR__ . '/db.php';

/** The portal's own name. Admin screens and anything unattributed use this. */
function fabo_app_name(): string
{
    return defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : 'Marketing Command Centre';
}

/** The line under the name in the top bar. */
function fabo_app_tagline(): string
{
    return defined('APP_TAGLINE') && APP_TAGLINE !== '' ? APP_TAGLINE : 'Central Marketing Reporting';
}

/**
 * The name one person's portal and mail should carry: their organisation when
 * they have one, otherwise the portal's own name.
 */
function fabo_person_brand(?array $person): string
{
    $org = trim((string) ($person['organisation'] ?? ''));
    return $org !== '' ? $org : fabo_app_name();
}

/**
 * The name to show a viewer. A client sees their organisation; a team member
 * sees the organisation of whoever they belong to, or the portal.
 */
function fabo_viewer_brand(array $viewer): string
{
    if (!empty($viewer['is_admin'])) {
        return fabo_app_name();
    }
    $client = $viewer['client'] ?? null;
    if (!$client) {
        return fabo_app_name();
    }
    // A sub-account carries the organisation of the account that created it.
    if (!empty($client['parent_id']) && trim((string) ($client['organisation'] ?? '')) === '') {
        $parent = fabo_one('SELECT organisation FROM clients WHERE id = ?', 'i', [(int) $client['parent_id']]);
        if ($parent) {
            return fabo_person_brand($parent);
        }
    }
    return fabo_person_brand($client);
}

/**
 * Whose mark a viewer's portal carries: the ORGANISATION's, not the person's.
 *
 * The same rule fabo_viewer_brand() follows for the name, and it has to be
 * the same rule: a team member's bar reading their organisation's name beside
 * their own face is two different answers to one question. A member's photo
 * belongs on their profile, not in the brandmark.
 *
 * Returns the client id whose logo is the brand, or 0 for ours -- which is
 * the same numbering fabo_customiser() uses.
 */
function fabo_brand_client_id(array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return 0;
    }
    $c = $viewer['client'] ?? [];
    $org = (int) ($c['parent_id'] ?? 0);
    if ($org === 0 && ($c['kind'] ?? 'client') === 'client') {
        $org = (int) ($c['id'] ?? 0);
    }
    return $org;                  // our own staff have no parent: our mark
}

/**
 * Whether a viewer's organisation has a mark of its own to show.
 *
 * Ours is the portal's, and an admin screen is the portal's own screen.
 */
function fabo_viewer_has_logo(array $viewer): bool
{
    $org = fabo_brand_client_id($viewer);
    if ($org === 0) {
        return false;
    }
    /* The viewer's own row when it is the organisation, so the common case
       costs no query. */
    $c = $viewer['client'] ?? [];
    if ((int) ($c['id'] ?? 0) === $org) {
        return !empty($c['image_file']);
    }
    $row = fabo_one('SELECT image_file FROM clients WHERE id = ?', 'i', [$org]);
    return !empty($row['image_file']);
}

/**
 * The address of the mark to show this viewer.
 *
 * A client's own comes through logo.php, which serves the logo belonging to
 * whoever is asking -- there is no id in the URL, so one organisation's mark
 * cannot be fetched by another. Everyone else gets the portal's own file,
 * which is static and cacheable.
 */
function fabo_logo_src(array $viewer, bool $fromAdminDir = false): string
{
    $up = $fromAdminDir ? '../' : '';
    if (!fabo_viewer_has_logo($viewer)) {
        return $up . 'assets/logo.png';
    }
    $src = $up . 'logo.php';
    // A client reading their portal through a link carries it on every request.
    $k = (string) ($_GET['k'] ?? '');
    return $k !== '' ? $src . '?k=' . rawurlencode($k) : $src;
}

/**
 * The organisation a person's own outgoing mail is signed with. For a
 * sub-account this is the parent's organisation, so a whole client team
 * writes under one name.
 */
function fabo_owner_brand(array $person): string
{
    if (!empty($person['parent_id'])) {
        $parent = fabo_one('SELECT organisation FROM clients WHERE id = ?', 'i', [(int) $person['parent_id']]);
        if ($parent) {
            return fabo_person_brand($parent);
        }
    }
    return fabo_person_brand($person);
}

/**
 * The organisation a client id stands for, as [orgId, name, hasLogo].
 *
 * For pages and mail with no viewer behind them -- a public form link, the
 * requisition emails -- where all there is to go on is the client_id the
 * form or row is filed under. A sub-account resolves to the account that
 * created it, the same rule fabo_brand_client_id() applies to a viewer.
 * 0 (or an id that no longer exists) is ours: [0, our name, false].
 */
function fabo_org_brand(int $clientId): array
{
    if ($clientId <= 0) {
        return [0, fabo_app_name(), false];
    }
    $c = fabo_one('SELECT id, parent_id, organisation, name, image_file FROM clients WHERE id = ?',
                  'i', [$clientId]);
    if ($c && (int) ($c['parent_id'] ?? 0) > 0) {
        $p = fabo_one('SELECT id, parent_id, organisation, name, image_file FROM clients WHERE id = ?',
                      'i', [(int) $c['parent_id']]);
        $c = $p ?: $c;
    }
    if (!$c) {
        return [0, fabo_app_name(), false];
    }
    $name = trim((string) ($c['organisation'] ?? '')) ?: trim((string) ($c['name'] ?? ''));
    return [(int) $c['id'], $name !== '' ? $name : fabo_app_name(), !empty($c['image_file'])];
}

/** How a message signs off, given the organisation sending it. */
function fabo_sign_off(string $brand): string
{
    return "\n\nThanks,\n" . $brand;
}
