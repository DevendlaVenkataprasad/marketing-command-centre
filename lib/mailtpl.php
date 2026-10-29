<?php
/**
 * The house style for every email the portal sends.
 *
 * Email is not the web. Gmail strips <style> blocks, Outlook renders with
 * Word, and almost nothing supports flexbox or grid — so this is tables and
 * inline styles throughout, which is ugly to write and the only thing that
 * renders the same everywhere.
 *
 * Every message is built twice: a plain-text part and an HTML part saying
 * the same thing. fabo_mail_wrap() turns the pieces into the finished HTML.
 *
 * ON LINKS. A message carries at most one: the portal's front door. It never
 * carries a private ?k= link, a row deep-link or a signed "next" target,
 * because a link that signs the holder straight in is a credential, and mail
 * is forwarded, quoted and left open on shared screens. The recipient goes
 * to the portal and signs in, and the message says where to look once there.
 */

require_once __DIR__ . '/ui.php';

/** The one address any message may point at: the portal's front door. */
function fabo_mail_home(): string
{
    return rtrim(fabo_portal_url(), '/') . '/login.php';
}

/* ------------------------------------------------------------- palette */

function fabo_mail_ink(): string   { return '#1d2330'; }
function fabo_mail_soft(): string  { return '#68707f'; }
function fabo_mail_line(): string  { return '#dfe3ea'; }
function fabo_mail_panel(): string { return '#f6f7f9'; }
function fabo_mail_accent(): string { return '#1f5fd0'; }

/* -------------------------------------------------------------- pieces */

/** A paragraph. */
function fabo_mail_p(string $html, string $extra = ''): string
{
    return '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:' . fabo_mail_ink()
         . ';' . $extra . '">' . $html . '</p>';
}

/** A small muted line, for the things that are context rather than content. */
function fabo_mail_note(string $html): string
{
    return '<p style="margin:0 0 14px;font-size:13px;line-height:1.55;color:'
         . fabo_mail_soft() . ';">' . $html . '</p>';
}

/**
 * The detail block: a label on the left, the value on the right.
 * $rows is [label => value], values already escaped.
 */
function fabo_mail_rows(array $rows): string
{
    if (!$rows) {
        return '';
    }
    /*
     * Labels in a column of their own, at the same left edge as the
     * paragraphs above and below. The old version sat the whole table in a
     * filled box with its own padding, so every label started fourteen
     * pixels right of the body text and the message read as two documents.
     *
     * A fixed label width rather than a percentage: percentages are
     * recalculated per client and Outlook rounds them differently, which is
     * how a tidy table arrives ragged.
     */
    $h = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
       . ' style="border-collapse:collapse;margin:0 0 20px;">';
    $i = 0;
    foreach ($rows as $label => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $top = $i++ > 0 ? 'border-top:1px solid ' . fabo_mail_line() . ';' : '';
        $h .= '<tr>'
            . '<td style="' . $top . 'padding:11px 14px 11px 0;font-size:11.5px;'
            . 'letter-spacing:.5px;text-transform:uppercase;font-weight:700;color:'
            . fabo_mail_soft() . ';vertical-align:top;width:132px;'
            . 'mso-line-height-rule:exactly;line-height:1.5;">'
            . e((string) $label) . '</td>'
            . '<td style="' . $top . 'padding:11px 0;font-size:14px;line-height:1.5;color:'
            . fabo_mail_ink() . ';vertical-align:top;word-break:break-word;">'
            . $value . '</td>'
            . '</tr>';
    }
    return $h . '</table>';
}

/**
 * The single button, which always points at the portal's front door.
 *
 * No other destination is offered, by design — see the note at the top.
 */
function fabo_mail_button(string $label = 'Open the portal'): string
{
    $url = fabo_mail_home();
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="margin:0 0 18px;"><tr><td style="border-radius:7px;background:'
         . fabo_mail_accent() . ';">'
         . '<a href="' . e($url) . '" style="display:inline-block;padding:11px 22px;'
         . 'font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">'
         . e($label) . '</a></td></tr></table>'
         . '<p style="margin:0 0 18px;font-size:12px;color:' . fabo_mail_soft()
         . ';word-break:break-all;">' . e($url) . '</p>';
}

/**
 * A button to one particular place, for the one case that needs it.
 *
 * The portal's front door cannot tell which account is meant when several
 * share a mailbox — the address matches all of them. This link belongs to
 * one account and opens that one, which is the whole point of sending it.
 */
function fabo_mail_own_link(string $url, string $label, string $note = ''): string
{
    if (trim($url) === '') {
        return '';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
         . ' style="margin:0 0 10px;"><tr><td style="border-radius:7px;background:'
         . fabo_mail_accent() . ';">'
         . '<a href="' . e($url) . '" style="display:inline-block;padding:11px 22px;'
         . 'font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">'
         . e($label) . '</a></td></tr></table>'
         . ($note !== ''
            ? '<p style="margin:0 0 6px;font-size:12.5px;line-height:1.55;color:'
              . fabo_mail_soft() . ';">' . e($note) . '</p>'
            : '')
         . '<p style="margin:0 0 18px;font-size:12px;color:' . fabo_mail_soft()
         . ';word-break:break-all;">' . e($url) . '</p>';
}

/** A quoted passage: a reason, a remark, a note somebody typed. */
function fabo_mail_quote(string $text): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
         . ' style="border-collapse:collapse;margin:0 0 18px;"><tr>'
         . '<td style="padding:12px 16px;background:' . fabo_mail_panel()
         . ';border-left:3px solid ' . fabo_mail_accent() . ';border-radius:0 6px 6px 0;'
         . 'font-size:14px;line-height:1.6;color:' . fabo_mail_ink() . ';white-space:pre-wrap;">'
         . e($text) . '</td></tr></table>';
}

/** A plain list of lines, for "what you can open" and the like. */
function fabo_mail_list(array $lines): string
{
    if (!$lines) {
        return '';
    }
    $h = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
       . ' style="border-collapse:collapse;margin:0 0 18px;">';
    foreach ($lines as $line) {
        $h .= '<tr><td style="padding:5px 0 5px 16px;font-size:14px;line-height:1.55;color:'
            . fabo_mail_ink() . ';border-left:2px solid ' . fabo_mail_line() . ';">'
            . $line . '</td></tr>';
    }
    return $h . '</table>';
}

/** A figure that should stand out — a one-time code, an amount. */
function fabo_mail_big(string $value, string $caption = ''): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
         . ' style="border-collapse:collapse;margin:0 0 18px;background:' . fabo_mail_panel()
         . ';border:1px solid ' . fabo_mail_line() . ';border-radius:8px;"><tr>'
         . '<td align="center" style="padding:18px 14px;">'
         . '<div style="font-size:26px;font-weight:700;letter-spacing:3px;color:'
         . fabo_mail_ink() . ';font-family:Consolas,Menlo,monospace;">' . e($value) . '</div>'
         . ($caption !== ''
            ? '<div style="margin-top:6px;font-size:12px;color:' . fabo_mail_soft() . ';">'
              . e($caption) . '</div>'
            : '')
         . '</td></tr></table>';
}

/* ------------------------------------------------------------ the shell */

/**
 * Wraps the pieces in the frame: sender's name at the top, content in the
 * middle, a plain footer. $brand is whose message it is — the portal's, or
 * a client's own organisation when they are writing to their people.
 */
function fabo_mail_wrap(string $brand, string $heading, string $inner,
                        string $preheader = ''): string
{
    $ink   = fabo_mail_ink();
    $soft  = fabo_mail_soft();
    $line  = fabo_mail_line();
    $panel = fabo_mail_panel();

    // The preheader is the grey line a mail list shows after the subject.
    // Hidden in the message itself, so it is not said twice.
    $pre = $preheader === '' ? '' :
        '<div style="display:none;max-height:0;overflow:hidden;opacity:0;'
        . 'mso-hide:all;font-size:1px;line-height:1px;color:#ffffff;">'
        . e($preheader) . str_repeat('&#8203;&nbsp;', 40) . '</div>';

    return '<!doctype html><html><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . e($heading) . '</title></head>'
         . '<body style="margin:0;padding:0;background:' . $panel . ';">'
         . $pre
         // Outer table: the grey page. Centring by table attribute, because
         // margin:auto is not reliable in Outlook.
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
         . ' style="border-collapse:collapse;background:' . $panel . ';">'
         . '<tr><td align="center" style="padding:24px 12px;">'

         // Inner table: the white card, held to a readable measure.
         . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"'
         . ' style="border-collapse:collapse;width:600px;max-width:100%;background:#ffffff;'
         . 'border:1px solid ' . $line . ';border-radius:10px;'
         . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'

         . '<tr><td style="padding:22px 28px 0;">'
         . '<div style="font-size:13px;font-weight:700;letter-spacing:.6px;'
         . 'text-transform:uppercase;color:' . fabo_mail_accent() . ';">' . e($brand) . '</div>'
         . '</td></tr>'

         . '<tr><td style="padding:14px 28px 0;">'
         . '<h1 style="margin:0 0 16px;font-size:21px;line-height:1.35;font-weight:650;color:'
         . $ink . ';">' . e($heading) . '</h1>'
         . '</td></tr>'

         . '<tr><td style="padding:0 28px 8px;">' . $inner . '</td></tr>'

         . '<tr><td style="padding:16px 28px 24px;border-top:1px solid ' . $line . ';">'
         . '<div style="font-size:12px;line-height:1.6;color:' . $soft . ';">'
         . e($brand) . '<br>'
         . 'This is an automated message — replying to it reaches us, but the quickest '
         . 'route is to sign in to the portal.'
         . '</div></td></tr>'

         . '</table></td></tr></table></body></html>';
}
