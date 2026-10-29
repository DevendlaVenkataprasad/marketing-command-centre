<?php
/**
 * The graphic that goes with a post.
 *
 * There is no image-generation service wired into this portal, and a picture
 * invented by one would be a picture of a company that does not exist —
 * plausible offices, plausible people, none of them real. For a brand's own
 * social account that is worse than no picture at all.
 *
 * So the graphic is composed, not generated: the headline the copywriter
 * wrote, set on the brand's colour, at the right size for the platform. It
 * is honest, it is instant, it costs nothing per post, and it is the kind of
 * thing a marketing team actually posts. The creative brief — visual
 * concept, design style, image_prompt — is still written and stored, so a
 * designer can work from it, or an image service can be added later without
 * changing anything around it.
 *
 * Two files come out of each one: an SVG, which is what to hand a designer,
 * and a PNG, which is what the platforms will accept.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google.php';   // fabo_env_or(), fabo_setting(), fabo_http()

/** Where generated creatives live. */
function fabo_media_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'media';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    /* Unlike uploads/, this folder has to be readable from outside: Instagram
       publishes by giving Meta a URL and having META fetch it, so a login
       page in front of the image means no post. What protects it instead is
       that every name is 32 random hex characters — the folder cannot be
       listed and a name cannot be guessed — and that nothing in it is ever
       executed. */
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "Options -Indexes\n"
          . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
          . "<FilesMatch \"\\.(php|phtml|php\\d|phar|cgi|pl|py|sh|htaccess)$\">\n"
          . "  Require all denied\n</FilesMatch>\n");
    }
    return $dir;
}

/** The public address of a generated creative. */
function fabo_media_url(string $file): string
{
    if ($file === '') {
        return '';
    }
    require_once __DIR__ . '/auth.php';
    return rtrim(fabo_portal_url(), '/') . '/media/' . rawurlencode($file);
}

/**
 * A font file for GD to set text with.
 *
 * Poppins ships with the portal (assets/fonts, SIL Open Font License, which
 * allows exactly this), so every graphic looks the same on any server. The
 * system fonts are only a fallback for a copy that is missing the folder.
 *
 * $weight is one of 'bold', 'semibold', 'medium', 'regular'; the old
 * true/false still works and means bold/regular.
 */
function fabo_creative_font($weight = false): string
{
    if (is_bool($weight)) {
        $weight = $weight ? 'bold' : 'regular';
    }
    static $found = [];
    if (isset($found[$weight])) {
        return $found[$weight];
    }
    $own = dirname(__DIR__) . '/assets/fonts/Poppins-'
         . ['bold' => 'Bold', 'semibold' => 'SemiBold', 'medium' => 'Medium',
            'regular' => 'Regular'][$weight] . '.ttf';
    $bold = in_array($weight, ['bold', 'semibold'], true);
    $candidates = array_merge([$own], $bold ? [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
    ] : [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/Library/Fonts/Arial.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
    ]);
    foreach ($candidates as $c) {
        if (is_file($c) && is_readable($c)) {
            return $found[$weight] = $c;
        }
    }
    return $found[$weight] = '';
}

/** The size a platform wants. Square works everywhere and is what both feeds favour. */
function fabo_creative_size(string $platform): array
{
    return strcasecmp($platform, 'linkedin') === 0 ? [1200, 1200] : [1080, 1080];
}

/**
 * A palette for a brand, derived from its name.
 *
 * Deterministic, so one brand always gets the same colours and a calendar
 * looks like one account rather than a paint chart. Each set is a deep
 * two-tone background, a bright accent for the highlights and the button,
 * and a second glow colour -- the combinations current product and agency
 * brands use, chosen to keep white text well above contrast minimums.
 */
function fabo_creative_palette(string $brand, string $style = ''): array
{
    $sets = [
        // bg        bg2        accent     glow
        ['#0B1229', '#1B2B5E', '#4F8CFF', '#7C5CFF'],   // midnight / electric blue
        ['#0A1F1C', '#0F3D36', '#2EE6A8', '#14B8A6'],   // deep teal / mint
        ['#1A0B2E', '#3B1263', '#C084FC', '#F472B6'],   // aubergine / orchid
        ['#111111', '#262626', '#FACC15', '#F97316'],   // carbon / signal yellow
        ['#2A0A12', '#5B1022', '#FB7185', '#F59E0B'],   // oxblood / coral
        ['#0C1A2B', '#123A5A', '#38BDF8', '#22D3EE'],   // navy / sky
    ];
    if (stripos($style, 'minimal') !== false || stripos($style, 'light') !== false) {
        $sets = [['#F8FAFC', '#E2E8F0', '#2563EB', '#7C3AED']];
    }
    $i = abs(crc32(mb_strtolower(trim($brand)) ?: 'brand')) % count($sets);
    [$bg, $bg2, $accent, $glow] = $sets[$i];
    $light = fabo_colour_light($bg);
    return [
        'bg'      => $bg,
        'panel'   => $bg2,
        'accent'  => $accent,
        'glow'    => $glow,
        'text'    => $light ? '#0F172A' : '#FFFFFF',
        'muted'   => $light ? '#475569' : '#C7D0E0',
        // Text on the accent-coloured button.
        'on'      => fabo_colour_light($accent) ? '#0B1020' : '#FFFFFF',
    ];
}

/** Is this colour light enough to need dark text on it? */
function fabo_colour_light(string $hex): bool
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) {
        return false;
    }
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    // Rec. 601 luma: green carries most of what the eye reads as brightness.
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150;
}

/** Break a headline into lines of at most $perLine characters. */
function fabo_wrap_words(string $text, int $perLine, int $maxLines = 4): array
{
    $text  = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    if ($text === '') {
        return [];
    }
    $lines = [];
    $line  = '';
    foreach (explode(' ', $text) as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if (mb_strlen($try) > $perLine && $line !== '') {
            $lines[] = $line;
            $line    = $word;
            if (count($lines) >= $maxLines) {
                break;
            }
        } else {
            $line = $try;
        }
    }
    if ($line !== '' && count($lines) < $maxLines) {
        $lines[] = $line;
    }
    return $lines;
}

/**
 * Lines that truly fit, measured with the font itself.
 *
 * Counting characters is not enough: "Master Marketing. The" is twenty-one
 * characters and wider than the card at headline size, because capitals and
 * bold are wide. Returns null when the text needs more than $maxLines lines
 * at this size, so the caller can try a smaller one.
 */
function fabo_wrap_fit(string $text, string $font, int $size, int $maxW, int $maxLines): ?array
{
    $text = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    if ($text === '') {
        return [];
    }
    $lines = [];
    $line  = '';
    foreach (explode(' ', $text) as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($line !== '' && fabo_text_width($try, $font, $size) > $maxW) {
            $lines[] = $line;
            $line    = $word;
        } else {
            $line = $try;
        }
        if (fabo_text_width($line, $font, $size) > $maxW) {
            return null;                    // one word wider than the card
        }
    }
    $lines[] = $line;
    return count($lines) <= $maxLines ? $lines : null;
}

/** How wide a line is, in pixels, at this size. */
function fabo_text_width(string $s, string $font, int $size): int
{
    $b = @imagettfbbox($size, 0, $font, $s);
    return $b ? abs($b[2] - $b[0]) : PHP_INT_MAX;
}

/** The largest size, from those given, at which the text fits. Returns [size, lines]. */
function fabo_fit_text(string $text, string $font, array $sizes, int $maxW, int $maxLines): array
{
    foreach ($sizes as $size) {
        $lines = fabo_wrap_fit($text, $font, (int) $size, $maxW, $maxLines);
        if ($lines !== null) {
            return [(int) $size, $lines];
        }
    }
    $size = (int) end($sizes);
    /* Still too long at the smallest size: keep what fits. */
    $lines = fabo_wrap_words($text, max(8, (int) floor($maxW / ($size * 0.62))), $maxLines);
    return [$size, $lines];
}

/** XML-safe text. */
function fabo_creative_xml(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Everything the card says, worked out once for both the PNG and the SVG.
 *
 * The call to action and the address at the foot come from the row itself
 * (cta, cta_url), so the graphic points where the post does.
 */
function fabo_creative_parts(array $row): array
{
    $headline = trim((string) ($row['headline'] ?? ''))
             ?: trim((string) ($row['linkedin_headline'] ?? ''))
             ?: trim((string) ($row['content_topic'] ?? ''));
    $url  = trim((string) ($row['cta_url'] ?? '')) ?: trim((string) ($row['source_url'] ?? ''));
    $host = strtolower((string) (@parse_url($url, PHP_URL_HOST) ?: ''));
    $host = preg_replace('~^www\.~', '', $host) ?? $host;
    return [
        'headline' => $headline,
        'support'  => trim((string) ($row['supporting_text'] ?? '')),
        'tag'      => mb_strtoupper(mb_substr(trim((string) ($row['content_pillar'] ?? '')), 0, 26)),
        'brand'    => mb_substr(trim((string) ($row['brand_name'] ?? '')), 0, 40),
        'cta'      => mb_substr(trim((string) ($row['cta'] ?? '')) ?: 'Learn More', 0, 24),
        'host'     => $host,
    ];
}

/**
 * The layout, in the card's own pixels, shared by both renderings.
 *
 * Measured with the font when there is one, so the headline is the largest
 * size at which it fits in four lines inside the margins.
 */
function fabo_creative_layout(array $parts, int $w, int $h, string $bold, string $regular): array
{
    $pad  = (int) round($w * 0.075);
    $maxW = $w - 2 * $pad;
    /* The room between the header and the footer, less a margin on each side. */
    $room = (int) round($h * 0.80) - (int) round($h * 0.20) - (int) round($h * 0.06);
    if ($bold !== '') {
        /* Largest headline at which headline and subtitle together fit the
           room, not just the width -- a long headline with a three-line
           subtitle otherwise runs into the footer. */
        [$ss, $sl] = $parts['support'] !== ''
            ? fabo_fit_text($parts['support'], $regular ?: $bold,
                  array_map(static fn($f) => (int) round($w * $f), [0.022, 0.020, 0.018]), $maxW, 3)
            : [0, []];
        $subH = $sl ? (int) round($h * 0.035) + count($sl) * (int) round($ss * 4 / 3 * 1.45) : 0;
        $sizes = array_map(static fn($f) => (int) round($w * $f),
                           [0.060, 0.054, 0.048, 0.043, 0.038, 0.034, 0.030]);
        $size  = 0;
        $lines = null;
        foreach ($sizes as $try) {
            /* Measured lines only: a size whose lines do not all fit the
               width is skipped, never wrapped by guesswork. */
            $fit = fabo_wrap_fit($parts['headline'], $bold, $try, $maxW, 4);
            if ($fit !== null
                && count($fit) * (int) round($try * 4 / 3 * 1.12) + $subH <= $room) {
                [$size, $lines] = [$try, $fit];
                break;
            }
        }
        if ($lines === null) {
            [$size, $lines] = fabo_fit_text($parts['headline'], $bold, [end($sizes)], $maxW, 4);
        }
    } else {
        $size  = (int) round($w * 0.060);
        $lines = array_slice(fabo_wrap_words($parts['headline'],
                     max(8, (int) floor($maxW / ($size * 0.6))), 4), 0, 4);
        $ss    = (int) round($w * 0.026);
        $sl    = $parts['support'] !== ''
               ? fabo_wrap_words($parts['support'], max(10, (int) floor($maxW / ($ss * 0.55))), 3) : [];
    }
    /* Sizes above are GD's: points at 96 dpi, the unit imagettfbbox measures
       in. On the card a point is 4/3 of a pixel, so spacing is set in pixels
       from that, or the lines would sit on top of each other. */
    $px     = (int) round($size * 4 / 3);
    $spx    = (int) round($ss * 4 / 3);
    $lead   = (int) round($px * 1.12);
    $sLead  = (int) round($spx * 1.45);
    $gap    = $sl ? (int) round($h * 0.035) : 0;
    $blockH = count($lines) * $lead + $gap + count($sl) * $sLead;

    /* The text block sits in the space between the header and the footer,
       a little above centre, where the eye lands first. */
    $headerB = (int) round($h * 0.20);
    $footerT = (int) round($h * 0.80);
    $top     = $headerB + max(0, (int) round(($footerT - $headerB - $blockH) * 0.45));

    return compact('pad', 'maxW', 'size', 'px', 'spx', 'lines', 'lead', 'ss', 'sl', 'sLead', 'gap',
                   'blockH', 'top', 'headerB', 'footerT');
}

/**
 * Compose the graphic as SVG, for a designer to open and adjust.
 *
 * The same card as the PNG -- same layout, same colours -- so what a
 * designer edits is what was posted.
 */
function fabo_creative_svg(array $row): string
{
    $platform = (string) ($row['platform'] ?? 'LinkedIn');
    [$w, $h]  = fabo_creative_size($platform);
    $p        = fabo_creative_palette((string) ($row['brand_name'] ?? ''),
                                      (string) ($row['design_style'] ?? ''));
    $parts    = fabo_creative_parts($row);
    $bold     = function_exists('imagettfbbox') ? fabo_creative_font('bold') : '';
    $L        = fabo_creative_layout($parts, $w, $h, $bold, fabo_creative_font('regular'));
    $pad      = $L['pad'];
    $font     = 'Poppins, \'Segoe UI\', Helvetica, Arial, sans-serif';
    $t        = 'fabo_creative_xml';

    $svg  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $svg .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h
          . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="'
          . $t((string) ($row['alt_text'] ?? $parts['headline'])) . '">' . "\n";
    $svg .= '<defs>'
          . '<linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
          . '<stop offset="0" stop-color="' . $p['bg'] . '"/><stop offset="1" stop-color="' . $p['panel'] . '"/>'
          . '</linearGradient>'
          . '<radialGradient id="g1"><stop offset="0" stop-color="' . $p['accent'] . '" stop-opacity=".45"/>'
          . '<stop offset="1" stop-color="' . $p['accent'] . '" stop-opacity="0"/></radialGradient>'
          . '<radialGradient id="g2"><stop offset="0" stop-color="' . $p['glow'] . '" stop-opacity=".35"/>'
          . '<stop offset="1" stop-color="' . $p['glow'] . '" stop-opacity="0"/></radialGradient>'
          . '<pattern id="dots" width="' . round($w / 30) . '" height="' . round($w / 30)
          . '" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.6" fill="' . $p['text']
          . '" fill-opacity=".07"/></pattern>'
          . '</defs>' . "\n";
    $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="url(#bg)"/>' . "\n";
    $svg .= '<circle cx="' . round($w * 0.92) . '" cy="' . round($h * 0.08) . '" r="' . round($w * 0.55)
          . '" fill="url(#g1)"/>' . "\n";
    $svg .= '<circle cx="' . round($w * 0.02) . '" cy="' . round($h * 0.98) . '" r="' . round($w * 0.5)
          . '" fill="url(#g2)"/>' . "\n";
    $svg .= '<rect width="' . $w . '" height="' . $h . '" fill="url(#dots)"/>' . "\n";
    $svg .= '<circle cx="' . round($w * 0.93) . '" cy="' . round($h * 0.86) . '" r="' . round($w * 0.17)
          . '" fill="none" stroke="' . $p['accent'] . '" stroke-opacity=".22" stroke-width="'
          . round($w * 0.012) . '"/>' . "\n";

    // Header: brand badge and category pill.
    $badge = (int) round($w * 0.062);
    $hy    = (int) round($h * 0.075);
    if ($parts['brand'] !== '') {
        $svg .= '<rect x="' . $pad . '" y="' . $hy . '" width="' . $badge . '" height="' . $badge
              . '" rx="' . round($badge * 0.28) . '" fill="' . $p['accent'] . '"/>' . "\n";
        $svg .= '<text x="' . ($pad + $badge / 2) . '" y="' . ($hy + $badge * 0.68)
              . '" text-anchor="middle" font-family="' . $font . '" font-weight="700" font-size="'
              . round($badge * 0.5) . '" fill="' . $p['on'] . '">'
              . $t(mb_strtoupper(mb_substr($parts['brand'], 0, 1))) . '</text>' . "\n";
        $svg .= '<text x="' . ($pad + $badge + round($w * 0.018)) . '" y="' . ($hy + $badge * 0.64)
              . '" font-family="' . $font . '" font-weight="600" font-size="' . round($w * 0.026)
              . '" fill="' . $p['text'] . '">' . $t($parts['brand']) . '</text>' . "\n";
    }
    if ($parts['tag'] !== '') {
        $fs = (int) round($w * 0.0175);
        $tw = (int) round(mb_strlen($parts['tag']) * $fs * 0.72 + $fs * 2.4);
        $th = (int) round($fs * 2.4);
        $tx = $w - $pad - $tw;
        $ty = $hy + (int) round(($badge - $th) / 2);
        $svg .= '<rect x="' . $tx . '" y="' . $ty . '" width="' . $tw . '" height="' . $th
              . '" rx="' . round($th / 2) . '" fill="' . $p['accent'] . '" fill-opacity=".14" stroke="'
              . $p['accent'] . '" stroke-opacity=".6" stroke-width="2"/>' . "\n";
        $svg .= '<text x="' . ($tx + $tw / 2) . '" y="' . ($ty + $th * 0.66)
              . '" text-anchor="middle" font-family="' . $font . '" font-weight="600" font-size="' . $fs
              . '" letter-spacing="2" fill="' . $p['accent'] . '">' . $t($parts['tag']) . '</text>' . "\n";
    }

    // The headline, its last line in the accent colour.
    $y = $L['top'] + (int) round($L['px'] * 0.92);
    $n = count($L['lines']);
    foreach ($L['lines'] as $i => $line) {
        $svg .= '<text x="' . $pad . '" y="' . ($y + $i * $L['lead'])
              . '" font-family="' . $font . '" font-weight="700" font-size="' . $L['px']
              . '" fill="' . ($n > 1 && $i === $n - 1 ? $p['accent'] : $p['text']) . '">'
              . $t($line) . '</text>' . "\n";
    }
    $sy = $y + ($n - 1) * $L['lead'] + $L['gap'] + $L['sLead'];
    foreach ($L['sl'] as $i => $line) {
        $svg .= '<text x="' . $pad . '" y="' . ($sy + $i * $L['sLead'])
              . '" font-family="' . $font . '" font-size="' . $L['spx'] . '" fill="' . $p['muted'] . '">'
              . $t($line) . '</text>' . "\n";
    }

    // Footer: a rule, the call to action as a button, and the address.
    $svg .= '<rect x="' . $pad . '" y="' . $L['footerT'] . '" width="' . ($w - 2 * $pad)
          . '" height="2" fill="' . $p['text'] . '" fill-opacity=".14"/>' . "\n";
    $bfs = (int) round($w * 0.023);
    $bh  = (int) round($bfs * 2.9);
    $bw  = (int) round(mb_strlen($parts['cta']) * $bfs * 0.62 + $bfs * 4.2);
    $by  = $L['footerT'] + (int) round(($h - $L['footerT'] - $bh) / 2);
    $svg .= '<rect x="' . $pad . '" y="' . $by . '" width="' . $bw . '" height="' . $bh
          . '" rx="' . round($bh / 2) . '" fill="' . $p['accent'] . '"/>' . "\n";
    $svg .= '<text x="' . ($pad + $bfs * 1.5) . '" y="' . ($by + $bh * 0.64)
          . '" font-family="' . $font . '" font-weight="600" font-size="' . $bfs . '" fill="' . $p['on'] . '">'
          . $t($parts['cta']) . ' &#8594;</text>' . "\n";
    if ($parts['host'] !== '') {
        $svg .= '<text x="' . ($w - $pad) . '" y="' . ($by + $bh * 0.64)
              . '" text-anchor="end" font-family="' . $font . '" font-weight="500" font-size="'
              . round($w * 0.021) . '" fill="' . $p['muted'] . '">' . $t($parts['host']) . '</text>' . "\n";
    }
    return $svg . '</svg>' . "\n";
}

/* ------------------------------------------------- pictures from the site */

/** Where pictures fetched from a website are kept, so each is fetched once. */
function fabo_creative_cache_dir(): string
{
    $dir = fabo_media_dir() . DIRECTORY_SEPARATOR . 'src';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * A picture from the company's website, as a GD image, or null.
 *
 * Fetched through fabo_url_open(), so the same address checks apply as to
 * the crawl, and kept on disk by its address so the eight posts of one site
 * fetch a logo once. SVG and ICO are skipped: GD cannot draw them.
 */
function fabo_creative_image(string $url)
{
    if ($url === '' || preg_match('~\.(svg|ico)(\?|$)~i', $url) || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $file = fabo_creative_cache_dir() . DIRECTORY_SEPARATOR . sha1($url) . '.bin';
    $data = is_file($file) ? (string) @file_get_contents($file) : '';
    if ($data === '') {
        require_once __DIR__ . '/ssrf.php';
        [$ok, $data] = fabo_url_open($url);
        if (!$ok || $data === '') {
            return null;
        }
        @file_put_contents($file, $data);
    }
    $im = @imagecreatefromstring($data);
    return $im ?: null;
}

/**
 * The company's logo, from what the crawl found on its homepage.
 *
 * The candidates are tried in the order the crawler ranked them. A tiny one
 * (a 32-pixel favicon) is kept only if nothing better loads, because blown
 * up it looks worse than the brand's initial.
 */
function fabo_creative_logo(array $row)
{
    $site = (int) ($row['site_id'] ?? 0);
    if ($site <= 0) {
        return null;
    }
    $s     = fabo_one('SELECT logo FROM website_sources WHERE id = ?', 'i', [$site]);
    $urls  = json_decode((string) ($s['logo'] ?? ''), true) ?: [];
    $small = null;
    foreach (array_slice($urls, 0, 6) as $u) {
        $im = fabo_creative_image((string) $u);
        if (!$im) {
            continue;
        }
        if (min(imagesx($im), imagesy($im)) >= 60) {
            return $im;
        }
        $small = $small ?: $im;
    }
    return $small && min(imagesx($small), imagesy($small)) >= 40 ? $small : null;
}

/** Up to three short points for the card, from what the post already says. */
function fabo_creative_points(array $row, array $parts): array
{
    $seen = mb_strtolower($parts['headline'] . ' ' . $parts['support']);
    $out  = [];
    foreach (['key_benefit', 'statistic', 'proof_point', 'solution'] as $f) {
        $v = trim((string) ($row[$f] ?? ''));
        /* The first clause, so a point reads as a point and not a paragraph. */
        $v = trim((string) preg_split('~(?<=[.!?;])\s|\s[—–-]\s~u', $v)[0], " .;");
        if ($v === '' || mb_strlen($v) < 4 || str_contains($seen, mb_strtolower($v))) {
            continue;
        }
        $out[] = $v;
        $seen .= ' ' . mb_strtolower($v);
        if (count($out) >= 3) {
            break;
        }
    }
    return $out;
}

/** The longest start of $s that fits $maxW, with an ellipsis if cut. */
function fabo_fit_line(string $s, string $font, int $size, int $maxW): string
{
    if (fabo_text_width($s, $font, $size) <= $maxW) {
        return $s;
    }
    $words = explode(' ', $s);
    while (count($words) > 1) {
        array_pop($words);
        $try = rtrim(implode(' ', $words), ' ,;:—–-') . '…';
        if (fabo_text_width($try, $font, $size) <= $maxW) {
            return $try;
        }
    }
    return mb_substr($s, 0, 12) . '…';
}

/* ---------------------------------------------------- the picture panel */

/**
 * Which illustration suits a post, from the post's own words.
 *
 * Six scenes, each matched by the words a post about that subject tends to
 * use. A post that matches none still gets a scene, chosen by its id, so a
 * site's calendar is not the same picture eight times.
 */
function fabo_creative_theme(array $row, array $avoid = []): string
{
    $hay = mb_strtolower(implode(' ', [
        $row['content_topic'] ?? '', $row['headline'] ?? '', $row['linkedin_headline'] ?? '',
        $row['content_pillar'] ?? '', $row['focus'] ?? '', $row['visual_concept'] ?? '']));
    $themes = [
        'learning' => ['learn', 'course', 'training', 'workshop', 'class', 'certif', 'skill',
                       'educat', 'ojt', 'bootcamp', 'guide', 'tips', 'sprint', 'mentor', 'study'],
        'people'   => ['community', 'team', 'people', 'network', 'alumni', 'student', 'youth',
                       'cohort', 'together', 'collaborat', 'family', 'founders', 'members', 'women'],
        'idea'     => ['idea', 'innovat', 'startup', 'launch', 'incubat', 'venture', 'mvp',
                       'technolog', ' ai ', 'ai-', 'product', 'prototype', 'invent', 'creat'],
        'event'    => ['event', 'register', 'apply', 'deadline', 'batch', 'join', 'webinar',
                       'announce', 'calendar', 'conference', 'summit', 'open now', 'applications'],
        'shield'   => ['ipr', 'patent', 'intellectual', 'legal', 'secur', 'protect', 'trust',
                       'complian', 'policy', 'privacy', 'safe'],
        'growth'   => ['fund', 'capital', 'invest', 'growth', 'revenue', 'career', 'placement',
                       'job', 'hire', 'salary', 'earn', 'stipend', 'result', 'data', 'scale',
                       'market', 'sales', 'profit'],
    ];
    $best = '';
    $top  = 0;
    foreach ($themes as $name => $words) {
        if (in_array($name, $avoid, true)) {
            continue;                       // already used nearby: another, for variety
        }
        $n = 0;
        foreach ($words as $w) {
            $n += substr_count(' ' . $hay . ' ', $w);
        }
        if ($n > $top) {
            [$best, $top] = [$name, $n];
        }
    }
    if ($best === '') {
        $names = array_values(array_diff(array_keys($themes), $avoid)) ?: array_keys($themes);
        $best  = $names[abs((int) ($row['id'] ?? crc32($hay))) % count($names)];
    }
    return $best;
}

/**
 * A picture made for the post by an image model, or null.
 *
 * Only when the Gemini key is allowed to make images -- the free tier is
 * not. A refusal is remembered for six hours, so a portal without image
 * quota does not spend a second per post asking. The picture is asked for
 * without any words in it: the card sets its own type, and models spell.
 */
function fabo_creative_ai_picture(array $row, array $p)
{
    $model = trim(fabo_env_or('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'));
    $key   = trim(fabo_env_or('GEMINI_API_KEY'));
    if ($key === '' || $model === '' || strtolower($model) === 'off') {
        return null;
    }
    if ((int) fabo_setting('gemini_image_off_until', '0') > time()) {
        return null;
    }
    $subject = trim((string) ($row['visual_concept'] ?? '')) ?: trim((string) ($row['image_prompt'] ?? ''))
            ?: trim((string) ($row['content_topic'] ?? ''));
    $prompt = 'Flat vector illustration in a clean, modern, minimal style for a professional '
            . 'social media post. Absolutely no text, letters, numbers, words or logos anywhere. '
            . 'Subject: ' . mb_substr($subject, 0, 400) . '. '
            . 'Colour palette: ' . $p['bg'] . ', ' . $p['accent'] . ', ' . $p['glow'] . ' and white. '
            . 'Simple shapes, soft gradients, one clear focal point, centred, generous empty space '
            . 'around it, wide 16:9 composition.';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model)
         . ':generateContent?key=' . rawurlencode($key);
    [$ok, $res, $status] = fabo_http('POST', $url,
        ['contents' => [['parts' => [['text' => $prompt]]]],
         'generationConfig' => ['responseModalities' => ['IMAGE']]],
        ['Content-Type: application/json'], 60);
    if (!$ok) {
        if (in_array($status, [400, 403, 404, 429], true)) {
            try {
                fabo_setting_put('gemini_image_off_until', (string) (time() + 6 * 3600));
            } catch (Throwable $e) {
                // Remembering is a saving, not a requirement.
            }
        }
        return null;
    }
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $part) {
        $data = $part['inlineData']['data'] ?? '';
        if ($data !== '') {
            $im = @imagecreatefromstring((string) base64_decode($data));
            return $im ?: null;
        }
    }
    return null;
}

/**
 * Draw one of the illustrations into the picture panel.
 *
 * Every scene is laid out on the same grid -- fractions of the panel, with
 * sizes taken from its height -- so each sits centred with the same margins
 * whatever the card's size. $d carries the drawing helpers of the card.
 */
function fabo_creative_scene($im, int $x, int $y, int $w, int $h, array $p, int $k,
                             string $theme, array $d): void
{
    $col = $d['col'];
    $tint = $d['tint'];
    $rounded = $d['rounded'];
    $glow = $d['glow'];
    $X = static fn(float $f) => $x + (int) round($w * $f);
    $Y = static fn(float $f) => $y + (int) round($h * $f);
    $L = static fn(float $f) => (int) round($h * $f);
    $line = static function (int $x1, int $y1, int $x2, int $y2, $c, int $t) use ($im) {
        imagesetthickness($im, max(1, $t));
        imageline($im, $x1, $y1, $x2, $y2, $c);
        imagesetthickness($im, 1);
    };
    $spark = static function (float $fx, float $fy, float $size, float $o = 0.85)
                     use ($im, $X, $Y, $L, $col, $p) {
        $sx = $X($fx);
        $sy = $Y($fy);
        $s  = max(2, $L($size));
        imagefilledpolygon($im, [$sx, $sy - $s * 2, $sx + (int) ($s / 2), $sy, $sx, $sy + $s * 2,
                                 $sx - (int) ($s / 2), $sy], $col($p['accent'], $o));
        imagefilledpolygon($im, [$sx - $s * 2, $sy, $sx, $sy - (int) ($s / 2), $sx + $s * 2, $sy,
                                 $sx, $sy + (int) ($s / 2)], $col($p['accent'], $o));
    };
    $shadow = static function (float $x1, float $x2, float $fy) use ($tint, $X, $Y, $L) {
        $tint($X($x1), $Y($fy), $X($x2) - $X($x1), $L(0.045), $L(0.022), '#000000', 22);
    };
    $light   = fabo_colour_light($p['bg']);
    $ink     = $light ? '#1E293B' : '#F8FAFC';    // the scene's line colour on this palette
    $soft    = $light ? '#CBD5E1' : '#E2E8F0';
    $skin    = ['#F2C9A5', '#C68B59', '#8D5524'];

    // The panel itself: a soft tint of the accent, with a glow off-centre.
    $tint($x, $y, $w, $h, (int) round($w * 0.03), $p['accent'], 12);
    $glow($X(0.72), $Y(0.30), $L(0.70), $p['glow'], 0.16);
    $glow($X(0.25), $Y(0.85), $L(0.55), $p['accent'], 0.10);

    switch ($theme) {
        case 'learning':
            $shadow(0.28, 0.72, 0.80);
            // Laptop: screen, the lesson on it, the base.
            $rounded($X(0.335), $Y(0.10), $X(0.665) - $X(0.335), $Y(0.70) - $Y(0.10), $L(0.04),
                     $col('#FFFFFF', 0.97));
            $rounded($X(0.35), $Y(0.15), $X(0.65) - $X(0.35), $Y(0.65) - $Y(0.15), $L(0.025),
                     $col($p['accent'], 0.92));
            imagefilledellipse($im, $X(0.5), $Y(0.40), $L(0.24), $L(0.24), $col('#FFFFFF', 0.96));
            $t = $L(0.05);
            imagefilledpolygon($im, [$X(0.5) - (int) ($t * 0.7), $Y(0.40) - $t,
                                     $X(0.5) + (int) ($t * 1.1), $Y(0.40),
                                     $X(0.5) - (int) ($t * 0.7), $Y(0.40) + $t], $col($p['accent']));
            $rounded($X(0.375), $Y(0.56), $X(0.625) - $X(0.375), $L(0.025), $L(0.012),
                     $col('#FFFFFF', 0.55));
            $rounded($X(0.375), $Y(0.56), (int) (($X(0.625) - $X(0.375)) * 0.45), $L(0.025),
                     $L(0.012), $col('#FFFFFF', 0.95));
            $rounded($X(0.29), $Y(0.70), $X(0.71) - $X(0.29), $L(0.06), $L(0.03), $col($soft));
            // Graduation cap, left.
            $cx = $X(0.16);
            $cy = $Y(0.36);
            $cw = $L(0.36);
            imagefilledpolygon($im, [$cx - (int) ($cw / 2), $cy, $cx, $cy - $L(0.10),
                                     $cx + (int) ($cw / 2), $cy, $cx, $cy + $L(0.10)], $col($ink, 0.92));
            $rounded($cx - $L(0.10), $cy + $L(0.03), $L(0.20), $L(0.11), $L(0.03), $col($ink, 0.80));
            $line($cx + $L(0.13), $cy + $L(0.015), $cx + $L(0.13), $cy + $L(0.16), $col($p['accent']), $L(0.012));
            imagefilledellipse($im, $cx + $L(0.13), $cy + $L(0.17), $L(0.045), $L(0.045), $col($p['accent']));
            // Open book, right.
            $bx = $X(0.84);
            $by = $Y(0.60);
            $bw = $L(0.20);
            imagefilledpolygon($im, [$bx, $by, $bx - $bw, $by - $L(0.05), $bx - $bw, $by + $L(0.17),
                                     $bx, $by + $L(0.22)], $col('#FFFFFF', 0.96));
            imagefilledpolygon($im, [$bx, $by, $bx + $bw, $by - $L(0.05), $bx + $bw, $by + $L(0.17),
                                     $bx, $by + $L(0.22)], $col('#FFFFFF', 0.86));
            $line($bx, $by, $bx, $by + $L(0.22), $col($p['accent']), $L(0.012));
            foreach ([0.03, 0.07, 0.11] as $o) {
                $line($bx - $bw + $L(0.03), $by - $L(0.02) + $L($o), $bx - $L(0.03), $by + $L($o + 0.01),
                      $col('#94A3B8'), $L(0.008));
            }
            // A bright idea above the book.
            imagefilledellipse($im, $X(0.84), $Y(0.24), $L(0.13), $L(0.13), $col($p['accent']));
            $rounded($X(0.84) - $L(0.03), $Y(0.31), $L(0.06), $L(0.04), $L(0.01), $col($ink, 0.85));
            $spark(0.25, 0.74, 0.018);
            $spark(0.58, 0.05, 0.014);
            $spark(0.94, 0.48, 0.012);
            break;

        case 'people':
            $shadow(0.18, 0.82, 0.86);
            // Three people, the centre one forward.
            foreach ([[0.31, 0.86, $soft, $skin[1]], [0.69, 0.86, $p['glow'], $skin[2]],
                      [0.50, 1.0, $p['accent'], $skin[0]]] as [$fx, $sc, $body, $face]) {
                $cx = $X($fx);
                $hr = $L(0.20 * $sc);
                $bw = $L(0.40 * $sc);
                $top = $Y(0.86) - $L(0.36 * $sc);
                $rounded($cx - (int) ($bw / 2), $top, $bw, $Y(0.86) - $top, (int) ($bw * 0.42), $col($body));
                imagefilledellipse($im, $cx, $top - (int) ($hr * 0.62), $hr, $hr, $col($face));
                // Hair, a simple cap of darker colour.
                imagefilledarc($im, $cx, $top - (int) ($hr * 0.62), $hr, $hr, 180, 360,
                               $col('#1F2937', 0.85), IMG_ARC_PIE);
            }
            // Speech bubbles: one each side, the conversation.
            $rounded($X(0.08), $Y(0.10), $X(0.25) - $X(0.08), $L(0.20), $L(0.05), $col('#FFFFFF', 0.96));
            imagefilledpolygon($im, [$X(0.20), $Y(0.10) + $L(0.19), $X(0.23), $Y(0.10) + $L(0.19),
                                     $X(0.235), $Y(0.10) + $L(0.27)], $col('#FFFFFF', 0.96));
            $rounded($X(0.10), $Y(0.10) + $L(0.06), (int) (($X(0.23) - $X(0.10)) * 0.9), $L(0.025),
                     $L(0.012), $col('#94A3B8'));
            $rounded($X(0.10), $Y(0.10) + $L(0.11), (int) (($X(0.23) - $X(0.10)) * 0.6), $L(0.025),
                     $L(0.012), $col('#CBD5E1'));
            $rounded($X(0.76), $Y(0.06), $X(0.92) - $X(0.76), $L(0.18), $L(0.05), $col($p['accent']));
            imagefilledpolygon($im, [$X(0.78), $Y(0.06) + $L(0.17), $X(0.81), $Y(0.06) + $L(0.17),
                                     $X(0.77), $Y(0.06) + $L(0.25)], $col($p['accent']));
            imagefilledellipse($im, $X(0.81), $Y(0.06) + $L(0.09), $L(0.035), $L(0.035), $col('#FFFFFF'));
            imagefilledellipse($im, $X(0.84), $Y(0.06) + $L(0.09), $L(0.035), $L(0.035), $col('#FFFFFF'));
            imagefilledellipse($im, $X(0.87), $Y(0.06) + $L(0.09), $L(0.035), $L(0.035), $col('#FFFFFF'));
            // A heart between them.
            $hx = $X(0.60);
            $hy = $Y(0.14);
            $hs = $L(0.045);
            imagefilledellipse($im, $hx - $hs, $hy, $hs * 2, $hs * 2, $col($p['accent']));
            imagefilledellipse($im, $hx + $hs, $hy, $hs * 2, $hs * 2, $col($p['accent']));
            imagefilledpolygon($im, [$hx - $hs * 2, $hy + (int) ($hs * 0.3), $hx + $hs * 2,
                                     $hy + (int) ($hs * 0.3), $hx, $hy + $hs * 2], $col($p['accent']));
            $spark(0.40, 0.08, 0.014);
            $spark(0.95, 0.62, 0.016);
            $spark(0.05, 0.62, 0.012);
            break;

        case 'idea':
            $shadow(0.38, 0.62, 0.86);
            // The bulb, lit.
            $glow($X(0.5), $Y(0.38), $L(0.50), $p['accent'], 0.30);
            $r = $L(0.40);
            imagefilledellipse($im, $X(0.5), $Y(0.38), $r, $r, $col($p['accent']));
            imagefilledellipse($im, $X(0.5) - (int) ($r * 0.18), $Y(0.38) - (int) ($r * 0.18),
                               (int) ($r * 0.22), (int) ($r * 0.22), $col('#FFFFFF', 0.55));
            $rounded($X(0.5) - $L(0.085), $Y(0.56), $L(0.17), $L(0.07), $L(0.02), $col($ink, 0.9));
            $rounded($X(0.5) - $L(0.07), $Y(0.645), $L(0.14), $L(0.05), $L(0.02), $col($ink, 0.75));
            $rounded($X(0.5) - $L(0.04), $Y(0.71), $L(0.08), $L(0.04), $L(0.02), $col($ink, 0.6));
            // Filament.
            $fy = $Y(0.42);
            $line($X(0.5) - $L(0.06), $fy, $X(0.5) - $L(0.02), $fy - $L(0.05), $col('#FFFFFF'), $L(0.012));
            $line($X(0.5) - $L(0.02), $fy - $L(0.05), $X(0.5) + $L(0.02), $fy, $col('#FFFFFF'), $L(0.012));
            $line($X(0.5) + $L(0.02), $fy, $X(0.5) + $L(0.06), $fy - $L(0.05), $col('#FFFFFF'), $L(0.012));
            // Rays.
            foreach ([200, 235, 270, 305, 340] as $deg) {
                $a  = deg2rad($deg);
                $r1 = $r * 0.62;
                $r2 = $r * 0.80;
                $line($X(0.5) + (int) ($r1 * cos($a)), $Y(0.38) + (int) ($r1 * sin($a)),
                      $X(0.5) + (int) ($r2 * cos($a)), $Y(0.38) + (int) ($r2 * sin($a)),
                      $col($p['accent']), $L(0.022));
            }
            // A gear, left.
            $gx = $X(0.19);
            $gy = $Y(0.58);
            $gr = $L(0.24);
            for ($i = 0; $i < 8; $i++) {
                $a = deg2rad($i * 45);
                imagefilledellipse($im, $gx + (int) ($gr * 0.5 * cos($a)), $gy + (int) ($gr * 0.5 * sin($a)),
                                   (int) ($gr * 0.3), (int) ($gr * 0.3), $col($ink, 0.85));
            }
            imagefilledellipse($im, $gx, $gy, $gr, $gr, $col($ink, 0.85));
            imagefilledellipse($im, $gx, $gy, (int) ($gr * 0.38), (int) ($gr * 0.38), $col($p['accent']));
            // A rocket, right, on its way up.
            $rx = $X(0.80);
            $ry = $Y(0.44);
            $rw = $L(0.16);
            imagefilledpolygon($im, [$rx - (int) ($rw * 0.5), $ry - $L(0.06), $rx, $ry - $L(0.30),
                                     $rx + (int) ($rw * 0.5), $ry - $L(0.06), $rx + (int) ($rw * 0.5),
                                     $ry + $L(0.20), $rx - (int) ($rw * 0.5), $ry + $L(0.20)],
                               $col('#FFFFFF', 0.96));
            imagefilledellipse($im, $rx, $ry, (int) ($rw * 0.55), (int) ($rw * 0.55), $col($p['accent']));
            imagefilledpolygon($im, [$rx - (int) ($rw * 0.5), $ry + $L(0.05), $rx - $rw, $ry + $L(0.24),
                                     $rx - (int) ($rw * 0.5), $ry + $L(0.20)], $col($p['accent']));
            imagefilledpolygon($im, [$rx + (int) ($rw * 0.5), $ry + $L(0.05), $rx + $rw, $ry + $L(0.24),
                                     $rx + (int) ($rw * 0.5), $ry + $L(0.20)], $col($p['accent']));
            imagefilledpolygon($im, [$rx - (int) ($rw * 0.3), $ry + $L(0.21), $rx, $ry + $L(0.36),
                                     $rx + (int) ($rw * 0.3), $ry + $L(0.21)], $col($p['glow']));
            $spark(0.33, 0.12, 0.016);
            $spark(0.66, 0.70, 0.014);
            $spark(0.93, 0.16, 0.012);
            break;

        case 'event':
            $shadow(0.33, 0.67, 0.88);
            // The calendar, with the day ticked.
            $cx1 = $X(0.37);
            $cx2 = $X(0.63);
            $cy1 = $Y(0.10);
            $cy2 = $Y(0.84);
            $rounded($cx1, $cy1, $cx2 - $cx1, $cy2 - $cy1, $L(0.05), $col('#FFFFFF', 0.97));
            $rounded($cx1, $cy1, $cx2 - $cx1, $L(0.20), $L(0.05), $col($p['accent']));
            imagefilledrectangle($im, $cx1, $cy1 + $L(0.12), $cx2, $cy1 + $L(0.20), $col($p['accent']));
            foreach ([0.30, 0.70] as $f) {
                $rx = $cx1 + (int) (($cx2 - $cx1) * $f);
                $rounded($rx - $L(0.018), $cy1 - $L(0.05), $L(0.036), $L(0.11), $L(0.018), $col($ink, 0.9));
            }
            $gw = ($cx2 - $cx1 - $L(0.10)) / 4;
            for ($r = 0; $r < 3; $r++) {
                for ($c = 0; $c < 4; $c++) {
                    $gx = $cx1 + $L(0.05) + (int) ($c * $gw);
                    $gy = $cy1 + $L(0.27) + (int) ($r * $L(0.16));
                    $hit = $r === 1 && $c === 2;
                    $rounded($gx + $L(0.012), $gy, (int) $gw - $L(0.024), $L(0.12), $L(0.025),
                             $hit ? $col($p['accent']) : $col('#E2E8F0'));
                    if ($hit) {
                        $mx = $gx + (int) ($gw / 2);
                        $my = $gy + $L(0.06);
                        $line($mx - $L(0.03), $my, $mx - $L(0.008), $my + $L(0.025), $col('#FFFFFF'), $L(0.014));
                        $line($mx - $L(0.008), $my + $L(0.025), $mx + $L(0.035), $my - $L(0.025),
                              $col('#FFFFFF'), $L(0.014));
                    }
                }
            }
            // A megaphone, left, calling it out.
            $mx = $X(0.20);
            $my = $Y(0.46);
            imagefilledpolygon($im, [$mx + $L(0.12), $my - $L(0.05), $mx - $L(0.10), $my - $L(0.18),
                                     $mx - $L(0.10), $my + $L(0.18), $mx + $L(0.12), $my + $L(0.05)],
                               $col($p['accent']));
            $rounded($mx + $L(0.10), $my - $L(0.06), $L(0.07), $L(0.12), $L(0.02), $col($ink, 0.85));
            imagefilledellipse($im, $mx - $L(0.10), $my, $L(0.08), $L(0.36), $col($p['glow']));
            $rounded($mx + $L(0.02), $my + $L(0.05), $L(0.045), $L(0.12), $L(0.02), $col($ink, 0.75));
            foreach ([0.07, 0.13] as $o) {
                $line($mx - $L(0.17 + $o), $my - $L(0.10 + $o * 0.4), $mx - $L(0.17 + $o),
                      $my + $L(0.10 + $o * 0.4), $col($p['accent'], 0.7), $L(0.016));
            }
            // A clock, right.
            $kx = $X(0.81);
            $ky = $Y(0.42);
            imagefilledellipse($im, $kx, $ky, $L(0.36), $L(0.36), $col($p['accent']));
            imagefilledellipse($im, $kx, $ky, $L(0.30), $L(0.30), $col('#FFFFFF', 0.97));
            $line($kx, $ky, $kx, $ky - $L(0.10), $col('#1E293B'), $L(0.016));
            $line($kx, $ky, $kx + $L(0.08), $ky + $L(0.03), $col('#1E293B'), $L(0.016));
            imagefilledellipse($im, $kx, $ky, $L(0.035), $L(0.035), $col($p['accent']));
            $spark(0.28, 0.10, 0.014);
            $spark(0.70, 0.86, 0.016);
            $spark(0.93, 0.80, 0.012);
            break;

        case 'shield':
            $shadow(0.38, 0.62, 0.90);
            // The shield, with its tick.
            $cx  = $X(0.5);
            $top = $Y(0.08);
            $hw  = $L(0.26);
            $pts = [$cx - $hw, $top + $L(0.08), $cx, $top, $cx + $hw, $top + $L(0.08),
                    $cx + $hw, $Y(0.44)];
            for ($i = 1; $i <= 8; $i++) {              // the curve down to the point
                $t = $i / 8;
                $pts[] = (int) ((1 - $t) ** 2 * ($cx + $hw) + 2 * (1 - $t) * $t * ($cx + $hw) + $t ** 2 * $cx);
                $pts[] = (int) ((1 - $t) ** 2 * $Y(0.44) + 2 * (1 - $t) * $t * $Y(0.72) + $t ** 2 * $Y(0.88));
            }
            for ($i = 7; $i >= 0; $i--) {
                $t = $i / 8;
                $pts[] = (int) ((1 - $t) ** 2 * ($cx - $hw) + 2 * (1 - $t) * $t * ($cx - $hw) + $t ** 2 * $cx);
                $pts[] = (int) ((1 - $t) ** 2 * $Y(0.44) + 2 * (1 - $t) * $t * $Y(0.72) + $t ** 2 * $Y(0.88));
            }
            imagefilledpolygon($im, $pts, $col($p['accent']));
            $line($cx - $L(0.10), $Y(0.44), $cx - $L(0.025), $Y(0.52), $col('#FFFFFF'), $L(0.035));
            $line($cx - $L(0.025), $Y(0.52), $cx + $L(0.12), $Y(0.33), $col('#FFFFFF'), $L(0.035));
            // A document, left.
            $dx = $X(0.15);
            $dy = $Y(0.18);
            $dw = $L(0.30);
            $dh = $L(0.58);
            $rounded($dx, $dy, $dw, $dh, $L(0.03), $col('#FFFFFF', 0.96));
            imagefilledpolygon($im, [$dx + $dw - $L(0.08), $dy, $dx + $dw, $dy + $L(0.08),
                                     $dx + $dw - $L(0.08), $dy + $L(0.08)], $col('#CBD5E1'));
            foreach ([0.14, 0.22, 0.30, 0.38] as $i => $o) {
                $rounded($dx + $L(0.04), $dy + $L($o), (int) ($dw * ($i === 3 ? 0.4 : 0.72)), $L(0.025),
                         $L(0.012), $col('#CBD5E1'));
            }
            imagefilledellipse($im, $dx + $dw - $L(0.07), $dy + $dh - $L(0.08), $L(0.09), $L(0.09),
                               $col($p['accent']));
            // A padlock, right.
            $lx = $X(0.83);
            $ly = $Y(0.50);
            $sw = $L(0.15);
            /* The shackle as one filled band -- outer arc out, inner arc back --
               because GD's thick arcs come out ragged. */
            $band = [];
            $ro = (int) ($sw / 2) + $L(0.017);
            $ri = (int) ($sw / 2) - $L(0.017);
            $vy = $L(0.11);
            for ($i = 0; $i <= 24; $i++) {
                $a = M_PI + M_PI * $i / 24;
                $band[] = $lx + (int) round($ro * cos($a));
                $band[] = $ly - $L(0.02) + (int) round(($vy + $L(0.017)) * sin($a));
            }
            $band[] = $lx + $ro;
            $band[] = $ly + $L(0.02);
            $band[] = $lx + $ri;
            $band[] = $ly + $L(0.02);
            for ($i = 24; $i >= 0; $i--) {
                $a = M_PI + M_PI * $i / 24;
                $band[] = $lx + (int) round($ri * cos($a));
                $band[] = $ly - $L(0.02) + (int) round(($vy - $L(0.017)) * sin($a));
            }
            $band[] = $lx - $ri;
            $band[] = $ly + $L(0.02);
            $band[] = $lx - $ro;
            $band[] = $ly + $L(0.02);
            imagefilledpolygon($im, $band, $col($ink));
            $rounded($lx - $L(0.13), $ly, $L(0.26), $L(0.22), $L(0.04), $col($p['glow']));
            imagefilledellipse($im, $lx, $ly + $L(0.09), $L(0.05), $L(0.05), $col('#1E293B'));
            $rounded($lx - $L(0.012), $ly + $L(0.09), $L(0.024), $L(0.06), $L(0.01), $col('#1E293B'));
            $spark(0.33, 0.10, 0.014);
            $spark(0.67, 0.78, 0.016);
            $spark(0.95, 0.18, 0.012);
            break;

        default:    // growth
            $shadow(0.10, 0.92, 0.86);
            $base = $Y(0.82);
            $bw   = (int) round($w * 0.065);
            $gap  = (int) round($w * 0.03);
            $bx   = $X(0.10);
            $tops = [];
            foreach ([0.28, 0.44, 0.36, 0.58, 0.72] as $i => $f) {
                $bh = $L($f);
                $xx = $bx + $i * ($bw + $gap);
                $tint($xx, $base - $bh, $bw, $bh, (int) round($bw * 0.22),
                      $i === 4 ? $p['accent'] : $ink, $i === 4 ? 95 : 22 + $i * 6);
                $tops[] = [$xx + (int) ($bw / 2), $base - $bh - $L(0.07)];
            }
            for ($i = 1; $i < count($tops); $i++) {
                $line($tops[$i - 1][0], $tops[$i - 1][1], $tops[$i][0], $tops[$i][1],
                      $col($p['accent']), (int) round($k * 3));
            }
            foreach ($tops as [$tx, $ty]) {
                imagefilledellipse($im, $tx, $ty, $k * 14, $k * 14, $col($p['accent']));
                imagefilledellipse($im, $tx, $ty, $k * 6, $k * 6, $col($p['bg']));
            }
            // A floating result card.
            $cx = $X(0.58);
            $cy = $Y(0.18);
            $cw = $X(0.92) - $cx;
            $ch = $L(0.56);
            $tint($cx + $k * 8, $cy + $k * 10, $cw, $ch, $k * 18, '#000000', 18);
            $rounded($cx, $cy, $cw, $ch, $k * 18, $col('#FFFFFF', 0.97));
            $av = (int) ($ch * 0.26);
            imagefilledellipse($im, $cx + (int) ($cw * 0.14), $cy + (int) ($ch * 0.26), $av, $av,
                               $col($p['accent']));
            $rounded($cx + (int) ($cw * 0.27), $cy + (int) ($ch * 0.17), (int) ($cw * 0.58), (int) ($ch * 0.07),
                     (int) ($ch * 0.035), $col('#0F172A', 0.75));
            $rounded($cx + (int) ($cw * 0.27), $cy + (int) ($ch * 0.30), (int) ($cw * 0.38), (int) ($ch * 0.06),
                     (int) ($ch * 0.03), $col('#94A3B8', 0.9));
            $rounded($cx + (int) ($cw * 0.08), $cy + (int) ($ch * 0.52), (int) ($cw * 0.84), (int) ($ch * 0.06),
                     (int) ($ch * 0.03), $col('#CBD5E1'));
            $rounded($cx + (int) ($cw * 0.08), $cy + (int) ($ch * 0.64), (int) ($cw * 0.62), (int) ($ch * 0.06),
                     (int) ($ch * 0.03), $col('#CBD5E1'));
            $rounded($cx + (int) ($cw * 0.08), $cy + (int) ($ch * 0.78), (int) ($cw * 0.30), (int) ($ch * 0.11),
                     (int) ($ch * 0.055), $col($p['accent']));
            $spark(0.50, 0.12, 0.016);
            $spark(0.96, 0.12, 0.012);
            $spark(0.05, 0.16, 0.012);
            break;
    }
}

/**
 * A blank card in the brand's colours, drawn at twice its size, with the
 * helpers everything on it is drawn with.
 *
 * Shared by the post graphic and the carousel slides, so a carousel looks
 * like the same brand as the post beside it. Returns the image ($im), the
 * scale ($k), its size at that scale ($W, $H) and the helpers: $rgb, $col,
 * $glow, $rounded, $tint, $textMid and $picture.
 */
function fabo_creative_canvas(int $w, int $h, array $p): array
{
    $k  = 2;                                    // supersampling factor
    $W  = $w * $k;
    $H  = $h * $k;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    imagesavealpha($im, false);

    $rgb = static function (string $hex): array {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    };
    /* GD's alpha runs 0 (solid) to 127 (clear); $o here is opacity 0..1. */
    $col = static function (string $hex, float $o = 1.0) use ($im, $rgb) {
        [$r, $g, $b] = $rgb($hex);
        return imagecolorallocatealpha($im, $r, $g, $b, (int) round(127 * (1 - max(0, min(1, $o)))));
    };

    // Background: a diagonal two-tone gradient, a band at a time.
    [$r1, $g1, $b1] = $rgb($p['bg']);
    [$r2, $g2, $b2] = $rgb($p['panel']);
    for ($y = 0; $y < $H; $y += 2) {
        $t = $y / max(1, $H - 1);
        $c = imagecolorallocate($im, (int) round($r1 + ($r2 - $r1) * $t),
                                (int) round($g1 + ($g2 - $g1) * $t), (int) round($b1 + ($b2 - $b1) * $t));
        imagefilledrectangle($im, 0, $y, $W, $y + 1, $c);
    }
    // Soft glows: many faint discs, smallest on top, which reads as a blur.
    $glow = static function (int $cx, int $cy, int $r, string $hex, float $peak) use ($im, $col) {
        $steps = 36;
        for ($i = $steps; $i >= 1; $i--) {
            $rr = (int) round($r * $i / $steps);
            imagefilledellipse($im, $cx, $cy, $rr * 2, $rr * 2, $col($hex, $peak / $steps * 1.6));
        }
    };
    $glow((int) round($W * 0.92), (int) round($H * 0.08), (int) round($W * 0.55), $p['accent'], 0.22);
    $glow((int) round($W * 0.02), (int) round($H * 0.98), (int) round($W * 0.50), $p['glow'], 0.20);
    // A fine dot texture, the finish most current brand cards carry.
    $step = (int) round($W / 30);
    $dot  = $col($p['text'], 0.05);
    for ($y = (int) ($step / 2); $y < $H; $y += $step) {
        for ($x = (int) ($step / 2); $x < $W; $x += $step) {
            imagefilledellipse($im, $x, $y, 5 * $k, 5 * $k, $dot);
        }
    }

    $rounded = static function (int $x, int $y, int $bw, int $bh, int $r, $c) use ($im) {
        $r = max(0, min($r, (int) floor(min($bw, $bh) / 2)));
        imagefilledrectangle($im, $x + $r, $y, $x + $bw - $r, $y + $bh, $c);
        imagefilledrectangle($im, $x, $y + $r, $x + $bw, $y + $bh - $r, $c);
        foreach ([[$x + $r, $y + $r], [$x + $bw - $r, $y + $r],
                  [$x + $r, $y + $bh - $r], [$x + $bw - $r, $y + $bh - $r]] as [$cx, $cy]) {
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $c);
        }
    };
    /* A see-through rounded shape. Drawn solid on a layer of its own and
       blended in once: the pieces a rounded shape is built from overlap, and
       translucent overlaps show as darker patches. */
    $tint = static function (int $x, int $y, int $bw, int $bh, int $r, string $hex, int $pct)
                    use ($im, $rgb) {
        $layer = imagecreatetruecolor($bw, $bh);
        $key   = imagecolorallocate($layer, 255, 0, 255);
        imagefill($layer, 0, 0, $key);
        imagecolortransparent($layer, $key);
        [$r1, $g1, $b1] = $rgb($hex);
        $c = imagecolorallocate($layer, $r1, $g1, $b1);
        $r = max(0, min($r, (int) floor(min($bw, $bh) / 2)));
        imagefilledrectangle($layer, $r, 0, $bw - 1 - $r, $bh - 1, $c);
        imagefilledrectangle($layer, 0, $r, $bw - 1, $bh - 1 - $r, $c);
        foreach ([[$r, $r], [$bw - 1 - $r, $r], [$r, $bh - 1 - $r], [$bw - 1 - $r, $bh - 1 - $r]] as [$cx, $cy]) {
            imagefilledellipse($layer, $cx, $cy, $r * 2, $r * 2, $c);
        }
        imagecopymerge($im, $layer, $x, $y, 0, 0, $bw, $bh, $pct);
    };
    /* Text placed by its visual box rather than its baseline, so it centres
       properly inside the chip, the pill and the button. */
    $textMid = static function (string $s, string $font, int $size, int $cx, int $cy, $c,
                                bool $centre = true) use ($im) {
        $b  = imagettfbbox($size, 0, $font, $s);
        $tw = abs($b[2] - $b[0]);
        $x  = $centre ? (int) round($cx - $tw / 2 - $b[0]) : $cx;
        $y  = (int) round($cy - ($b[1] + $b[7]) / 2);
        imagettftext($im, $size, 0, $x, $y, $c, $font, $s);
        return $tw;
    };
    /* A picture cropped to fill a box, with its corners rounded off. The
       corners are cut by making them transparent on a copy, so whatever is
       behind -- the gradient, a glow -- shows through as it should. */
    $picture = static function ($src, int $x, int $y, int $bw, int $bh, int $r) use ($im) {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($bw / $sw, $bh / $sh);
        $cw = (int) round($bw / $scale);
        $ch = (int) round($bh / $scale);
        $box = imagecreatetruecolor($bw, $bh);
        imagealphablending($box, false);
        imagesavealpha($box, true);
        imagefill($box, 0, 0, imagecolorallocatealpha($box, 255, 255, 255, 0));
        imagealphablending($box, true);
        imagecopyresampled($box, $src, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2),
                           $bw, $bh, $cw, $ch);
        imagealphablending($box, false);
        $clear = imagecolorallocatealpha($box, 0, 0, 0, 127);
        for ($yy = 0; $yy < $r; $yy++) {
            $dx = $r - (int) floor(sqrt(max(0, $r * $r - ($r - $yy - 0.5) ** 2)));
            if ($dx <= 0) {
                continue;
            }
            imagefilledrectangle($box, 0, $yy, $dx - 1, $yy, $clear);
            imagefilledrectangle($box, $bw - $dx, $yy, $bw - 1, $yy, $clear);
            imagefilledrectangle($box, 0, $bh - 1 - $yy, $dx - 1, $bh - 1 - $yy, $clear);
            imagefilledrectangle($box, $bw - $dx, $bh - 1 - $yy, $bw - 1, $bh - 1 - $yy, $clear);
        }
        imagealphablending($im, true);
        imagecopy($im, $box, $x, $y, 0, 0, $bw, $bh);
    };

    return compact('im', 'k', 'W', 'H', 'rgb', 'col', 'glow', 'rounded', 'tint', 'textMid',
                   'picture');
}

/**
 * The card, rasterised, because the platforms will not take SVG.
 *
 * Laid out like a designed post: the company's own logo in the header, an
 * illustration made for the post (generated when the key allows, otherwise
 * drawn to suit its subject -- see fabo_creative_scene()), the headline, the
 * supporting line, up to three key points, and a call-to-action button with
 * the address.
 *
 * Drawn at twice the size and scaled down: GD has no anti-aliasing for
 * filled shapes, and the halving is what gives the edges their smoothness.
 */
function fabo_creative_png(array $row, string $path): bool
{
    if (!extension_loaded('gd') || !function_exists('imagettftext')) {
        return false;
    }
    $bold = fabo_creative_font('bold');
    if ($bold === '') {
        return false;               // no font on this server: the SVG stands alone
    }
    $semi = fabo_creative_font('semibold') ?: $bold;
    $med  = fabo_creative_font('medium') ?: $semi;
    $reg  = fabo_creative_font('regular') ?: $med;

    $platform = (string) ($row['platform'] ?? 'LinkedIn');
    [$w, $h]  = fabo_creative_size($platform);
    $p        = fabo_creative_palette((string) ($row['brand_name'] ?? ''),
                                      (string) ($row['design_style'] ?? ''));
    $parts    = fabo_creative_parts($row);
    $points   = fabo_creative_points($row, $parts);
    $logo     = null;
    $art      = null;
    try {
        $logo = fabo_creative_logo($row);
        /* Never a photograph lifted from the website: a picture made for the
           post when the key can make images, otherwise an illustration. */
        $art  = fabo_creative_ai_picture($row, $p);
    } catch (Throwable $e) {
        error_log('FABO creative pictures: ' . $e->getMessage());
    }

    /* The canvas -- background, glows, texture -- and the drawing helpers,
       shared with the carousel slides. See fabo_creative_canvas(). */
    extract(fabo_creative_canvas($w, $h, $p));

    $pad   = (int) round($W * 0.075);
    $inner = $W - 2 * $pad;

    /* ---------------------------------------------------------- header */
    $hy = (int) round($H * 0.055);
    $hh = (int) round($H * 0.078);
    $nameX = $pad;
    if ($logo) {
        /* The logo on a white chip, so it reads on any palette -- most logos
           are made for a white page. A wide one is a wordmark and already
           says the name, so the name is not set beside it. */
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $chipPad = (int) round($hh * 0.18);
        $ih = $hh - 2 * $chipPad;
        $iw = (int) round($ih * $lw / max(1, $lh));
        $iw = min($iw, (int) round($W * 0.40));
        $ih = (int) round($iw * $lh / max(1, $lw));
        $cw = $iw + 2 * $chipPad;
        $rounded($pad, $hy, $cw, $hh, (int) round($hh * 0.26), $col('#FFFFFF'));
        imagecopyresampled($im, $logo, $pad + $chipPad, $hy + (int) (($hh - $ih) / 2), 0, 0,
                           $iw, $ih, $lw, $lh);
        $nameX = $lw / max(1, $lh) > 2.2 ? -1 : $pad + $cw + (int) round($W * 0.02);
    } elseif ($parts['brand'] !== '') {
        $rounded($pad, $hy, $hh, $hh, (int) round($hh * 0.26), $col($p['accent']));
        $textMid(mb_strtoupper(mb_substr($parts['brand'], 0, 1)), $bold, (int) round($hh * 0.40),
                 $pad + (int) ($hh / 2), $hy + (int) ($hh / 2), $col($p['on']));
        $nameX = $pad + $hh + (int) round($W * 0.02);
    }
    $tagW = 0;
    if ($parts['tag'] !== '') {
        $fs   = (int) round($W * 0.0130);
        $tagW = fabo_text_width($parts['tag'], $semi, $fs) + (int) round($fs * 2.6);
        $th   = (int) round($fs * 2.7);
        $tx   = $W - $pad - $tagW;
        $ty   = $hy + (int) round(($hh - $th) / 2);
        $tint($tx, $ty, $tagW, $th, (int) ($th / 2), $p['accent'], 22);
        $textMid($parts['tag'], $semi, $fs, $tx + (int) ($tagW / 2), $ty + (int) ($th / 2),
                 $col($p['accent']));
    }
    if ($nameX > 0 && $parts['brand'] !== '') {
        $ns   = (int) round($W * 0.0175);
        $room = $W - $pad - $tagW - (int) round($W * 0.03) - $nameX;
        $textMid(fabo_fit_line($parts['brand'], $semi, $ns, max(60, $room)), $semi, $ns,
                 $nameX, $hy + (int) ($hh / 2), $col($p['text']), false);
    }

    /* ---------------------------------------------------- picture panel */
    $vy = $hy + $hh + (int) round($H * 0.035);
    $vh = (int) round($H * 0.32);
    $vr = (int) round($W * 0.026);
    if ($art) {
        $picture($art, $pad, $vy, $inner, $vh, $vr);
    } else {
        fabo_creative_scene($im, $pad, $vy, $inner, $vh, $p, $k, fabo_creative_theme($row),
                            compact('col', 'tint', 'rounded', 'glow'));
    }

    /* -------------------------------------------------------------- text */
    $footT = (int) round($H * 0.855);
    $top   = $vy + $vh + (int) round($H * 0.045);
    $room  = $footT - (int) round($H * 0.03) - $top;

    /* Everything is measured, then the most that fits is used: all the
       points at a good headline size first, then fewer points, then no
       supporting line, then a smaller headline. Never cut off, never over. */
    $px  = static fn(int $pt) => (int) round($pt * 4 / 3);
    $sub = $parts['support'] !== '' ? $parts['support'] : '';
    $ss  = (int) round($W * 0.0175);
    $ps  = (int) round($W * 0.0165);
    $plan = null;
    $options = [[count($points), true], [min(2, count($points)), true], [1, true],
                [min(2, count($points)), false], [0, true], [0, false]];
    foreach ($options as [$np, $withSub]) {
        $np = min($np, count($points));
        foreach ([0.050, 0.045, 0.041, 0.037] as $f) {
            $hs = (int) round($W * $f);
            $hl = fabo_wrap_fit($parts['headline'], $bold, $hs, $inner, 3);
            if ($hl === null) {
                continue;
            }
            $sl = ($withSub && $sub !== '') ? (fabo_wrap_fit($sub, $reg, $ss, $inner, 2) ?? []) : [];
            $need = count($hl) * (int) round($px($hs) * 1.12)
                  + ($sl ? (int) round($H * 0.012) + count($sl) * (int) round($px($ss) * 1.45) : 0)
                  + ($np ? (int) round($H * 0.02) + $np * (int) round($px($ps) * 1.75) : 0);
            if ($need <= $room) {
                $plan = [$hs, $hl, $sl, $np, $need];
                break 2;
            }
        }
    }
    if ($plan === null) {
        $hs = (int) round($W * 0.034);
        $plan = [$hs, fabo_fit_text($parts['headline'], $bold, [$hs], $inner, 3)[1], [], 0,
                 $room];
    }
    [$hs, $hl, $sl, $np, $need] = $plan;

    // Centred in the room between the picture and the footer.
    $y = $top + max(0, (int) (($room - $need) / 2));
    $n = count($hl);
    foreach ($hl as $i => $line) {
        $base = $y + (int) round($px($hs) * 0.92) + $i * (int) round($px($hs) * 1.12);
        imagettftext($im, $hs, 0, $pad, $base,
                     $n > 1 && $i === $n - 1 ? $col($p['accent']) : $col($p['text']), $bold, $line);
    }
    $y += $n * (int) round($px($hs) * 1.12);
    if ($sl) {
        $y += (int) round($H * 0.012);
        foreach ($sl as $line) {
            $y += (int) round($px($ss) * 1.45);
            imagettftext($im, $ss, 0, $pad, $y - (int) round($px($ss) * 0.3), $col($p['muted']), $reg, $line);
        }
    }
    if ($np) {
        /* Points that fit whole on their line first; a shortened one only
           when there are not enough of the others. */
        $maxPt  = $W - $pad - ($pad + (int) round($px($ps) * 1.05) + (int) round($W * 0.014));
        $whole  = array_values(array_filter($points,
                      static fn($t) => fabo_text_width($t, $med, $ps) <= $maxPt));
        $cut    = array_values(array_diff($points, $whole));
        $points = array_merge($whole, $cut);
        $y += (int) round($H * 0.02);
        $lead = (int) round($px($ps) * 1.75);
        $dot  = (int) round($px($ps) * 1.05);
        foreach (array_slice($points, 0, $np) as $pt) {
            $cy = $y + (int) ($lead / 2);
            // A tick in an accent disc.
            imagefilledellipse($im, $pad + (int) ($dot / 2), $cy, $dot, $dot, $col($p['accent']));
            imagesetthickness($im, max(2, (int) round($dot * 0.12)));
            $on = $col($p['on']);
            $x0 = $pad + (int) round($dot * 0.28);
            imageline($im, $x0, $cy, $x0 + (int) round($dot * 0.16), $cy + (int) round($dot * 0.17), $on);
            imageline($im, $x0 + (int) round($dot * 0.16), $cy + (int) round($dot * 0.17),
                      $x0 + (int) round($dot * 0.46), $cy - (int) round($dot * 0.17), $on);
            imagesetthickness($im, 1);
            $tx = $pad + $dot + (int) round($W * 0.014);
            $textMid(fabo_fit_line($pt, $med, $ps, $W - $pad - $tx), $med, $ps, $tx, $cy,
                     $col($p['text'], 0.92), false);
            $y += $lead;
        }
    }

    /* ------------------------------------------------------------ footer */
    imagefilledrectangle($im, $pad, $footT, $W - $pad, $footT + 2 * $k, $col($p['text'], 0.14));
    $bfs   = (int) round($W * 0.0165);
    $label = $parts['cta'];
    $lw    = fabo_text_width($label, $semi, $bfs);
    $arrow = (int) round($bfs * 1.3);
    $bh    = (int) round($bfs * 3.2);
    $bw    = $lw + $arrow + (int) round($bfs * 3.6);
    $by    = $footT + (int) round(($H - $footT - $bh) / 2);
    $rounded($pad, $by, $bw, $bh, (int) ($bh / 2), $col($p['accent']));
    $bx = $pad + (int) round($bfs * 1.5);
    $textMid($label, $semi, $bfs, $bx, $by + (int) ($bh / 2), $col($p['on']), false);
    // The arrow, drawn: no font can be relied on to have a good one.
    $ax = $bx + $lw + (int) round($bfs * 0.7);
    $ay = $by + (int) ($bh / 2);
    imagesetthickness($im, max(2, (int) round($bfs * 0.16)));
    $on = $col($p['on']);
    imageline($im, $ax, $ay, $ax + $arrow, $ay, $on);
    imageline($im, $ax + $arrow, $ay, $ax + $arrow - (int) round($arrow * 0.42),
              $ay - (int) round($arrow * 0.42), $on);
    imageline($im, $ax + $arrow, $ay, $ax + $arrow - (int) round($arrow * 0.42),
              $ay + (int) round($arrow * 0.42), $on);
    imagesetthickness($im, 1);
    if ($parts['host'] !== '') {
        $hs2 = (int) round($W * 0.0150);
        $hw  = fabo_text_width($parts['host'], $med, $hs2);
        // A small globe before the address.
        $gx = $W - $pad - $hw - (int) round($hs2 * 1.9);
        $gy = $by + (int) ($bh / 2);
        $gr = (int) round($hs2 * 1.05);
        imagesetthickness($im, max(2, (int) round($k * 1.5)));
        imageellipse($im, $gx, $gy, $gr, $gr, $col($p['muted']));
        imageellipse($im, $gx, $gy, (int) ($gr * 0.45), $gr, $col($p['muted']));
        imageline($im, $gx - (int) ($gr / 2), $gy, $gx + (int) ($gr / 2), $gy, $col($p['muted']));
        imagesetthickness($im, 1);
        $textMid($parts['host'], $med, $hs2, $W - $pad - $hw, $gy, $col($p['muted']), false);
    }

    // Down to the real size, which is where the edges become smooth.
    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
    unset($im);
    return (bool) imagepng($out, $path, 6);
}

/* ------------------------------------------------------------ carousels */

/**
 * The slides a carousel row carries, as [title, body] pairs.
 *
 * Stored one per line as "Title — body" so the grid shows them readably and
 * a person can edit them in a cell.
 */
function fabo_creative_slides(array $row): array
{
    $out = [];
    foreach (preg_split('~\R~u', (string) ($row['carousel_slides'] ?? '')) ?: [] as $line) {
        $line = trim(preg_replace('~^\s*\d+[.)]\s*~u', '', $line) ?? $line);
        if ($line === '') {
            continue;
        }
        $parts  = preg_split('~\s+[—–]\s+|\s+-\s+|\s*::\s*~u', $line, 2) ?: [$line];
        $out[]  = [trim($parts[0]), trim($parts[1] ?? '')];
    }
    return array_slice($out, 0, 10);
}

/**
 * One slide of a carousel, as a GD image at its real size.
 *
 * Portrait 4:5, LinkedIn's preferred document shape. Three layouts: the
 * cover (logo, illustration, the promise), the points (a big number, the
 * point, what it means), and the close (the call to action and the address).
 * Every slide carries the brand and its page number, so a slide shared on
 * its own still says whose it is.
 */
function fabo_creative_slide(array $row, array $p, int $i, int $n, string $title, string $body,
                             string $theme = '')
{
    $bold = fabo_creative_font('bold');
    $semi = fabo_creative_font('semibold') ?: $bold;
    $med  = fabo_creative_font('medium') ?: $semi;
    $reg  = fabo_creative_font('regular') ?: $med;
    [$w, $h] = [1080, 1350];
    extract(fabo_creative_canvas($w, $h, $p));
    $parts = fabo_creative_parts($row);
    $pad   = (int) round($W * 0.08);
    $inner = $W - 2 * $pad;
    $px    = static fn(int $pt) => (int) round($pt * 4 / 3);

    // Header: logo (or initial) and brand; page number on the right.
    $hy = (int) round($H * 0.045);
    $hh = (int) round($H * 0.055);
    $logo = null;
    try {
        $logo = fabo_creative_logo($row);
    } catch (Throwable $e) {
        // The initial stands in.
    }
    $nameX = $pad + $hh + (int) round($W * 0.02);
    if ($logo) {
        $lw = imagesx($logo);
        $lh = imagesy($logo);
        $cp = (int) round($hh * 0.18);
        $ih = $hh - 2 * $cp;
        $iw = min((int) round($ih * $lw / max(1, $lh)), (int) round($W * 0.40));
        $ih = (int) round($iw * $lh / max(1, $lw));
        $rounded($pad, $hy, $iw + 2 * $cp, $hh, (int) round($hh * 0.26), $col('#FFFFFF'));
        imagecopyresampled($im, $logo, $pad + $cp, $hy + (int) (($hh - $ih) / 2), 0, 0, $iw, $ih, $lw, $lh);
        $nameX = $lw / max(1, $lh) > 2.2 ? -1 : $pad + $iw + 2 * $cp + (int) round($W * 0.02);
    } else {
        $rounded($pad, $hy, $hh, $hh, (int) round($hh * 0.26), $col($p['accent']));
        $textMid(mb_strtoupper(mb_substr($parts['brand'] ?: 'B', 0, 1)), $bold, (int) round($hh * 0.40),
                 $pad + (int) ($hh / 2), $hy + (int) ($hh / 2), $col($p['on']));
    }
    if ($nameX > 0 && $parts['brand'] !== '') {
        $textMid(fabo_fit_line($parts['brand'], $semi, (int) round($W * 0.017), (int) round($W * 0.45)),
                 $semi, (int) round($W * 0.017), $nameX, $hy + (int) ($hh / 2), $col($p['text']), false);
    }
    $pg = ($i + 1) . ' / ' . $n;
    $ps = (int) round($W * 0.015);
    $pw = fabo_text_width($pg, $semi, $ps) + (int) round($ps * 2.4);
    $tint($W - $pad - $pw, $hy + (int) (($hh - $ps * 2.6) / 2), $pw, (int) round($ps * 2.6),
          (int) round($ps * 1.3), $p['accent'], 22);
    $textMid($pg, $semi, $ps, $W - $pad - (int) ($pw / 2), $hy + (int) ($hh / 2), $col($p['accent']));

    $first = $i === 0;
    $last  = $i === $n - 1 && $n > 2;
    $top   = $hy + $hh + (int) round($H * 0.05);
    $foot  = (int) round($H * 0.89);

    if ($first) {
        // Cover: the illustration, then the promise.
        $vh = (int) round($H * 0.33);
        fabo_creative_scene($im, $pad, $top, $inner, $vh, $p, $k, $theme ?: fabo_creative_theme($row),
                            compact('col', 'tint', 'rounded', 'glow'));
        $y = $top + $vh + (int) round($H * 0.05);
        $room = $foot - $y - (int) round($H * 0.03);
        foreach ([0.068, 0.060, 0.052, 0.045] as $f) {
            $ts = (int) round($W * $f);
            $tl = fabo_wrap_fit($title, $bold, $ts, $inner, 3);
            if ($tl !== null && count($tl) * (int) round($px($ts) * 1.1) <= (int) ($room * 0.62)) {
                break;
            }
        }
        $tl = $tl ?? fabo_fit_text($title, $bold, [$ts], $inner, 3)[1];
        foreach ($tl as $j => $line) {
            imagettftext($im, $ts, 0, $pad, $y + (int) round($px($ts) * 0.92) + $j * (int) round($px($ts) * 1.1),
                         $j === count($tl) - 1 && count($tl) > 1 ? $col($p['accent']) : $col($p['text']),
                         $bold, $line);
        }
        $y += count($tl) * (int) round($px($ts) * 1.1) + (int) round($H * 0.02);
        if ($body !== '') {
            $bs = (int) round($W * 0.024);
            foreach (fabo_fit_text($body, $reg, [$bs, (int) round($W * 0.021)], $inner, 3)[1] as $line) {
                $y += (int) round($px($bs) * 1.45);
                imagettftext($im, $bs, 0, $pad, $y, $col($p['muted']), $reg, $line);
            }
        }
        $cta = 'Swipe';
    } else {
        // A point, or the close: a big number or a mark, the title, the body.
        $y = $top + (int) round($H * 0.04);
        if ($last) {
            $d = (int) round($W * 0.13);
            imagefilledellipse($im, $pad + (int) ($d / 2), $y + (int) ($d / 2), $d, $d, $col($p['accent']));
            imagesetthickness($im, (int) round($d * 0.09));
            imageline($im, $pad + (int) ($d * 0.28), $y + (int) ($d * 0.52), $pad + (int) ($d * 0.44),
                      $y + (int) ($d * 0.68), $col($p['on']));
            imageline($im, $pad + (int) ($d * 0.44), $y + (int) ($d * 0.68), $pad + (int) ($d * 0.74),
                      $y + (int) ($d * 0.34), $col($p['on']));
            imagesetthickness($im, 1);
            $y += $d + (int) round($H * 0.045);
        } else {
            $num = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $ns  = (int) round($W * 0.10);
            imagettftext($im, $ns, 0, $pad, $y + (int) round($px($ns) * 0.78), $col($p['accent']), $bold, $num);
            $rounded($pad, $y + (int) round($px($ns) * 0.95), (int) round($W * 0.10), (int) round($k * 5),
                     (int) round($k * 2.5), $col($p['accent']));
            $y += (int) round($px($ns) * 0.95) + (int) round($H * 0.045);
        }
        $room = $foot - $y - (int) round($H * 0.04);
        foreach ([0.062, 0.055, 0.048, 0.042] as $f) {
            $ts = (int) round($W * $f);
            $tl = fabo_wrap_fit($title, $bold, $ts, $inner, 3);
            if ($tl !== null) {
                break;
            }
        }
        $tl = $tl ?? fabo_fit_text($title, $bold, [$ts], $inner, 3)[1];
        foreach ($tl as $j => $line) {
            imagettftext($im, $ts, 0, $pad, $y + (int) round($px($ts) * 0.92) + $j * (int) round($px($ts) * 1.1),
                         $col($p['text']), $bold, $line);
        }
        $y += count($tl) * (int) round($px($ts) * 1.1) + (int) round($H * 0.035);
        if ($body !== '') {
            foreach ([0.030, 0.027, 0.024, 0.021] as $f) {
                $bs = (int) round($W * $f);
                $bl = fabo_wrap_fit($body, $reg, $bs, $inner, 7);
                if ($bl !== null && count($bl) * (int) round($px($bs) * 1.5) <= $foot - $y - (int) round($H * 0.05)) {
                    break;
                }
            }
            $bl = $bl ?? fabo_fit_text($body, $reg, [$bs], $inner, 7)[1];
            foreach ($bl as $line) {
                $y += (int) round($px($bs) * 1.5);
                imagettftext($im, $bs, 0, $pad, $y, $col($p['text'], 0.86), $reg, $line);
            }
        }
        /* The space left under a point gets an illustration of that point --
           chosen from the slide's own words, so each slide has its own. */
        $space = $foot - (int) round($H * 0.04) - ($y + (int) round($H * 0.05));
        if (!$last && $space >= (int) round($H * 0.15)) {
            $sh = min($space, (int) round($H * 0.34));
            fabo_creative_scene($im, $pad, $foot - (int) round($H * 0.04) - $sh, $inner, $sh, $p, $k,
                                $theme ?: fabo_creative_theme(['id' => (int) ($row['id'] ?? 0) + $i,
                                                               'content_topic' => $title . ' ' . $body]),
                                compact('col', 'tint', 'rounded', 'glow'));
        }
        $cta = $last ? $parts['cta'] : 'Next';
    }

    // Footer: progress, and a button that says what to do.
    imagefilledrectangle($im, $pad, $foot, $W - $pad, $foot + 2 * $k, $col($p['text'], 0.14));
    $fy  = $foot + (int) round(($H - $foot) / 2);
    $seg = (int) round($inner * 0.42 / max(1, $n));
    for ($j = 0; $j < $n; $j++) {
        $rounded($pad + $j * ($seg + $k * 6), $fy - $k * 4, $seg, $k * 8, $k * 4,
                 $j <= $i ? $col($p['accent']) : $col($p['text'], 0.20));
    }
    $bfs = (int) round($W * 0.017);
    $lw  = fabo_text_width($cta, $semi, $bfs);
    $bh  = (int) round($bfs * 3.2);
    $bw  = $lw + (int) round($bfs * 5.2);
    $bx  = $W - $pad - $bw;
    $rounded($bx, $fy - (int) ($bh / 2), $bw, $bh, (int) ($bh / 2), $col($p['accent']));
    $textMid($cta, $semi, $bfs, $bx + (int) round($bfs * 1.5), $fy, $col($p['on']), false);
    $ax = $bx + (int) round($bfs * 1.5) + $lw + (int) round($bfs * 0.7);
    $ar = (int) round($bfs * 1.3);
    imagesetthickness($im, max(2, (int) round($bfs * 0.16)));
    imageline($im, $ax, $fy, $ax + $ar, $fy, $col($p['on']));
    imageline($im, $ax + $ar, $fy, $ax + $ar - (int) ($ar * 0.42), $fy - (int) ($ar * 0.42), $col($p['on']));
    imageline($im, $ax + $ar, $fy, $ax + $ar - (int) ($ar * 0.42), $fy + (int) ($ar * 0.42), $col($p['on']));
    imagesetthickness($im, 1);
    if ($last && $parts['host'] !== '') {
        $hs = (int) round($W * 0.020);
        $textMid($parts['host'], $med, $hs, $pad, $foot - (int) round($H * 0.05), $col($p['muted']), false);
    }

    $out = imagecreatetruecolor($w, $h);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
    unset($im);
    return $out;
}

/**
 * The carousel for a row: every slide as a JPEG, and all of them as one PDF.
 *
 * Returns what to store in document_asset -- the PDF's file name -- or ''.
 * The slide images sit beside it as <stem>-1.jpg, <stem>-2.jpg... so the
 * preview can show them without a PDF viewer.
 */
function fabo_creative_carousel(array $row): string
{
    $slides = fabo_creative_slides($row);
    if (count($slides) < 2 || !function_exists('imagejpeg') || fabo_creative_font('bold') === '') {
        return '';
    }
    $p    = fabo_creative_palette((string) ($row['brand_name'] ?? ''), (string) ($row['design_style'] ?? ''));
    $dir  = fabo_media_dir();
    $stem = bin2hex(random_bytes(16));
    $jpgs = [];
    /* Each slide its own scene: the cover's from the post, each point's from
       its own words, never the same one twice in a row of four. */
    $used = [];
    foreach ($slides as $i => [$title, $body]) {
        $theme = $i === 0 ? fabo_creative_theme($row)
               : fabo_creative_theme(['id' => (int) ($row['id'] ?? 0) + $i,
                                      'content_topic' => $title . ' ' . $body], array_slice($used, -4));
        $used[] = $theme;
        $im   = fabo_creative_slide($row, $p, $i, count($slides), $title, $body, $theme);
        $file = $dir . DIRECTORY_SEPARATOR . $stem . '-' . ($i + 1) . '.jpg';
        imagejpeg($im, $file, 90);
        $jpgs[] = $file;
    }
    $pdf = $dir . DIRECTORY_SEPARATOR . $stem . '.pdf';
    return fabo_pdf_from_jpegs($jpgs, $pdf, (string) ($row['linkedin_headline'] ?? '')) ? $stem . '.pdf' : '';
}

/**
 * A PDF whose pages are the given JPEGs, one image filling each page.
 *
 * Written by hand because it is this simple: a JPEG goes into a PDF as it
 * is (DCTDecode), so there is nothing to encode and no library to install.
 */
function fabo_pdf_from_jpegs(array $files, string $out, string $title = ''): bool
{
    $objs  = [];                                    // object number => body
    $kids  = [];
    $next  = 3;                                     // 1 catalog, 2 pages
    foreach ($files as $f) {
        $info = @getimagesize($f);
        $data = @file_get_contents($f);
        if (!$info || $data === false) {
            return false;
        }
        [$pw, $ph] = [$info[0] / 2, $info[1] / 2];  // 2 px to the point: 1080 px is 540 pt
        $img  = $next++;
        $cont = $next++;
        $page = $next++;
        $objs[$img] = '<< /Type /XObject /Subtype /Image /Width ' . $info[0] . ' /Height ' . $info[1]
                    . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '
                    . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
        $draw = sprintf("q %.2F 0 0 %.2F 0 0 cm /Im0 Do Q", $pw, $ph);
        $objs[$cont] = '<< /Length ' . strlen($draw) . " >>\nstream\n" . $draw . "\nendstream";
        $objs[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . sprintf('%.2F %.2F', $pw, $ph)
                     . '] /Resources << /XObject << /Im0 ' . $img . ' 0 R >> >> /Contents ' . $cont . ' 0 R >>';
        $kids[] = $page . ' 0 R';
    }
    $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    $info    = $next;
    $safe    = preg_replace('~[()\\\\\r\n]~', ' ', mb_substr($title, 0, 120)) ?? '';
    $objs[$info] = '<< /Title (' . $safe . ') /Producer (Marketing Command Centre) >>';
    ksort($objs);

    $pdf  = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $xref = [];
    foreach ($objs as $n => $body) {
        $xref[$n] = strlen($pdf);
        $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
    }
    $start = strlen($pdf);
    $pdf  .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    for ($n = 1; $n <= count($objs); $n++) {
        $pdf .= sprintf("%010d 00000 n \n", $xref[$n]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . ' /Root 1 0 R /Info ' . $info
          . " 0 R >>\nstartxref\n" . $start . "\n%%EOF\n";
    return @file_put_contents($out, $pdf) !== false;
}

/**
 * Make the graphic for a row. Returns the PNG's file name, or ''.
 *
 * The name is random rather than derived from the row, because this folder
 * is reachable from the open internet — it has to be, for Instagram — and a
 * predictable name would let anyone walk the set.
 */
function fabo_creative_make(array $row): string
{
    $dir  = fabo_media_dir();
    $stem = bin2hex(random_bytes(16));

    $svg = fabo_creative_svg($row);
    @file_put_contents($dir . DIRECTORY_SEPARATOR . $stem . '.svg', $svg);

    $png = $dir . DIRECTORY_SEPARATOR . $stem . '.png';
    if (fabo_creative_png($row, $png)) {
        return $stem . '.png';
    }
    /* No usable font on this server. The SVG is still a real graphic — it
       just cannot go to Instagram, which the publisher will say plainly
       rather than failing at the last step. */
    return is_file($dir . DIRECTORY_SEPARATOR . $stem . '.svg') ? $stem . '.svg' : '';
}

/** Throw away a creative that has been replaced. */
function fabo_creative_forget(string $file): void
{
    if ($file === '' || !preg_match('~^[0-9a-f]{32}\.(png|svg)$~', $file)) {
        return;                     // never delete a path somebody else chose
    }
    $stem = substr($file, 0, 32);
    foreach (['png', 'svg'] as $ext) {
        $p = fabo_media_dir() . DIRECTORY_SEPARATOR . $stem . '.' . $ext;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}
