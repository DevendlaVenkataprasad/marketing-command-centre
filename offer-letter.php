<?php
/**
 * HR — a candidate's offer letter, from the link in their offer email.
 *
 * The token belongs to one shortlisted candidate (hr_shortlist.offer_token)
 * and opens only the letter HR sent them, which exists only once the CEO
 * approved them (lib/hr/approval.php).
 */

require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/hr/app.php';
fabo_boot();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = (string) ($_GET['t'] ?? '');
$s = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $s = fabo_one("SELECT * FROM hr_shortlist WHERE offer_token = ? AND approval_status = 'approved' AND offer_file IS NOT NULL", 's', [$token]);
    } catch (Throwable $e) {
        $s = null;
    }
}
if (!$s) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offer letter</title>'
       . '<link rel="stylesheet" href="' . e(fabo_asset('assets/form.css')) . '"><body class="fm-body"><main class="fm-wrap"><div class="fm-card"><div class="fm-head">'
       . '<h1>This link is not valid</h1><p class="fm-lead">Please use the full link from your offer email, or reply to that email and HR will help.</p></div></div></main></body>';
    exit;
}
hr_shortlist_send_resume(['resume_file' => $s['offer_file'], 'resume_name' => 'Offer letter - ' . $s['full_name'] . '.pdf']);
