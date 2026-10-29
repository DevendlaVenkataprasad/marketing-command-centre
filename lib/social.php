<?php
/**
 * Putting a post on LinkedIn and Instagram.
 *
 * Both platforms need more than code. LinkedIn wants a developer app with
 * the w_member_social scope; Instagram wants a Business account attached to
 * a Facebook Page, inside a Meta app that has passed App Review for
 * instagram_content_publish. Neither approval can be written — they are
 * applied for and waited on.
 *
 * So this file is built to sit dormant. With no account connected, nothing
 * here runs and the portal says so in plain words instead of failing at the
 * last step of a pipeline somebody waited ten minutes for. When the
 * approvals land, connecting an account is all that changes.
 *
 * THE RULE THAT MATTERS: publishing is idempotent. A post must never appear
 * twice because a worker restarted, an API timed out on a request it had
 * already honoured, or two cron runs overlapped. That is not achieved by
 * being careful — it is achieved by a unique key. Every attempt claims
 * `publication_attempts.idempotency_key` BEFORE calling out, and the row
 * that fails to claim it does not publish. An API call that times out is
 * never retried blind; the attempt is left for a person, because a timeout
 * means "I do not know whether that posted" and guessing wrong is a
 * duplicate on somebody's real brand account.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jobs.php';

/** What each platform needs, and what it will refuse. */
function fabo_social_platforms(): array
{
    return [
        'linkedin' => [
            'label'    => 'LinkedIn',
            'needs'    => 'A LinkedIn developer app with the w_member_social scope, '
                        . 'and Marketing Developer Platform access for company pages.',
            'max_text' => 3000,
            'image'    => false,      // optional
        ],
        'instagram' => [
            'label'    => 'Instagram',
            'needs'    => 'An Instagram Business or Creator account linked to a Facebook Page, '
                        . 'inside a Meta app approved for instagram_content_publish.',
            'max_text' => 2200,
            'image'    => true,       // Instagram will not take a post without one
        ],
    ];
}

/** The connected account for a client on a platform, or null. */
function fabo_social_account(int $clientId, string $platform): ?array
{
    try {
        $row = fabo_one('SELECT * FROM social_accounts WHERE client_id = ? AND platform = ?',
                        'is', [$clientId, strtolower($platform)]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || trim((string) $row['access_token']) === '') {
        return null;
    }
    /* An expired token is not a connection. Saying so here means the caller
       gets one clear reason rather than a 401 from somebody else's API. */
    if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
        $row['expired'] = true;
    }
    return $row;
}

/** Is this client able to publish to this platform right now? [ok, reason] */
function fabo_social_ready(int $clientId, string $platform): array
{
    $p = fabo_social_platforms()[strtolower($platform)] ?? null;
    if (!$p) {
        return [false, 'That is not a platform this portal posts to.'];
    }
    $acct = fabo_social_account($clientId, $platform);
    if (!$acct) {
        return [false, 'No ' . $p['label'] . ' account is connected for this client. '
                     . $p['needs']];
    }
    if (!empty($acct['expired'])) {
        return [false, 'The ' . $p['label'] . ' connection has expired and needs reconnecting.'];
    }
    return [true, ''];
}

/**
 * The key that makes a post happen once.
 *
 * Built from the things that identify THIS post going to THIS place — not
 * from the time, and not from a random value, because either of those would
 * make a second attempt look like a different post, which is the whole
 * failure being prevented.
 */
function fabo_social_key(int $rowId, string $platform): string
{
    return 'wc:' . $rowId . ':' . strtolower($platform);
}

/**
 * Claim the right to publish. Returns the attempt id, or 0 if somebody
 * already holds it.
 *
 * The INSERT is the lock. Two workers reaching this line at the same instant
 * produce one insert and one duplicate-key error, and the one that failed
 * does not publish. This is deliberately not a SELECT-then-INSERT: between
 * those two statements is exactly where a double post comes from.
 */
function fabo_social_claim(int $rowId, int $clientId, string $platform): int
{
    $key = fabo_social_key($rowId, $platform);
    try {
        return (int) fabo_exec(
            'INSERT INTO publication_attempts
             (client_id, row_id, platform, idempotency_key, publication_status, attempt_count)
             VALUES (?,?,?,?,"claimed",1)', 'iiss',
            [$clientId, $rowId, strtolower($platform), $key]);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), 'Duplicate entry')) {
            throw $e;
        }
    }
    /* Somebody has the key. Whether this worker may retry depends on what
       happened last time — see below. */
    $have = fabo_one('SELECT * FROM publication_attempts WHERE idempotency_key = ?', 's', [$key]);
    if (!$have) {
        return 0;
    }
    $status = (string) $have['publication_status'];
    if (in_array($status, ['published', 'claimed', 'unknown'], true)) {
        /* published — done. claimed — in flight. unknown — an earlier call
           timed out and nobody can say whether it posted; retrying that is
           how a brand ends up with the same post twice, so it waits for a
           person. */
        return 0;
    }
    if ((int) $have['attempt_count'] >= 5) {
        return 0;
    }
    $took = fabo_exec('UPDATE publication_attempts
                          SET publication_status = "claimed", attempt_count = attempt_count + 1
                        WHERE id = ? AND publication_status = ?',
                      'is', [(int) $have['id'], $status]);
    return $took > 0 ? (int) $have['id'] : 0;
}

/** Record how it went. */
function fabo_social_settle(int $attemptId, string $status, string $postId = '',
                            string $url = '', string $error = ''): void
{
    fabo_exec('UPDATE publication_attempts
                  SET publication_status = ?, post_id = ?, platform_post_url = ?,
                      last_error = ?, published_at = CASE WHEN ? = "published"
                                                          THEN NOW() ELSE published_at END
                WHERE id = ?', 'sssssi',
              [$status, mb_substr($postId, 0, 190), mb_substr($url, 0, 700),
               mb_substr($error, 0, 500), $status, $attemptId]);
}

/* ------------------------------------------------- LinkedIn, by post kind */

/** The headers every LinkedIn REST call carries. */
function fabo_linkedin_head(array $acct): array
{
    return [
        'Authorization: Bearer ' . trim((string) $acct['access_token']),
        'Content-Type: application/json',
        'X-Restli-Protocol-Version: 2.0.0',
        'LinkedIn-Version: ' . date('Ym', strtotime('-2 months')),
    ];
}

/** The LinkedIn post kind of a row, with the old rows (no kind) read sensibly. */
function fabo_linkedin_kind(array $row): string
{
    $k = trim((string) ($row['linkedin_type'] ?? ''));
    if ($k !== '') {
        return $k;
    }
    $ct = strtolower((string) ($row['content_type'] ?? ''));
    return str_contains($ct, 'text') ? 'Text' : (str_contains($ct, 'carousel') ? 'Carousel' : 'Image');
}

/**
 * The files a row has to post with, by kind, as local paths.
 *
 * What a person uploaded always comes first -- "use my image" means exactly
 * that -- and the portal's own graphic and carousel stand in where nothing
 * was uploaded. Returns ['image' => [...], 'pdf' => [...], 'video' => [...]].
 */
function fabo_linkedin_files(array $row): array
{
    require_once __DIR__ . '/creative.php';
    $dir = fabo_media_dir() . DIRECTORY_SEPARATOR;
    $out = ['image' => [], 'pdf' => [], 'video' => []];
    foreach (json_decode((string) ($row['post_media'] ?? ''), true) ?: [] as $m) {
        $f = (string) ($m['file'] ?? '');
        $k = (string) ($m['kind'] ?? '');
        if (isset($out[$k]) && preg_match('~^[0-9a-f]{32}\.[a-z0-9]{2,5}$~', $f) && is_file($dir . $f)) {
            $out[$k][] = $dir . $f;
        }
    }
    $own = ['image' => (bool) $out['image'], 'pdf' => (bool) $out['pdf']];
    $card = (string) ($row['media_asset'] ?? '');
    if (!$own['image'] && preg_match('~^[0-9a-f]{32}\.png$~', $card) && is_file($dir . $card)) {
        $out['image'][] = $dir . $card;
    }
    $doc = (string) ($row['document_asset'] ?? '');
    if (!$own['pdf'] && preg_match('~^([0-9a-f]{32})\.pdf$~', $doc, $m) && is_file($dir . $doc)) {
        $out['pdf'][] = $dir . $doc;
        $out['slides'] = glob($dir . $m[1] . '-*.jpg') ?: [];
        natsort($out['slides']);
        $out['slides'] = array_values($out['slides']);
    }
    return $out;
}

/**
 * Can this row go out as the kind it is? Returns [ok, why].
 *
 * Asked before anything is claimed or uploaded, so a post that cannot go --
 * a video post with no video -- says so and is not marked failed.
 */
function fabo_linkedin_ready_row(array $row): array
{
    $kind  = fabo_linkedin_kind($row);
    $files = fabo_linkedin_files($row);
    switch ($kind) {
        case 'Video':
            return $files['video'] ? [true, ''] : [false, 'This is a video post and no video has been uploaded.'];
        case 'Document':
        case 'Carousel':
            return $files['pdf'] ? [true, '']
                 : [false, 'This is a ' . strtolower($kind) . ' post and there is no PDF for it.'];
        case 'Poll':
            $opts = array_filter(array_map('trim', explode('|', (string) ($row['poll_options'] ?? ''))));
            return trim((string) ($row['poll_question'] ?? '')) !== '' && count($opts) >= 2
                 ? [true, ''] : [false, 'A poll needs a question and at least two options.'];
        case 'Article':
        case 'Newsletter':
        case 'Event':
        case 'Job':
            return trim((string) (($row['cta_url'] ?? '') ?: ($row['source_url'] ?? ''))) !== ''
                 ? [true, ''] : [false, 'A ' . strtolower($kind) . ' post needs a link to point to.'];
    }
    return [true, ''];
}

/** PUT bytes to an upload address LinkedIn gave. Returns [ok, etag]. */
function fabo_linkedin_put(string $url, string $token, string $bytes): array
{
    $ch = curl_init($url);
    $etag = '';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token,
                                   'Content-Type: application/octet-stream'],
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$etag): int {
            if (stripos($line, 'etag:') === 0) {
                $etag = trim(substr($line, 5));
            }
            return strlen($line);
        },
    ]);
    if (defined('SMTP_CAFILE') && is_file(SMTP_CAFILE)) {
        curl_setopt($ch, CURLOPT_CAINFO, SMTP_CAFILE);
    }
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return [$status >= 200 && $status < 300, $etag];
}

/** Upload an image or a document from disk. $what is 'images' or 'documents'. Returns [ok, urn|why]. */
function fabo_linkedin_upload_file(array $acct, string $path, string $what): array
{
    $author = trim((string) $acct['account_urn']);
    [$ok, $res, $status] = fabo_http('POST',
        'https://api.linkedin.com/rest/' . $what . '?action=initializeUpload',
        ['initializeUploadRequest' => ['owner' => $author]], fabo_linkedin_head($acct), 30);
    $target = (string) ($res['value']['uploadUrl'] ?? '');
    $urn    = (string) ($res['value'][$what === 'images' ? 'image' : 'document'] ?? '');
    if (!$ok || $target === '' || $urn === '') {
        return [false, 'LinkedIn would not take the ' . rtrim($what, 's') . ' (' . $status . ').'];
    }
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return [false, 'The file to upload could not be read.'];
    }
    [$put] = fabo_linkedin_put($target, trim((string) $acct['access_token']), $bytes);
    return $put ? [true, $urn] : [false, 'The upload to LinkedIn did not go through.'];
}

/**
 * Upload a video from disk: initialise, send each part, finalise, then wait
 * for LinkedIn to finish processing it -- a post that names a video still
 * being processed is refused. Returns [ok, urn|why].
 */
function fabo_linkedin_upload_video(array $acct, string $path): array
{
    $author = trim((string) $acct['account_urn']);
    $size   = (int) @filesize($path);
    $head   = fabo_linkedin_head($acct);
    [$ok, $res, $status] = fabo_http('POST', 'https://api.linkedin.com/rest/videos?action=initializeUpload',
        ['initializeUploadRequest' => ['owner' => $author, 'fileSizeBytes' => $size,
                                       'uploadCaptions' => false, 'uploadThumbnail' => false]],
        $head, 30);
    $video = (string) ($res['value']['video'] ?? '');
    $parts = (array) ($res['value']['uploadInstructions'] ?? []);
    if (!$ok || $video === '' || !$parts) {
        return [false, 'LinkedIn would not take the video (' . $status . ').'];
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return [false, 'The video could not be read.'];
    }
    $etags = [];
    foreach ($parts as $part) {
        $first = (int) ($part['firstByte'] ?? 0);
        $last  = (int) ($part['lastByte'] ?? ($size - 1));
        fseek($fh, $first);
        $chunk = (string) fread($fh, $last - $first + 1);
        [$put, $etag] = fabo_linkedin_put((string) $part['uploadUrl'],
                                          trim((string) $acct['access_token']), $chunk);
        if (!$put) {
            fclose($fh);
            return [false, 'Part of the video did not upload.'];
        }
        $etags[] = trim($etag, '"');
    }
    fclose($fh);
    [$ok, , $status] = fabo_http('POST', 'https://api.linkedin.com/rest/videos?action=finalizeUpload',
        ['finalizeUploadRequest' => ['video' => $video,
                                     'uploadToken' => (string) ($res['value']['uploadToken'] ?? ''),
                                     'uploadedPartIds' => $etags]], $head, 30);
    if (!$ok) {
        return [false, 'LinkedIn did not accept the finished video (' . $status . ').'];
    }
    for ($i = 0; $i < 20; $i++) {          // up to about a minute
        [$ok, $v] = fabo_http('GET', 'https://api.linkedin.com/rest/videos/' . rawurlencode($video),
                              null, $head, 20);
        $state = strtoupper((string) ($v['status'] ?? ''));
        if ($state === 'AVAILABLE') {
            return [true, $video];
        }
        if ($state === 'PROCESSING_FAILED') {
            return [false, 'LinkedIn could not process the video.'];
        }
        sleep(3);
    }
    return [false, 'LinkedIn is still processing the video; it will be tried again.'];
}

/**
 * Post one row to LinkedIn as the kind it is. Returns [ok, id|why, url].
 *
 * Text, Image, Multi-image, Document/Carousel, Video and Poll are LinkedIn's
 * own kinds. Article, Newsletter, Event and Job go out as a link post with a
 * preview card: LinkedIn's API does not let an app create those natively.
 */
function fabo_linkedin_post_row(array $acct, array $row, string $text): array
{
    $author = trim((string) $acct['account_urn']);
    if ($author === '') {
        return [false, 'That LinkedIn connection has no author to post as.', ''];
    }
    $kind  = fabo_linkedin_kind($row);
    $files = fabo_linkedin_files($row);
    $title = mb_substr(trim((string) (($row['linkedin_headline'] ?? '') ?: ($row['headline'] ?? ''))), 0, 200);
    $body  = [
        'author'     => $author,
        'commentary' => $text,
        'visibility' => 'PUBLIC',
        'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [],
                           'thirdPartyDistributionChannels' => []],
        'lifecycleState' => 'PUBLISHED',
        'isReshareDisabledByAuthor' => false,
    ];

    switch ($kind) {
        case 'Text':
            break;

        case 'Multi-image':
            $imgs = $files['image'];
            if (count($imgs) < 2 && !empty($files['slides'])) {
                $imgs = array_merge($imgs, $files['slides']);
            }
            $ids = [];
            foreach (array_slice($imgs, 0, 20) as $img) {
                [$ok, $urn] = fabo_linkedin_upload_file($acct, $img, 'images');
                if ($ok) {
                    $ids[] = ['id' => $urn, 'altText' => mb_substr((string) ($row['alt_text'] ?? ''), 0, 4000)];
                }
            }
            if (count($ids) >= 2) {
                $body['content'] = ['multiImage' => ['images' => $ids]];
            } elseif ($ids) {
                $body['content'] = ['media' => ['id' => $ids[0]['id'], 'altText' => $ids[0]['altText']]];
            }
            break;

        case 'Carousel':
        case 'Document':
            [$ok, $urn] = fabo_linkedin_upload_file($acct, $files['pdf'][0], 'documents');
            if (!$ok) {
                return [false, $urn, ''];
            }
            $body['content'] = ['media' => ['id' => $urn, 'title' => $title ?: 'Document']];
            break;

        case 'Video':
            [$ok, $urn] = fabo_linkedin_upload_video($acct, $files['video'][0]);
            if (!$ok) {
                return [false, $urn, ''];
            }
            $body['content'] = ['media' => ['id' => $urn, 'title' => $title]];
            break;

        case 'Poll':
            $opts = array_slice(array_values(array_filter(array_map('trim',
                        explode('|', (string) ($row['poll_options'] ?? ''))))), 0, 4);
            $days = ['1 day' => 'ONE_DAY', '3 days' => 'THREE_DAYS', '1 week' => 'SEVEN_DAYS',
                     '2 weeks' => 'FOURTEEN_DAYS'][(string) ($row['poll_duration'] ?? '')] ?? 'SEVEN_DAYS';
            $body['content'] = ['poll' => [
                'question' => mb_substr(trim((string) $row['poll_question']), 0, 140),
                'options'  => array_map(static fn($o) => ['text' => mb_substr($o, 0, 30)], $opts),
                'settings' => ['duration' => $days],
            ]];
            break;

        case 'Article':
        case 'Newsletter':
        case 'Event':
        case 'Job':
            $article = [
                'source'      => (string) (($row['cta_url'] ?? '') ?: ($row['source_url'] ?? '')),
                'title'       => $title,
                'description' => mb_substr(trim((string) (($row['supporting_text'] ?? '')
                                    ?: ($row['primary_message'] ?? ''))), 0, 250),
            ];
            if ($files['image']) {
                [$ok, $urn] = fabo_linkedin_upload_file($acct, $files['image'][0], 'images');
                if ($ok) {
                    $article['thumbnail'] = $urn;
                }
            }
            $body['content'] = ['article' => $article];
            break;

        default:    // Image
            if ($files['image']) {
                [$ok, $urn] = fabo_linkedin_upload_file($acct, $files['image'][0], 'images');
                if ($ok) {
                    $body['content'] = ['media' => ['id' => $urn,
                                        'altText' => mb_substr((string) ($row['alt_text'] ?? ''), 0, 4000)]];
                }
                /* A picture that would not upload is not a reason to lose the
                   post: the text still goes. */
            }
    }

    [$ok, $res, $status] = fabo_http('POST', 'https://api.linkedin.com/rest/posts',
                                     $body, fabo_linkedin_head($acct), 45);
    if (!$ok) {
        $why = is_array($res) ? (string) ($res['message'] ?? json_encode($res)) : (string) $res;
        return [false, 'LinkedIn refused it (' . $status . '): ' . $why, ''];
    }
    $id = '';
    foreach ($GLOBALS['fabo_http_headers'] ?? [] as $h) {
        if (stripos($h, 'x-restli-id:') === 0) {
            $id = trim(substr($h, 12));
        }
    }
    if ($id === '' && is_array($res)) {
        $id = (string) ($res['id'] ?? '');
    }
    return [true, $id, $id !== '' ? 'https://www.linkedin.com/feed/update/' . rawurlencode($id) : ''];
}

/* ------------------------------------------------------------ Instagram */

/**
 * Post to Instagram.
 *
 * Two steps, and the first is the one that surprises people: Instagram does
 * not accept an uploaded file. A container is created with a URL, and META
 * fetches the image from it. So the picture must already be sitting on a
 * public HTTPS address — which is why generated creatives live in /media
 * rather than in uploads/, where everything is denied.
 */
function fabo_instagram_post(array $acct, string $caption, string $imageUrl): array
{
    $token = trim((string) $acct['access_token']);
    $igId  = trim((string) $acct['account_urn']);
    if ($igId === '') {
        return [false, 'That Instagram connection has no account id.', ''];
    }
    if ($imageUrl === '') {
        return [false, 'Instagram will not take a post without an image.', ''];
    }
    if (!str_starts_with(strtolower($imageUrl), 'https://')) {
        return [false, 'Instagram needs the image on a public https address. '
                     . 'This portal is not being served over https.', ''];
    }
    if (str_ends_with(strtolower($imageUrl), '.svg')) {
        return [false, 'Instagram will not take an SVG. No font was available on the '
                     . 'server to make a PNG — see lib/creative.php.', ''];
    }

    $base = 'https://graph.facebook.com/v21.0/';
    [$ok, $res] = fabo_http('POST', $base . rawurlencode($igId) . '/media', [
        'image_url'    => $imageUrl,
        'caption'      => $caption,
        'access_token' => $token,
    ], ['Content-Type: application/json'], 60);
    if (!$ok) {
        $why = is_array($res) ? (string) ($res['error']['message'] ?? json_encode($res)) : (string) $res;
        return [false, 'Instagram refused the image: ' . $why, ''];
    }
    $container = (string) ($res['id'] ?? '');
    if ($container === '') {
        return [false, 'Instagram did not return a container id.', ''];
    }

    /* Meta fetches the image in its own time; publishing before it has
       finished is refused, so the container is given a few seconds. */
    for ($i = 0; $i < 6; $i++) {
        [$sOk, $sRes] = fabo_http('GET', $base . rawurlencode($container)
            . '?fields=status_code&access_token=' . rawurlencode($token), null, [], 20);
        $state = $sOk ? (string) ($sRes['status_code'] ?? '') : '';
        if ($state === 'FINISHED') {
            break;
        }
        if ($state === 'ERROR') {
            return [false, 'Instagram could not fetch the image from ' . $imageUrl, ''];
        }
        sleep(3);
    }

    [$ok, $res] = fabo_http('POST', $base . rawurlencode($igId) . '/media_publish', [
        'creation_id'  => $container,
        'access_token' => $token,
    ], ['Content-Type: application/json'], 60);
    if (!$ok) {
        $why = is_array($res) ? (string) ($res['error']['message'] ?? json_encode($res)) : (string) $res;
        return [false, 'Instagram refused to publish it: ' . $why, ''];
    }
    $id = (string) ($res['id'] ?? '');
    return [true, $id, $id !== '' ? 'https://www.instagram.com/p/' . rawurlencode($id) . '/' : ''];
}

/* ------------------------------------------------------- the publishing */

/** The text that actually goes out, for the platform it is going to. */
function fabo_social_text(array $row, string $platform): string
{
    $ig   = strcasecmp($platform, 'instagram') === 0;
    $copy = trim((string) ($ig ? ($row['instagram_copy'] ?? '') : ($row['linkedin_copy'] ?? '')));
    if ($copy === '') {
        $copy = trim((string) ($row['primary_message'] ?? ''));
    }
    $tags = trim((string) ($row['hashtags'] ?? ''));
    if ($tags !== '' && !str_contains($copy, '#')) {
        $copy .= "\n\n" . $tags;
    }
    $cta = trim((string) ($row['cta_url'] ?? ''));
    /* A link in an Instagram caption is not clickable, so it is left out
       rather than printed as decoration. LinkedIn's is. */
    if (!$ig && $cta !== '' && !str_contains($copy, $cta)) {
        $copy .= "\n\n" . $cta;
    }
    $max = fabo_social_platforms()[strtolower($platform)]['max_text'] ?? 3000;
    return mb_substr($copy, 0, $max);
}

/**
 * Publish one row to one platform. Returns [ok, note].
 *
 * Everything that can refuse does so before the claim, so a refusal does not
 * burn the one attempt that matters.
 */
function fabo_social_publish(int $rowId, string $platform): array
{
    require_once __DIR__ . '/webcontent.php';
    $row = fabo_one('SELECT * FROM `' . FABO_WC_TABLE . '` WHERE id = ?', 'i', [$rowId]);
    if (!$row) {
        return [false, 'That row is gone.'];
    }
    $clientId = (int) $row['client_id'];

    [$ready, $why] = fabo_social_ready($clientId, $platform);
    if (!$ready) {
        return [false, $why];
    }
    $status = strtoupper((string) $row['status']);
    if (!in_array($status, ['APPROVED', 'SCHEDULED', 'PUBLISHING'], true)) {
        return [false, 'That post has not been approved, so it was not published.'];
    }
    /* The status says it may go; the approval record must agree. A post
       rejected, or sent back for approval after an edit, does not go out
       whatever its status cell has been typed to. */
    $decided = fabo_wc_approval_state($rowId);
    if ($decided !== '' && $decided !== 'approved') {
        return [false, 'That post is ' . ($decided === 'rejected' ? 'rejected' : 'waiting for approval')
                     . ', so it was not published.'];
    }
    $text = fabo_social_text($row, $platform);
    if (trim($text) === '') {
        return [false, 'There is no copy to post.'];
    }
    /* What the kind of post needs -- a video, a PDF, a poll question --
       checked before the one attempt is claimed. */
    if (strcasecmp($platform, 'linkedin') === 0) {
        [$fit, $why] = fabo_linkedin_ready_row($row);
        if (!$fit) {
            return [false, $why];
        }
    }

    $image = '';
    $asset = trim((string) ($row['media_asset'] ?? ''));
    if ($asset !== '') {
        require_once __DIR__ . '/creative.php';
        $image = fabo_media_url($asset);
    }

    /* From here on, one attempt only. */
    $attempt = fabo_social_claim($rowId, $clientId, $platform);
    if ($attempt === 0) {
        return [false, 'That post is already published, already going out, or waiting on '
                     . 'somebody to check whether an earlier attempt landed.'];
    }
    $acct = fabo_social_account($clientId, $platform);

    try {
        if (strcasecmp($platform, 'linkedin') === 0) {
            [$ok, $idOrWhy, $url] = fabo_linkedin_post_row($acct, $row, $text);
        } else {
            [$ok, $idOrWhy, $url] = fabo_instagram_post($acct, $text, $image);
        }
    } catch (Throwable $e) {
        /* The dangerous case. The request went out and no answer came back,
           so whether it posted is genuinely unknown. Marked as such, and
           never retried automatically. */
        fabo_social_settle($attempt, 'unknown', '', '', $e->getMessage());
        fabo_exec('UPDATE `' . FABO_WC_TABLE . '`
                      SET publishing_status = ? WHERE id = ?', 'si',
                  ['Unclear whether this posted — check ' . $platform . ' before retrying.',
                   $rowId]);
        return [false, 'The call did not come back. Check ' . $platform
                     . ' before trying again — it may have posted.'];
    }

    if (!$ok) {
        fabo_social_settle($attempt, 'failed', '', '', (string) $idOrWhy);
        fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET status = "FAILED", publishing_status = ?
                    WHERE id = ?', 'si', [mb_substr((string) $idOrWhy, 0, 400), $rowId]);
        return [false, (string) $idOrWhy];
    }

    fabo_social_settle($attempt, 'published', (string) $idOrWhy, (string) $url);
    fabo_exec('UPDATE `' . FABO_WC_TABLE . '`
                  SET status = "PUBLISHED", published_at = ?, platform_post_url = ?,
                      publishing_status = ?
                WHERE id = ?', 'sssi',
              [date('Y-m-d H:i'), (string) $url, 'Published to ' . $platform . '.', $rowId]);
    return [true, 'Published to ' . $platform . '.'];
}

/**
 * Everything whose time has come. Called from cron.php.
 *
 * A row is due when it is SCHEDULED and its date and time have passed. The
 * query claims nothing — fabo_social_publish() does that — so two sweeps
 * running at once is safe.
 */
function fabo_social_sweep(int $max = 10): int
{
    require_once __DIR__ . '/webcontent.php';
    try {
        $due = fabo_all(
            'SELECT id, platform FROM `' . FABO_WC_TABLE . '`
              WHERE status = "SCHEDULED" AND scheduled_date IS NOT NULL
                AND scheduled_date <> "0000-00-00"
                AND CONCAT(scheduled_date, " ",
                           COALESCE(NULLIF(scheduled_time, ""), "09:00")) <= ?
              ORDER BY scheduled_date, id LIMIT ?', 'si', [date('Y-m-d H:i'), $max]);
    } catch (Throwable $e) {
        return 0;
    }
    $done = 0;
    foreach ($due as $r) {
        [$ok, $why] = fabo_social_publish((int) $r['id'], (string) ($r['platform'] ?: 'LinkedIn'));
        if ($ok) {
            $done++;
        } else {
            /* Due but not sent -- usually no account connected yet. Said on
               the row, so a post that is past its time is never silently
               sitting there. The status stays SCHEDULED: once the reason is
               fixed, the next sweep sends it. */
            fabo_exec('UPDATE `' . FABO_WC_TABLE . '` SET publishing_status = ?
                        WHERE id = ? AND status = "SCHEDULED"', 'si',
                      [mb_substr('Due, not sent: ' . (string) $why, 0, 400), (int) $r['id']]);
        }
    }
    return $done;
}

/** The queue's entry points into this file. */
function fabo_jobs_social(): array
{
    return ['social.publish' => 'fabo_social_job_publish'];
}

function fabo_social_job_publish(array $p): string
{
    [$ok, $note] = fabo_social_publish((int) ($p['row_id'] ?? 0), (string) ($p['platform'] ?? ''));
    if (!$ok) {
        throw new RuntimeException($note);
    }
    return $note;
}
