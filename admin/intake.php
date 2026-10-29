<?php
/**
 * Admin — Intake Forms.
 *
 * A link for any sheet that anybody can fill in without signing in. Each
 * submission becomes one row on that sheet, filed under the organisation the
 * form belongs to, so it shows up on their sheet exactly as if their own team
 * had typed it.
 *
 * The form asks every column a person can answer, read from the sheet as it
 * stands today -- renamed, added and reordered columns included -- so there is
 * no second list of questions to keep in step. This page shows, for each
 * form, exactly which columns it asks and which the portal fills in itself.
 *
 * The public page is form.php; what it writes is lib/forms.php.
 *
 * Live sheets, lower down, share a whole sheet rather than a form: every
 * column, as a live grid at s.php, made for one department and the person
 * who fills it in. See lib/shares.php.
 *
 * Two copies of one page: this one is ours, at scope 0, and intake.php at the
 * portal root is a client's, at their organisation's scope -- granted by us
 * with the "Share their sheets as intake forms" right. A client sees and
 * makes only their own forms, only for sheets they can edit, and their rows
 * are always filed under them. See fabo_manage_open() in lib/manage.php.
 */

require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/forms.php';
require_once __DIR__ . '/../lib/lists.php';
require_once __DIR__ . '/../lib/manage.php';
require_once __DIR__ . '/../lib/shares.php';
fabo_boot();

header('X-Robots-Tag: noindex, nofollow');

/* Ours at scope 0, a client's at their organisation's id. Every query below
   carries $scope; one that does not is a leak between clients. */
[$viewer, $scope] = fabo_manage_open('intake');
$isOurs  = $scope === 0;
/* "View only" sees the forms and their links; "Can edit" also makes them. */
$mayEdit = fabo_intake_may_edit($viewer);

/** When a link stops working, from the choice on the form. */
function fabo_intake_expiry(string $choice): ?string
{
    $days = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365][$choice] ?? 0;
    return $days ? date('Y-m-d H:i:s', strtotime('+' . $days . ' days')) : null;
}

/** Organisations a form can file its rows under: the heads of client accounts. */
function fabo_intake_clients(): array
{
    return fabo_all("SELECT id, name, organisation FROM clients
                     WHERE kind = 'client' AND revoked_at IS NULL
                       AND (parent_id IS NULL OR parent_id = 0)
                     ORDER BY organisation, name");
}

/**
 * What a form for this sheet asks, and what it leaves to the portal, as
 * [asked => [label..], files => gallery label|null, left => [label => why]].
 * Worked out as the organisation sees the sheet, with their own columns.
 */
function fabo_intake_columns(string $key, int $owner): array
{
    $was = fabo_view_owner();
    fabo_view_owner($owner);
    $sheet = fabo_sheet($key);
    $out   = ['asked' => [], 'files' => null, 'left' => [], 'label' => (string) ($sheet['label'] ?? $key)];
    if ($sheet) {
        $asked = [];
        foreach (fabo_form_fields($sheet) as $c) {
            $asked[$c[1]] = true;
            $out['asked'][] = fabo_form_label($c)
                . (fabo_form_is_file_col($c) ? ' (file)' : ($c[2] === 'checkbox' ? ' (yes / no)' : ''));
        }
        foreach ($sheet['columns'] as $c) {
            if (isset($asked[$c[1]])) { continue; }
            $why = match (true) {
                $c[2] === 'id'       => 'issued automatically',
                $c[2] === 'images'   => 'asked as the file upload at the end',
                $c[2] === 'computed' => 'worked out by the portal',
                $c[1] === 'approval_to' => 'sent for approval from the sheet, once the row exists',
                default              => 'set by the team that runs this sheet',
            };
            if ($c[2] === 'images') { $out['files'] = fabo_form_label($c); }
            $out['left'][fabo_form_label($c)] = $why;
        }
    }
    fabo_view_owner($was);
    return $out;
}

/**
 * What the Live sheets section's buttons do. Returns the flash to show.
 * A client's desk reaches only its own shares, and files only under itself.
 */
function fabo_intake_live_action(string $action, array $viewer, int $scope, bool $isOurs): array
{
    if ($action === 'ls_create') {
        $owner = $isOurs ? (int) ($_POST['client_id'] ?? 0) : $scope;
        $key   = (string) ($_POST['sheet_key'] ?? '');
        if ($owner > 0 && !fabo_one("SELECT id FROM clients WHERE id = ? AND kind = 'client'", 'i', [$owner])) {
            throw new InvalidArgumentException('That client was not found.');
        }
        $was = fabo_view_owner();
        fabo_view_owner($owner);
        $ok = fabo_sheet($key) !== null && in_array($key, fabo_form_sheet_keys(), true);
        fabo_view_owner($was);
        if (!$ok || !fabo_manage_sheet_ok($viewer, $scope, $key)) {
            throw new InvalidArgumentException($isOurs ? 'Pick a sheet that this client has.'
                                                       : 'Pick one of the sheets you can edit.');
        }
        fabo_share_create($scope, $owner, $key, (string) ($_POST['department'] ?? ''),
                          (string) ($_POST['contact_name'] ?? ''), (string) ($_POST['contact_email'] ?? ''),
                          (string) ($_POST['sees'] ?? 'own'),
                          fabo_intake_expiry((string) ($_POST['duration'] ?? 'never')),
                          $viewer['is_admin'] ? 'Admin' : (string) ($viewer['client']['name'] ?? ''),
                          (string) ($_POST['hub_spoke'] ?? ''));
        fabo_view_owner($was);
        return ['ok', 'Live sheet shared. Copy its link below and send it to the person it is for — '
                    . 'no sign-in is needed, and what they type is on the sheet straight away.'];
    }
    $id = (int) ($_POST['share_id'] ?? 0);
    $sh = fabo_one('SELECT * FROM sheet_shares WHERE id = ?' . ($isOurs ? '' : ' AND owner_scope = ' . (int) $scope),
                   'i', [$id]);
    if (!$sh) {
        throw new InvalidArgumentException('That live sheet was not found.');
    }
    switch ($action) {
        case 'ls_toggle':
            fabo_exec('UPDATE sheet_shares SET active = 1 - active WHERE id = ?', 'i', [$id]);
            return ['ok', (int) $sh['active'] ? 'Live sheet closed. Its link no longer opens.'
                                              : 'Live sheet opened again.'];
        case 'ls_rotate':
            fabo_exec('UPDATE sheet_shares SET token = ? WHERE id = ?', 'si', [fabo_share_new_token(), $id]);
            return ['ok', 'New link made. The old one no longer works.'];
        case 'ls_extend':
            fabo_exec('UPDATE sheet_shares SET expires_at = ?, active = 1 WHERE id = ?', 'si',
                      [fabo_intake_expiry((string) ($_POST['duration'] ?? 'never')), $id]);
            return ['ok', 'Link renewed.'];
        case 'ls_delete':
            fabo_exec('DELETE FROM sheet_shares WHERE id = ?', 'i', [$id]);
            return ['ok', 'Live sheet deleted. Its link stops working; every row added through it stays on the sheet.'];
    }
    throw new InvalidArgumentException('Unknown action.');
}

/** How many rows a share has added, or null when that cannot be told. */
function fabo_intake_live_rows(array $share): ?int
{
    $was   = fabo_view_owner();
    $sheet = fabo_share_sheet($share);
    fabo_view_owner($was);
    if (!$sheet) {
        return null;
    }
    try {
        $r = fabo_one('SELECT COUNT(*) n FROM `' . $sheet['table'] . '` WHERE share_id = ?', 'i', [(int) $share['id']]);
        return (int) ($r['n'] ?? 0);
    } catch (Throwable $e) {
        return null;
    }
}

/** Every column of the shared sheet, sorted into what is typed and what the portal fills. */
function fabo_intake_live_columns(array $share): array
{
    $was   = fabo_view_owner();
    $sheet = fabo_share_sheet($share);
    $out   = ['label' => (string) ($sheet['label'] ?? $share['sheet_key']), 'edit' => [], 'auto' => []];
    if ($sheet) {
        foreach (fabo_share_columns($sheet, $share) as $m) {
            if ($m['mode'] === 'read') {
                $out['auto'][$m['label']] = $m['note'];
            } else {
                $out['edit'][] = $m['label'] . ($m['mode'] === 'images' ? ' (pictures)'
                               : ($m['mode'] === 'file' ? ' (file)' : ($m['unlock'] !== '' ? ' (opens on its date)' : '')));
            }
        }
        if (!array_filter(fabo_share_columns($sheet, $share), static fn($m) => $m['mode'] === 'images')) {
            $out['edit'][] = 'Attachments — Images';
        }
        $out['edit'][] = 'Attachments — Documents';
    }
    fabo_view_owner($was);
    return $out;
}

/* --------------------------------------------- filtering and pages

   Both lists on this desk -- forms and live sheets -- can be filtered and
   are shown a page at a time. Everything is in the address (f_* for forms,
   l_* for live sheets), so a filtered page can be reloaded, bookmarked or
   sent on, and the buttons on a card come back to the same view. */

/** The address keys this page keeps; anything else is dropped. */
const FABO_IK_KEYS = ['k', 'f_q', 'f_sheet', 'f_state', 'f_client', 'f_per', 'fp',
                      'l_q', 'l_sheet', 'l_dept', 'l_hub', 'l_state', 'l_client', 'l_per', 'lp'];

/** How many cards a page may show, and the default. */
const FABO_IK_PER = [10, 25, 50];

/** One filter value from the address, trimmed; '' when not set. */
function fabo_ik_get(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? trim(mb_substr($v, 0, 160)) : '';
}

/** The kept address keys, with some changed; a null value takes one out. */
function fabo_ik_query(array $over = [], ?array $from = null): string
{
    $q = [];
    foreach (FABO_IK_KEYS as $k) {
        $v = $from !== null ? ($from[$k] ?? '') : fabo_ik_get($k);
        if (is_string($v) && $v !== '') {
            $q[$k] = $v;
        }
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = (string) $v;
        }
    }
    return http_build_query($q);
}

/** Page size from the address, one of the offered sizes. */
function fabo_ik_per(string $key): int
{
    $n = (int) fabo_ik_get($key);
    return in_array($n, FABO_IK_PER, true) ? $n : FABO_IK_PER[0];
}

/** Does a search term appear in any of these strings? Case-insensitive. */
function fabo_ik_match(string $q, array $hay): bool
{
    if ($q === '') {
        return true;
    }
    $hay = mb_strtolower(implode(' ', array_map('strval', $hay)));
    foreach (preg_split('~\s+~u', mb_strtolower($q)) as $w) {
        if ($w !== '' && !str_contains($hay, $w)) {
            return false;
        }
    }
    return true;
}

/** One page of a list: [items, page, pages, total, first, last]. */
function fabo_ik_page(array $items, string $pageKey, int $per): array
{
    $total = count($items);
    $pages = max(1, (int) ceil($total / $per));
    $page  = min($pages, max(1, (int) fabo_ik_get($pageKey)));
    $slice = array_slice($items, ($page - 1) * $per, $per);
    return [$slice, $page, $pages, $total,
            $total ? ($page - 1) * $per + 1 : 0, ($page - 1) * $per + count($slice)];
}

/** The sheet's name as an organisation calls it, remembered. */
function fabo_ik_sheet_name(string $key, int $owner): string
{
    static $seen = [];
    if (!isset($seen[$owner][$key])) {
        $was = fabo_view_owner();
        fabo_view_owner($owner);
        $seen[$owner][$key] = fabo_sheet($key) ? fabo_sheet_label($key) : $key;
        fabo_view_owner($was);
    }
    return $seen[$owner][$key];
}

/**
 * The row of page links under a list: previous, numbers (the first, the
 * last and those around this one), next.
 */
function fabo_ik_pager(string $pageKey, int $page, int $pages, string $anchor): string
{
    if ($pages <= 1) {
        return '';
    }
    $link = static function (int $p, string $text, string $cls = '') use ($pageKey, $anchor): string {
        return '<a class="ik-pg' . $cls . '" href="?' . e(fabo_ik_query([$pageKey => $p > 1 ? $p : null]))
             . '#' . $anchor . '">' . $text . '</a>';
    };
    $h = '<nav class="ik-pager" aria-label="Pages">';
    $h .= $page > 1 ? $link($page - 1, '&lsaquo; Previous', ' ik-pgstep')
                    : '<span class="ik-pg ik-pgstep off">&lsaquo; Previous</span>';
    $shown = 0;
    for ($p = 1; $p <= $pages; $p++) {
        if ($p === 1 || $p === $pages || abs($p - $page) <= 1) {
            if ($shown && $p - $shown > 1) {
                $h .= '<span class="ik-pggap">&hellip;</span>';
            }
            $h .= $p === $page ? '<span class="ik-pg on" aria-current="page">' . $p . '</span>' : $link($p, (string) $p);
            $shown = $p;
        }
    }
    $h .= $page < $pages ? $link($page + 1, 'Next &rsaquo;', ' ik-pgstep')
                         : '<span class="ik-pg ik-pgstep off">Next &rsaquo;</span>';
    return $h . '</nav>';
}

/** Hidden fields carrying every kept key except the ones a filter bar sets itself. */
function fabo_ik_hidden(string $prefix, string $pageKey): string
{
    $h = '';
    foreach (FABO_IK_KEYS as $k) {
        if (str_starts_with($k, $prefix) || $k === $pageKey) {
            continue;
        }
        $v = fabo_ik_get($k);
        if ($v !== '') {
            $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
        }
    }
    return $h;
}

/** A dropdown filter. $opts is value => label; '' is "any". */
function fabo_ik_select(string $name, string $label, array $opts, string $any): string
{
    $cur = fabo_ik_get($name);
    $h = '<label class="ik-fsel"><span>' . e($label) . '</span><select name="' . e($name)
       . '" class="js-ikauto"><option value="">' . e($any) . '</option>';
    foreach ($opts as $v => $l) {
        $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>'
            . e((string) $l) . '</option>';
    }
    return $h . '</select></label>';
}

/* ------------------------------------------------------------- actions */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fabo_csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['form_id'] ?? 0);
    $flash  = null;
    try {
        if (!$mayEdit) {
            throw new InvalidArgumentException('You can see these forms but not change them. Ask the admin for edit access.');
        }
        if (str_starts_with($action, 'ls_')) {
            $flash = fabo_intake_live_action($action, $viewer, $scope, $isOurs);
        } elseif ($action === 'create') {
            $label  = trim((string) ($_POST['label'] ?? ''));
            $key    = (string) ($_POST['sheet_key'] ?? '');
            $client = $isOurs ? (int) ($_POST['client_id'] ?? 0) : $scope;
            if ($label === '') {
                throw new InvalidArgumentException('Give the form a name people will recognise.');
            }
            if ($client > 0 && !fabo_one("SELECT id FROM clients WHERE id = ? AND kind = 'client'",
                                         'i', [$client])) {
                throw new InvalidArgumentException('That client was not found.');
            }
            /* The sheet has to exist for whoever the form is for: a sheet
               built in one client's portal is theirs alone. */
            $was = fabo_view_owner();
            fabo_view_owner($client);
            $ok = fabo_sheet($key) !== null && in_array($key, fabo_form_sheet_keys(), true);
            fabo_view_owner($was);
            if (!$ok || !fabo_manage_sheet_ok($viewer, $scope, $key)) {
                throw new InvalidArgumentException($isOurs ? 'Pick a sheet that this client has.'
                                                           : 'Pick one of the sheets you can edit.');
            }
            fabo_exec('INSERT INTO form_links (sheet_key, label, token, active, expires_at, client_id)
                       VALUES (?,?,?,1,?,?)', 'ssssi',
                      [$key, mb_substr($label, 0, 160), fabo_form_new_token(),
                       fabo_intake_expiry((string) ($_POST['duration'] ?? 'never')), $client]);
            $flash = ['ok', 'Form created. Copy its link below and share it — no sign-in is needed to fill it in.'];
        } elseif ($id > 0 && fabo_one('SELECT id FROM form_links WHERE id = ?'
                                      . ($isOurs ? '' : ' AND client_id = ' . (int) $scope),
                                      'i', [$id])) {
            switch ($action) {
                case 'toggle':
                    fabo_exec('UPDATE form_links SET active = 1 - active WHERE id = ?', 'i', [$id]);
                    $flash = ['ok', 'Done.'];
                    break;
                case 'rotate':
                    fabo_exec('UPDATE form_links SET token = ? WHERE id = ?', 'si', [fabo_form_new_token(), $id]);
                    $flash = ['ok', 'New link made. The old one no longer works.'];
                    break;
                case 'extend':
                    fabo_exec('UPDATE form_links SET expires_at = ?, active = 1 WHERE id = ?', 'si',
                              [fabo_intake_expiry((string) ($_POST['duration'] ?? 'never')), $id]);
                    $flash = ['ok', 'Link renewed.'];
                    break;
                case 'delete':
                    fabo_exec('DELETE FROM form_links WHERE id = ?', 'i', [$id]);
                    $flash = ['ok', 'Form deleted. Everything sent through it stays on its sheet.'];
                    break;
            }
        } else {
            throw new InvalidArgumentException('That form was not found.');
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('FABO intake: ' . $e->getMessage());
        $flash = ['err', 'That did not go through. Nothing was changed.'];
    }
    fabo_session();
    $_SESSION['fabo_intake_flash'] = $flash;
    /* Back to the view the button was pressed on -- same filters, same page. */
    parse_str((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY), $was);
    $keep = $action === 'create' || $action === 'ls_create'
        ? '' : fabo_ik_query(['k' => null], is_array($was) ? $was : []);
    header('Location: ' . fabo_manage_back($viewer, 'intake.php' . ($keep !== '' ? '?' . $keep : ''))
           . (str_starts_with($action, 'ls_') ? '#live'
              : ($action === 'create' && ($flash[0] ?? '') === 'ok' ? '#forms' : '')));
    exit;
}

fabo_session();
$flash = $_SESSION['fabo_intake_flash'] ?? null;
unset($_SESSION['fabo_intake_flash']);

/* --------------------------------------------------------- what to show */

if ($isOurs) {
    $names = [0 => 'Ours (the portal)'];
    foreach (fabo_intake_clients() as $c) {
        $names[(int) $c['id']] = $c['name'] . ($c['organisation'] ? ' · ' . $c['organisation'] : '');
    }
} else {
    // A client files under their own organisation, and only there.
    $org   = fabo_one('SELECT name, organisation FROM clients WHERE id = ?', 'i', [$scope]);
    $names = [$scope => (string) (($org['organisation'] ?? '') ?: ($org['name'] ?? 'Your organisation'))];
}

/* The sheets a form can be made for, per organisation: ours, and each
   client's own built sheets on top of the shared ones. */
$sheetsFor = [];
foreach (array_keys($names) as $cid) {
    $was = fabo_view_owner();
    fabo_view_owner($cid);
    foreach (fabo_form_sheet_keys() as $k) {
        if (!fabo_manage_sheet_ok($viewer, $scope, $k)) {
            continue;                    // a client shares only what they can edit
        }
        $sheetsFor[$cid][$k] = fabo_sheet_label($k);
    }
    fabo_view_owner($was);
}

$forms = $isOurs
    ? fabo_all('SELECT * FROM form_links ORDER BY created_at DESC')
    : fabo_all('SELECT * FROM form_links WHERE client_id = ? ORDER BY created_at DESC', 'i', [$scope]);
$base  = fabo_portal_url();

/* The departments a live sheet can be for, per organisation: their Business
   Unit options. Empty means they have none yet, and any name is taken. */
$unitsFor = [];
$hubsFor  = [];
foreach (array_keys($names) as $cid) {
    $unitsFor[$cid] = fabo_list_values('Business Unit', (int) $cid);
    $hubsFor[$cid]  = fabo_list_values(FABO_SHARE_HUB_LIST, (int) $cid);
}
$shares = fabo_shares_for($scope);

/* ---- the forms: filtered, then a page of them ---- */
$formsAll   = count($forms);
$fSheetOpts = [];
foreach ($forms as $f) {
    $k = fabo_form_is_universal($f) ? '*' : (string) $f['sheet_key'];
    $fSheetOpts[$k] = $k === '*' ? 'Every sheet' : fabo_ik_sheet_name($k, (int) $f['client_id']);
}
asort($fSheetOpts);
$fq = fabo_ik_get('f_q');
$forms = array_values(array_filter($forms, static function ($f) use ($fq, $isOurs, $names) {
    $k = fabo_form_is_universal($f) ? '*' : (string) $f['sheet_key'];
    $sheetName = $k === '*' ? 'Every sheet' : fabo_ik_sheet_name($k, (int) $f['client_id']);
    return fabo_ik_match($fq, [$f['label'], $sheetName, $names[(int) $f['client_id']] ?? ''])
        && (fabo_ik_get('f_sheet') === '' || fabo_ik_get('f_sheet') === $k)
        && (fabo_ik_get('f_state') === '' || fabo_ik_get('f_state') === fabo_form_state($f))
        && (!$isOurs || fabo_ik_get('f_client') === '' || (int) fabo_ik_get('f_client') === (int) $f['client_id']);
}));
[$forms, $fPage, $fPages, $fTotal, $fFrom, $fTo] = fabo_ik_page($forms, 'fp', fabo_ik_per('f_per'));
$fFiltered = fabo_ik_get('f_q') . fabo_ik_get('f_sheet') . fabo_ik_get('f_state') . fabo_ik_get('f_client') !== '';

/* ---- the live sheets: filtered, then a page of them ---- */
$sharesAll  = count($shares);
$lSheetOpts = $lDeptOpts = $lHubOpts = [];
foreach ($shares as $sh) {
    $lSheetOpts[(string) $sh['sheet_key']] = fabo_ik_sheet_name((string) $sh['sheet_key'], (int) $sh['filed_under']);
    $lDeptOpts[(string) $sh['department']] = (string) $sh['department'];
    if (fabo_share_hub($sh) !== '') {
        $lHubOpts[fabo_share_hub($sh)] = fabo_share_hub($sh);
    }
}
asort($lSheetOpts);
ksort($lDeptOpts);
ksort($lHubOpts);
$lq = fabo_ik_get('l_q');
$shares = array_values(array_filter($shares, static function ($sh) use ($lq, $isOurs, $names) {
    $hub = fabo_share_hub($sh);
    return fabo_ik_match($lq, [$sh['department'], $hub, $sh['contact_name'], $sh['contact_email'] ?? '',
                               fabo_ik_sheet_name((string) $sh['sheet_key'], (int) $sh['filed_under']),
                               $names[(int) $sh['filed_under']] ?? ''])
        && (fabo_ik_get('l_sheet') === '' || fabo_ik_get('l_sheet') === (string) $sh['sheet_key'])
        && (fabo_ik_get('l_dept') === '' || fabo_ik_get('l_dept') === (string) $sh['department'])
        && (fabo_ik_get('l_hub') === '' || (fabo_ik_get('l_hub') === '-' ? $hub === '' : fabo_ik_get('l_hub') === $hub))
        && (fabo_ik_get('l_state') === '' || fabo_ik_get('l_state') === fabo_share_state($sh))
        && (!$isOurs || fabo_ik_get('l_client') === '' || (int) fabo_ik_get('l_client') === (int) $sh['filed_under']);
}));
[$shares, $lPage, $lPages, $lTotal, $lFrom, $lTo] = fabo_ik_page($shares, 'lp', fabo_ik_per('l_per'));
$lFiltered = fabo_ik_get('l_q') . fabo_ik_get('l_sheet') . fabo_ik_get('l_dept') . fabo_ik_get('l_hub')
           . fabo_ik_get('l_state') . fabo_ik_get('l_client') !== '';

fabo_head($viewer, 'Intake Forms', 'intake');
?>
<div class="wrap-page">
  <div class="pagehead">
    <h1>INTAKE FORMS</h1>
    <div class="sub">Share any sheet as a form &middot; no sign-in to fill it in &middot;
      every answer becomes a row on that sheet</div>
  </div>

<?php if ($flash): ?>
  <div class="flash <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

  <!-- ===================================================== new form -->
  <?php if ($mayEdit): ?>
  <div class="block">
    <div class="sb-head">
      <div>
        <span class="eyebrow">New form</span>
        <h2>Share a sheet for filling in</h2>
      </div>
      <p class="sb-lead">The form asks <strong>every column</strong> of the sheet that a
        person can answer, in the sheet's own order and with its own dropdowns &mdash;
        including columns you rename or add later. What is sent lands as a new row on
        that sheet<?= $isOurs ? ', filed under the client you pick, and appears on their sheet straight away'
                              : ', in your own data, and appears on your sheet straight away' ?>.</p>
    </div>
    <form method="post" class="sb-add ik-new">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="sb-row">
        <div class="sb-field grow"><label>Form name <small>(people see this at the top)</small></label>
          <input name="label" required maxlength="160" placeholder="e.g. Spoke event report — October"></div>
        <?php if ($isOurs): ?>
        <div class="sb-field"><label>Rows are filed under</label>
          <select name="client_id" id="ikclient">
            <?php foreach ($names as $cid => $nm): ?>
              <option value="<?= (int) $cid ?>"><?= e($nm) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="sb-field"><label>Sheet</label>
          <select name="sheet_key" id="iksheet" required>
            <?php foreach ($sheetsFor[array_key_first($names)] ?? [] as $k => $l): ?>
              <option value="<?= e($k) ?>"><?= e($l) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sb-field"><label>Link works for</label>
          <select name="duration">
            <option value="never">No end date</option>
            <option value="7d">7 days</option>
            <option value="30d">30 days</option>
            <option value="90d">90 days</option>
            <option value="365d">1 year</option>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Create form</button></div>
      </div>
    </form>
  </div>

  <?php endif; ?>

  <!-- ===================================================== the forms -->
  <div class="block" id="forms">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Shared forms</span>
        <h2><?= $formsAll ?> form<?= $formsAll === 1 ? '' : 's' ?></h2>
      </div>
    </div>

    <?php if ($formsAll): ?>
    <form method="get" class="ik-filter" action="#forms">
      <?= fabo_ik_hidden('f_', 'fp') ?>
      <label class="ik-fsearch"><span>Search</span>
        <input type="search" name="f_q" value="<?= e(fabo_ik_get('f_q')) ?>"
               placeholder="Form name, sheet<?= $isOurs ? ', client' : '' ?>…"></label>
      <?= fabo_ik_select('f_sheet', 'Sheet', $fSheetOpts, 'All sheets') ?>
      <?= fabo_ik_select('f_state', 'Status', ['ok' => 'Open', 'off' => 'Closed', 'expired' => 'Expired'], 'Any status') ?>
      <?php if ($isOurs): ?>
        <?= fabo_ik_select('f_client', 'Filed under', $names, 'Everyone') ?>
      <?php endif; ?>
      <?= fabo_ik_select('f_per', 'Per page', array_combine(array_slice(FABO_IK_PER, 1), array_slice(FABO_IK_PER, 1)), (string) FABO_IK_PER[0]) ?>
      <div class="ik-facts">
        <button class="btn btn-sm btn-primary">Filter</button>
        <?php if ($fFiltered): ?>
          <a class="btn btn-sm" href="?<?= e(fabo_ik_query(['f_q' => null, 'f_sheet' => null, 'f_state' => null,
                                                           'f_client' => null, 'fp' => null])) ?>#forms">Clear</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="ik-count">
      <?= $fTotal ? 'Showing <b>' . $fFrom . '–' . $fTo . '</b> of <b>' . $fTotal . '</b>'
                    . ($fFiltered ? ' matching form' . ($fTotal === 1 ? '' : 's') . ' (of ' . $formsAll . ')' : '') : '' ?>
    </div>
    <?php endif; ?>

    <?php if (!$formsAll): ?>
      <div class="empty"><?= $mayEdit ? 'No forms yet. Make the first one above.' : 'No forms have been shared yet.' ?></div>
    <?php elseif (!$fTotal): ?>
      <div class="empty">No form matches these filters.
        <a href="?<?= e(fabo_ik_query(['f_q' => null, 'f_sheet' => null, 'f_state' => null, 'f_client' => null, 'fp' => null])) ?>#forms">Clear them</a>.</div>
    <?php endif; ?>

    <div class="ik-list">
    <?php foreach ($forms as $f):
        $state = fabo_form_state($f);
        $url   = $base . 'form.php?k=' . $f['token'];
        $cid   = (int) $f['client_id'];
        $cols  = fabo_form_is_universal($f) ? null : fabo_intake_columns((string) $f['sheet_key'], $cid);
        $tone  = ['ok' => 'good', 'off' => 'muted', 'expired' => 'warn'][$state];
        $word  = ['ok' => 'Open', 'off' => 'Closed', 'expired' => 'Expired'][$state];
    ?>
      <div class="ik-card<?= $state === 'ok' ? '' : ' ik-off' ?>">
        <div class="ik-top">
          <div class="ik-title">
            <strong><?= e((string) $f['label']) ?></strong>
            <span class="pill tone-<?= e($tone) ?>"><i class="dot"></i><?= e($word) ?></span>
          </div>
          <div class="ik-meta">
            <span><b><?= e($cols['label'] ?? 'Every sheet') ?></b></span>
            <?php if ($isOurs): ?>
              <span>filed under <?= e($names[$cid] ?? ('client #' . $cid)) ?></span>
            <?php endif; ?>
            <span><?= (int) $f['submit_count'] ?> sent<?= !empty($f['last_submitted_at'])
                ? ' · last ' . e(date('d M Y, H:i', strtotime((string) $f['last_submitted_at']))) : '' ?></span>
            <span><?= !empty($f['expires_at'])
                ? ($state === 'expired' ? 'ended ' : 'until ') . e(date('d M Y', strtotime((string) $f['expires_at'])))
                : 'no end date' ?></span>
          </div>
        </div>

        <div class="ik-link">
          <input type="text" readonly value="<?= e($url) ?>" aria-label="Form link">
          <button type="button" class="btn btn-sm btn-primary js-ikcopy">Copy link</button>
          <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">Open form</a>
        </div>

        <?php if ($cols): ?>
          <details class="ik-cols">
            <summary><?= count($cols['asked']) ?> column<?= count($cols['asked']) === 1 ? '' : 's' ?> asked
              <?= $cols['files'] ? '+ ' . e($cols['files']) . ' upload' : '' ?>
              &middot; <?= count($cols['left']) ?> filled in by the portal</summary>
            <div class="ik-colgrid">
              <div><h4>On the form</h4>
                <ol><?php foreach ($cols['asked'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ol></div>
              <?php if ($cols['left']): ?>
                <div><h4>Not asked, and why</h4>
                  <ul><?php foreach ($cols['left'] as $l => $why): ?>
                    <li><?= e($l) ?> <span class="ro">— <?= e($why) ?></span></li>
                  <?php endforeach; ?></ul></div>
              <?php endif; ?>
            </div>
          </details>
        <?php endif; ?>

        <?php if ($mayEdit): ?>
        <div class="sb-acts ik-acts">
          <?php foreach (['toggle' => $state === 'off' ? 'Open it' : 'Close it',
                          'rotate' => 'New link'] as $act => $txt): ?>
            <form method="post" class="sb-inline"<?= $act === 'rotate'
                ? ' onsubmit="return confirm(\'Make a new link? The current one stops working for everyone who has it.\');"' : '' ?>>
              <?= fabo_csrf_field() ?>
              <input type="hidden" name="action" value="<?= e($act) ?>">
              <input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-sm"><?= e($txt) ?></button>
            </form>
          <?php endforeach; ?>
          <form method="post" class="sb-inline">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="action" value="extend">
            <input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
            <select name="duration" aria-label="Renew for">
              <option value="30d">30 days</option><option value="90d">90 days</option>
              <option value="365d">1 year</option><option value="never">No end date</option>
            </select>
            <button class="btn btn-sm">Renew</button>
          </form>
          <span style="flex:1"></span>
          <form method="post" class="sb-inline"
                onsubmit="return confirm('Delete this form? Its link stops working. Everything already sent stays on the sheet.');">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="form_id" value="<?= (int) $f['id'] ?>">
            <button class="btn btn-sm danger">Delete</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?= fabo_ik_pager('fp', $fPage, $fPages, 'forms') ?>
  </div>

  <!-- ===================================================== live sheets -->
  <div class="block" id="live">
    <div class="sb-head">
      <div>
        <span class="eyebrow">Live sheets</span>
        <h2>Share a whole sheet with a department</h2>
      </div>
      <p class="sb-lead">Not a form: the <strong>live sheet itself</strong>, as a grid, with
        <strong>every one of its columns</strong> &mdash; including columns you rename or add later,
        which appear on the shared sheet without a new link. Choose the sheet, the department and
        the person who will fill it in. Rows they add are on the sheet the moment they type them,
        stamped with the department, and what your team changes shows up on their screen within
        seconds. Columns the portal works out are shown to them, read-only.</p>
    </div>

    <?php if ($mayEdit): ?>
    <form method="post" class="sb-add ik-new ls-new">
      <?= fabo_csrf_field() ?>
      <input type="hidden" name="action" value="ls_create">
      <div class="sb-row">
        <?php if ($isOurs): ?>
        <div class="sb-field"><label>Rows are filed under</label>
          <select name="client_id" id="lsclient">
            <?php foreach ($names as $cid => $nm): ?>
              <option value="<?= (int) $cid ?>"><?= e($nm) ?></option>
            <?php endforeach; ?>
          </select></div>
        <?php endif; ?>
        <div class="sb-field"><label>Sheet</label>
          <select name="sheet_key" id="lssheet" required>
            <?php foreach ($sheetsFor[array_key_first($names)] ?? [] as $k => $l): ?>
              <option value="<?= e($k) ?>"><?= e($l) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="sb-field grow"><label>Department</label>
          <input name="department" required maxlength="160" list="lsunits"
                 placeholder="Choose or type" autocomplete="off">
          <datalist id="lsunits">
            <?php foreach ($unitsFor[array_key_first($names)] ?? [] as $u): ?>
              <option value="<?= e($u) ?>"></option>
            <?php endforeach; ?>
          </datalist></div>
        <div class="sb-field"><label>Hub / Spoke</label>
          <select name="hub_spoke" id="lshub">
            <option value="">All hubs &amp; spokes</option>
            <?php foreach ($hubsFor[array_key_first($names)] ?? [] as $h): ?>
              <option value="<?= e($h) ?>"><?= e($h) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <div class="sb-row">
        <div class="sb-field grow"><label>Name <small>(the person filling it in)</small></label>
          <input name="contact_name" required maxlength="160" placeholder="e.g. Priya Sharma"></div>
        <div class="sb-field grow"><label>Email <small>(optional, for your reference)</small></label>
          <input name="contact_email" type="email" maxlength="190" placeholder="name@example.com"></div>
        <div class="sb-field"><label>They see</label>
          <select name="sees">
            <option value="own">Only the rows they add</option>
            <option value="all">Every row on the sheet</option>
          </select></div>
        <div class="sb-field"><label>Link works for</label>
          <select name="duration">
            <option value="never">No end date</option>
            <option value="7d">7 days</option>
            <option value="30d">30 days</option>
            <option value="90d">90 days</option>
            <option value="365d">1 year</option>
          </select></div>
        <div class="sb-field"><label>&nbsp;</label>
          <button class="btn btn-primary">Share live sheet</button></div>
      </div>
      <p class="ls-hint">Departments come from the <b>Business Unit</b> dropdown on Sheet Management.
        <span id="lsunitnote"><?= ($unitsFor[array_key_first($names)] ?? [])
            ? 'Pick one of its ' . count($unitsFor[array_key_first($names)]) . ' options.'
            : 'It is empty here, so any name is accepted.' ?></span></p>
    </form>
    <?php endif; ?>

    <?php if ($sharesAll): ?>
    <div class="ik-listhead">
      <span class="eyebrow">Shared live sheets</span>
      <h2><?= $sharesAll ?> live sheet<?= $sharesAll === 1 ? '' : 's' ?></h2>
    </div>
    <form method="get" class="ik-filter" action="#live">
      <?= fabo_ik_hidden('l_', 'lp') ?>
      <label class="ik-fsearch"><span>Search</span>
        <input type="search" name="l_q" value="<?= e(fabo_ik_get('l_q')) ?>"
               placeholder="Department, person, email, hub, sheet…"></label>
      <?= fabo_ik_select('l_sheet', 'Sheet', $lSheetOpts, 'All sheets') ?>
      <?= fabo_ik_select('l_dept', 'Department', $lDeptOpts, 'All departments') ?>
      <?= fabo_ik_select('l_hub', 'Hub / Spoke', ['-' => 'All hubs & spokes (not one)'] + $lHubOpts, 'Any') ?>
      <?= fabo_ik_select('l_state', 'Status', ['ok' => 'Live', 'off' => 'Closed', 'expired' => 'Expired'], 'Any status') ?>
      <?php if ($isOurs): ?>
        <?= fabo_ik_select('l_client', 'Filed under', $names, 'Everyone') ?>
      <?php endif; ?>
      <?= fabo_ik_select('l_per', 'Per page', array_combine(array_slice(FABO_IK_PER, 1), array_slice(FABO_IK_PER, 1)), (string) FABO_IK_PER[0]) ?>
      <div class="ik-facts">
        <button class="btn btn-sm btn-primary">Filter</button>
        <?php if ($lFiltered): ?>
          <a class="btn btn-sm" href="?<?= e(fabo_ik_query(['l_q' => null, 'l_sheet' => null, 'l_dept' => null, 'l_hub' => null,
                                                           'l_state' => null, 'l_client' => null, 'lp' => null])) ?>#live">Clear</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="ik-count">
      <?= $lTotal ? 'Showing <b>' . $lFrom . '–' . $lTo . '</b> of <b>' . $lTotal . '</b>'
                    . ($lFiltered ? ' matching live sheet' . ($lTotal === 1 ? '' : 's') . ' (of ' . $sharesAll . ')' : '') : '' ?>
    </div>
    <?php endif; ?>

    <?php if (!$sharesAll): ?>
      <div class="empty"><?= $mayEdit ? 'No live sheets shared yet.' : 'No live sheets have been shared yet.' ?></div>
    <?php elseif (!$lTotal): ?>
      <div class="empty">No live sheet matches these filters.
        <a href="?<?= e(fabo_ik_query(['l_q' => null, 'l_sheet' => null, 'l_dept' => null, 'l_hub' => null,
                                       'l_state' => null, 'l_client' => null, 'lp' => null])) ?>#live">Clear them</a>.</div>
    <?php endif; ?>

    <div class="ik-list">
    <?php foreach ($shares as $sh):
        $state = fabo_share_state($sh);
        $url   = $base . FABO_SHARE_PAGE . '?k=' . $sh['token'];
        $cid   = (int) $sh['filed_under'];
        $lc    = fabo_intake_live_columns($sh);
        $added = fabo_intake_live_rows($sh);
        $tone  = ['ok' => 'good', 'off' => 'muted', 'expired' => 'warn'][$state];
        $word  = ['ok' => 'Live', 'off' => 'Closed', 'expired' => 'Expired'][$state];
    ?>
      <div class="ik-card ls-card<?= $state === 'ok' ? '' : ' ik-off' ?>">
        <div class="ik-top">
          <div class="ik-title">
            <strong><?= e($lc['label']) ?> &middot; <?= e((string) $sh['department']) ?><?= fabo_share_hub($sh) !== ''
                ? ' &middot; ' . e(fabo_share_hub($sh)) : '' ?></strong>
            <span class="pill tone-<?= e($tone) ?>"><i class="dot"></i><?= e($word) ?></span>
          </div>
          <div class="ik-meta">
            <span>for <b><?= e((string) $sh['contact_name']) ?></b><?= !empty($sh['contact_email'])
                ? ' &lt;' . e((string) $sh['contact_email']) . '&gt;' : '' ?></span>
            <?php if ($isOurs): ?>
              <span>filed under <?= e($names[$cid] ?? ('client #' . $cid)) ?></span>
            <?php endif; ?>
            <span><?= (string) $sh['sees'] === 'all'
                ? (fabo_share_hub($sh) !== '' ? 'sees every ' . e(fabo_share_hub($sh)) . ' row' : 'sees every row')
                : 'sees own rows' ?></span>
            <span><?= $added === null ? '' : $added . ' row' . ($added === 1 ? '' : 's') . ' added &middot; ' ?>opened
              <?= (int) $sh['opened_count'] ?>&times;<?= !empty($sh['last_opened_at'])
                ? ' &middot; last ' . e(date('d M Y, H:i', strtotime((string) $sh['last_opened_at']))) : '' ?></span>
            <span><?= !empty($sh['expires_at'])
                ? ($state === 'expired' ? 'ended ' : 'until ') . e(date('d M Y', strtotime((string) $sh['expires_at'])))
                : 'no end date' ?></span>
          </div>
        </div>

        <div class="ik-link">
          <input type="text" readonly value="<?= e($url) ?>" aria-label="Live sheet link">
          <button type="button" class="btn btn-sm btn-primary js-ikcopy">Copy link</button>
          <a class="btn btn-sm" href="<?= e($url) ?>" target="_blank" rel="noopener">Open sheet</a>
        </div>

        <details class="ik-cols">
          <summary>All <?= count($lc['edit']) + count($lc['auto']) ?> columns shared &middot;
            <?= count($lc['edit']) ?> they fill in &middot; <?= count($lc['auto']) ?> filled in by the portal</summary>
          <div class="ik-colgrid">
            <div><h4>They fill in</h4>
              <ol><?php foreach ($lc['edit'] as $l): ?><li><?= e($l) ?></li><?php endforeach; ?></ol></div>
            <?php if ($lc['auto']): ?>
              <div><h4>Shown to them, read-only</h4>
                <ul><?php foreach ($lc['auto'] as $l => $why): ?>
                  <li><?= e($l) ?> <span class="ro">— <?= e($why) ?></span></li>
                <?php endforeach; ?></ul></div>
            <?php endif; ?>
          </div>
        </details>

        <?php if ($mayEdit): ?>
        <div class="sb-acts ik-acts">
          <?php foreach (['ls_toggle' => $state === 'off' ? 'Open it' : 'Close it',
                          'ls_rotate' => 'New link'] as $act => $txt): ?>
            <form method="post" class="sb-inline"<?= $act === 'ls_rotate'
                ? ' onsubmit="return confirm(\'Make a new link? The current one stops working for everyone who has it.\');"' : '' ?>>
              <?= fabo_csrf_field() ?>
              <input type="hidden" name="action" value="<?= e($act) ?>">
              <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
              <button class="btn btn-sm"><?= e($txt) ?></button>
            </form>
          <?php endforeach; ?>
          <form method="post" class="sb-inline">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="action" value="ls_extend">
            <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
            <select name="duration" aria-label="Renew for">
              <option value="30d">30 days</option><option value="90d">90 days</option>
              <option value="365d">1 year</option><option value="never">No end date</option>
            </select>
            <button class="btn btn-sm">Renew</button>
          </form>
          <span style="flex:1"></span>
          <form method="post" class="sb-inline"
                onsubmit="return confirm('Delete this live sheet? Its link stops working. Every row added through it stays on the sheet.');">
            <?= fabo_csrf_field() ?>
            <input type="hidden" name="action" value="ls_delete">
            <input type="hidden" name="share_id" value="<?= (int) $sh['id'] ?>">
            <button class="btn btn-sm danger">Delete</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?= fabo_ik_pager('lp', $lPage, $lPages, 'live') ?>
  </div>
</div>

<script>
(function () {
  /* The sheet list follows the client: each has its own built sheets. */
  var sheets = <?= json_encode($sheetsFor, JSON_UNESCAPED_UNICODE) ?>;
  var pick = document.getElementById('ikclient');
  var into = document.getElementById('iksheet');
  if (pick && into) {
    pick.addEventListener('change', function () {
      var was = into.value, list = sheets[pick.value] || {};
      into.innerHTML = '';
      Object.keys(list).forEach(function (k) {
        var o = new Option(list[k], k);
        if (k === was) { o.selected = true; }
        into.appendChild(o);
      });
    });
  }
  /* The live sheet's pickers follow its client too, and so do its departments. */
  var units = <?= json_encode($unitsFor, JSON_UNESCAPED_UNICODE) ?>;
  var lpick = document.getElementById('lsclient');
  var linto = document.getElementById('lssheet');
  var ldl   = document.getElementById('lsunits');
  var lhint = document.getElementById('lsunitnote');
  var hubs  = <?= json_encode($hubsFor, JSON_UNESCAPED_UNICODE) ?>;
  var lhub  = document.getElementById('lshub');
  if (lpick && linto) {
    lpick.addEventListener('change', function () {
      var was = linto.value, list = sheets[lpick.value] || {};
      linto.innerHTML = '';
      Object.keys(list).forEach(function (k) {
        var o = new Option(list[k], k);
        if (k === was) { o.selected = true; }
        linto.appendChild(o);
      });
      var u = units[lpick.value] || [];
      ldl.innerHTML = '';
      u.forEach(function (v) { var o = document.createElement('option'); o.value = v; ldl.appendChild(o); });
      if (lhub) {
        var hv = lhub.value;
        lhub.innerHTML = '';
        lhub.appendChild(new Option('All hubs & spokes', ''));
        (hubs[lpick.value] || []).forEach(function (h) {
          var o = new Option(h, h);
          if (h === hv) { o.selected = true; }
          lhub.appendChild(o);
        });
      }
      if (lhint) {
        lhint.textContent = u.length ? 'Pick one of its ' + u.length + ' options.'
                                     : 'It is empty here, so any name is accepted.';
      }
    });
  }
  /* A dropdown filter applies the moment it changes, from the first page. */
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest ? ev.target.closest('.js-ikauto') : null;
    if (sel && sel.form) { sel.form.submit(); }
  });
  /* Copying a link. */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.js-ikcopy') : null;
    if (!b) { return; }
    var i = b.parentNode.querySelector('input');
    i.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    if (!ok && navigator.clipboard) { navigator.clipboard.writeText(i.value); ok = true; }
    b.textContent = ok ? 'Copied' : 'Select and copy';
    setTimeout(function () { b.textContent = 'Copy link'; }, 2000);
  });
})();
</script>
<?php fabo_foot();
