<?php
/**
 * The Add Website button, and how far along each site is.
 *
 * Two jobs, and the second matters more than it looks. The work behind this
 * button takes minutes, not seconds, so a panel that shows one unchanging
 * word looks broken and gets pressed again. It shows a bar instead, and the
 * page keeps asking — and that asking is also what MOVES the work on a
 * portal whose cron has not been set up yet. See api/wcprogress.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/webcontent.php';
require_once __DIR__ . '/lists.php';
require_once __DIR__ . '/approvals.php';

/**
 * Whose website this is.
 *
 * Nobody is asked. A client working in their own portal is adding it for
 * themselves — there is no other answer, and a dropdown offering one option
 * is a question that wastes a press. We add ours at scope 0, the same place
 * our own sheets and dropdowns live.
 */
function fabo_wc_owner(array $viewer): int
{
    if (!empty($viewer['is_admin'])) {
        return 0;
    }
    return fabo_customiser($viewer);
}

/** How many websites the panel lists before "Show all". */
const FABO_WC_SHOWN = 3;

/** The websites this viewer may see, newest first -- not the ones hidden. */
function fabo_wc_sites(array $viewer): array
{
    if (!empty($viewer['is_admin'])) {
        return fabo_all('SELECT * FROM website_sources WHERE COALESCE(hidden, 0) = 0
                          ORDER BY id DESC LIMIT 25');
    }
    $me = fabo_customiser($viewer);
    return fabo_all('SELECT * FROM website_sources
                      WHERE (client_id = ? OR client_id IN
                             (SELECT id FROM clients WHERE parent_id = ?))
                        AND COALESCE(hidden, 0) = 0
                      ORDER BY id DESC LIMIT 25', 'ii', [$me, $me]);
}

/**
 * The block itself, rendered above the grid by lib/sheetpage.php — the same
 * way the requisition calendar and the event blocks are.
 */
function fabo_wc_block(array $viewer, string $perm): void
{
    $mayAdd = $perm === 'edit';
    $sites  = fabo_wc_sites($viewer);
    $ai     = function_exists('fabo_gemini_configured') && fabo_gemini_configured();
    /* The endpoint works out permission from ?sheet=, the same way every
       other api/ call does, so the name has to be on the URL. */
    $poll   = fabo_link($viewer, ($viewer['is_admin'] ? '../api/' : 'api/') . 'wcprogress.php',
                        ['sheet' => FABO_WC_SHEET] + ($viewer['is_admin'] ? ['as' => 'admin'] : []));
    ?>
    <div class="card wcbox">
      <div class="wcbox-head">
        <div>
          <h2>Websites</h2>
          <div class="hint">Add a website: it is read, and posts with their graphics are
            written onto the grid below and scheduled at the time you choose.</div>
        </div>
        <?php if ($mayAdd): ?>
          <button class="btn btn-primary" type="button" id="wcAdd">+ Add website</button>
        <?php endif; ?>
      </div>

      <?php if (!$ai): ?>
        <div class="wcwarn">No Gemini API key is set, so nothing can be written. Add
          <code>GEMINI_API_KEY</code> to <code>config.local.php</code>.</div>
      <?php endif; ?>

      <?php if ($sites): ?>
        <?php /* One slim line per website, newest first, and only the latest
                 few unless asked: a panel listing every site ever added in
                 full pushed the grid -- the actual work -- off the screen. */ ?>
        <div class="wclist" id="wcList">
        <?php foreach ($sites as $n => $s):
            [$pc, $stage, $note] = fabo_wc_progress($s);
            $state = (string) $s['state'];
            $done  = in_array($state, ['GENERATED', 'FAILED'], true);
            $tone  = $state === 'FAILED' ? 'bad' : ($state === 'GENERATED' ? 'ok' : 'go');
        ?>
          <div class="wcrow<?= $n >= FABO_WC_SHOWN ? ' wcmore' : '' ?>" data-site="<?= (int) $s['id'] ?>"
               <?= $n >= FABO_WC_SHOWN ? 'hidden' : '' ?>>
            <div class="wcrow-who">
              <a href="<?= e((string) $s['website_url']) ?>" target="_blank"
                 rel="noopener noreferrer"><?= e((string) $s['site_host']) ?></a>
              <?php if (trim((string) $s['brand_name']) !== ''): ?>
                <span class="wcbrand"><?= e((string) $s['brand_name']) ?></span>
              <?php endif; ?>
            </div>
            <div class="wcrow-state">
              <div class="wcbar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                   aria-valuenow="<?= (int) $pc ?>">
                <span class="wcbar-fill <?= e($tone) ?>" style="width:<?= (int) $pc ?>%"></span>
              </div>
              <span class="wcstage"><?= e($stage) ?></span>
              <span class="wcpc"><?= (int) $pc ?>%</span>
              <span class="wcnote" title="<?= e($note) ?>"><?= e($note) ?></span>
            </div>
            <div class="wcrow-when">
              <?= e($s['post_date'] ? date('d M', strtotime((string) $s['post_date'])) : '—') ?>
              <?= e(trim((string) ($s['post_time'] ?? '')) !== '' ? '· ' . $s['post_time'] : '') ?>
            </div>
            <?php if ($mayAdd): ?>
              <div class="wcrow-act">
                <?php $ready = $done ? count(fabo_wc_ready_to_approve((int) $s['id'])) : 0; ?>
                <?php if ($ready > 0 && fabo_may_request($viewer, 'webcontent')): ?>
                  <form method="post" onsubmit="var b=this.querySelector('button'); if (b) { b.disabled = true; b.textContent = 'Approving…'; }">
                    <input type="hidden" name="csrf" value="<?= e(fabo_csrf_token()) ?>">
                    <input type="hidden" name="action" value="wc_approve_all">
                    <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-sm pvgo" title="Approve every post that passed the claim check and has what it needs. Rejected posts are left alone."
                      onclick="return confirm(<?= e(json_encode('Approve ' . $ready . ' post' . ($ready === 1 ? '' : 's') . ' from ' . $s['site_host'] . ' and put ' . ($ready === 1 ? 'it' : 'them') . ' on the schedule?')) ?>);"
                      >✓ Approve <?= $ready ?> ready</button>
                  </form>
                <?php endif; ?>
                <?php if ($done): ?>
                  <form method="post" onsubmit="var b=this.querySelector('button'); if (b) { b.disabled = true; b.textContent = '…'; }">
                    <input type="hidden" name="csrf" value="<?= e(fabo_csrf_token()) ?>">
                    <input type="hidden" name="action" value="wc_rewrite">
                    <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-sm" title="Read the site again and rewrite the posts"
                      onclick="return confirm('Read the site again and write the posts from scratch? Posts you have not edited are replaced; any you have edited are kept.');"
                      >Read again</button>
                  </form>
                  <?php /* Off the panel, posts kept: for a site that is done
                           with and only taking up room. */ ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(fabo_csrf_token()) ?>">
                    <input type="hidden" name="action" value="wc_hide">
                    <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                    <button class="btn btn-sm" title="Take this website off the list. Its posts stay on the grid.">Hide</button>
                  </form>
                <?php endif; ?>
                <?php /* Offered at any stage, so a site stuck part way can be
                         cleared and started again. */ ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= e(fabo_csrf_token()) ?>">
                  <input type="hidden" name="action" value="wc_remove">
                  <input type="hidden" name="site_id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-sm btn-danger" title="Delete this website and its posts"
                    onclick="return confirm(<?= e(json_encode('Remove ' . (string) $s['site_host'] . '? Its posts on the grid below are deleted too. This cannot be undone.')) ?>);"
                    >Remove</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <?php if (count($sites) > FABO_WC_SHOWN): ?>
          <button type="button" class="wcshowall" id="wcShowAll"
            onclick="document.querySelectorAll('#wcList .wcmore').forEach(function (r) { r.hidden = false; }); this.remove();"
            >Show all <?= count($sites) ?> websites</button>
        <?php endif; ?>
      <?php else: ?>
        <div class="wcempty">No websites yet. Press <strong>Add website</strong> to start one.</div>
      <?php endif; ?>
    </div>

    <?php if ($mayAdd): ?>
    <div class="wcmodal" id="wcModal" hidden>
      <div class="wcmodal-card" role="dialog" aria-modal="true" aria-labelledby="wcModalTitle">
        <h3 id="wcModalTitle">Add a website</h3>
        <p class="hint">Everything else is worked out from the site itself.</p>
        <form method="post" id="wcForm">
          <input type="hidden" name="csrf" value="<?= e(fabo_csrf_token()) ?>">
          <input type="hidden" name="action" value="wc_add">

          <label for="wcUrl">Website link</label>
          <input id="wcUrl" name="website_url" type="url" required
                 placeholder="https://example.com" autocomplete="off">

          <?php /* From the day it is added: today is the default and the
                   earliest allowed. */ ?>
          <div class="wcrow2">
            <div>
              <label for="wcDate">First post date</label>
              <input id="wcDate" name="post_date" type="date" required
                     min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div>
              <label for="wcTime">Posting time</label>
              <input id="wcTime" name="post_time" type="time" required value="10:00">
            </div>
          </div>

          <label for="wcEvery">How often</label>
          <select id="wcEvery" name="post_every">
            <option value="1" selected>Every day</option>
            <option value="2">Every 2 days</option>
            <option value="3">Every 3 days</option>
            <option value="7">Once a week</option>
          </select>
          <div class="hint">The first post goes out on the date above at this time — if that
            time has already passed today, shortly after the posts are written. You can move
            any of them afterwards.</div>

          <label class="wccheck">
            <input type="checkbox" name="auto_post" value="1" checked>
            <span><strong>Post automatically</strong> at the set time. A post whose claims
              do not match the website is held for your review instead.</span>
          </label>

          <div class="wcmodal-foot">
            <button class="btn" type="button" id="wcCancel">Cancel</button>
            <button class="btn btn-primary" type="submit">Add website</button>
          </div>
        </form>
      </div>
    </div>
    <script>
    (function () {
      var modal = document.getElementById('wcModal');
      var open  = document.getElementById('wcAdd');
      var shut  = document.getElementById('wcCancel');
      if (!modal || !open) { return; }
      function show(on) {
        modal.hidden = !on;
        if (on) { document.getElementById('wcUrl').focus(); }
      }
      open.addEventListener('click', function () { show(true); });
      shut.addEventListener('click', function () { show(false); });
      modal.addEventListener('click', function (ev) {
        if (ev.target === modal) { show(false); }
      });
      document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) { show(false); }
      });
      /* Pressed twice is two crawls of the same site. */
      document.getElementById('wcForm').addEventListener('submit', function (ev) {
        var b = ev.target.querySelector('button[type=submit]');
        if (b) { b.disabled = true; b.textContent = 'Adding…'; }
      });
    })();
    </script>
    <?php endif; ?>

    <script>
    /* Ask how it is going, and in asking, make it go.
     *
     * Each call runs a few seconds of queued work and returns where every
     * website stands. That is what lets this finish while somebody watches
     * on a portal with no cron job set up: the polling IS the worker. With
     * cron running it finds the work already done and just draws the bar.
     *
     * It stops as soon as nothing is outstanding, so an idle page costs
     * nothing. */
    (function () {
      var list = document.getElementById('wcList');
      if (!list) { return; }
      var url   = <?= json_encode($poll) ?>;
      var quiet = 0;

      function paint(site) {
        var row = list.querySelector('.wcrow[data-site="' + site.id + '"]');
        if (!row) { window.location.reload(); return; }
        var fill = row.querySelector('.wcbar-fill');
        fill.style.width = site.percent + '%';
        fill.className = 'wcbar-fill ' + (site.state === 'FAILED' ? 'bad'
                       : (site.state === 'GENERATED' ? 'ok' : 'go'));
        row.querySelector('.wcbar').setAttribute('aria-valuenow', site.percent);
        row.querySelector('.wcstage').textContent = site.stage;
        row.querySelector('.wcpc').textContent = site.percent + '%';
        row.querySelector('.wcnote').textContent = site.error || site.note || '';
        if (site.brand && !row.querySelector('.wcbrand')) {
          var s = document.createElement('span');
          s.className = 'wcbrand';
          s.textContent = site.brand;
          row.querySelector('.wcrow-who').appendChild(s);
        }
      }

      /* Three askers at once. The queue hands each job to exactly one of
         them (fabo_job_claim), so the posts are written side by side rather
         than one after another -- most of the wait is the model writing, and
         that is what this shortens. */
      var WORKERS  = 3;
      var reloaded = false;
      function done() {
        /* One last look before giving up: the rows that were just written
           are on the grid, not in this panel. */
        if (!reloaded) { reloaded = true; window.location.reload(); }
      }
      function ask() {
        if (reloaded) { return; }
        fetch(url, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.ok) { return; }
            (res.sites || []).forEach(paint);
            if (res.busy) {
              quiet = 0;
              setTimeout(ask, 1000);
            } else if (quiet++ < 1) {
              setTimeout(ask, 1500);
            } else {
              done();
            }
          })
          .catch(function () { setTimeout(ask, 5000); });
      }

      /* Only bother if something is actually outstanding. */
      var busy = Array.prototype.some.call(list.querySelectorAll('.wcbar'), function (b) {
        var n = parseInt(b.getAttribute('aria-valuenow'), 10);
        return n > 0 && n < 100;
      });
      if (busy) {
        for (var w = 0; w < WORKERS; w++) { setTimeout(ask, 300 + w * 700); }
      }
    })();
    </script>
    <?php
    fabo_wc_preview_block($viewer, $perm);
}

/**
 * The post window: see a post as it will appear, shape it, and sign it off.
 *
 * Opened from the eye button at the start of each row (lib/grid.php).
 *
 *   Left, the post as LinkedIn or Instagram will show it, drawn from the
 *   draft -- so every edit shows the moment it is made.
 *   Right, the editor: the LinkedIn kind, the text, the poll or the slides,
 *   the date and time, the files; and the approval -- where it stands, and
 *   Approve & schedule / Reject / Send for approval.
 *
 * Nothing is stored until Save (api/wcmedia.php, action=save), and Discard
 * puts everything back. A decision cannot be made over unsaved changes:
 * what is approved has to be what is stored.
 */
function fabo_wc_preview_block(array $viewer, string $perm = 'view'): void
{
    /* as=admin: the same browser may also be signed in to a team member's
       portal, and these calls are the admin's. See fabo_endpoint(). */
    $asAdmin  = $viewer['is_admin'] ? ['as' => 'admin'] : [];
    $base     = $viewer['is_admin'] ? '../api/' : 'api/';
    $q        = ['sheet' => FABO_WC_SHEET] + $asAdmin;
    $api      = fabo_link($viewer, $base . 'wcpreview.php', $q);
    $saveApi  = fabo_link($viewer, $base . 'wcmedia.php', $q);
    $decideApi = fabo_link($viewer, $base . 'approve.php', $q);
    $media    = ($viewer['is_admin'] ? '../' : '') . 'media/';
    $kinds    = ['Text' => 'Text', 'Image' => 'Image', 'Multi-image' => 'Multiple images',
                 'Carousel' => 'Carousel (swipeable PDF)', 'Document' => 'Document / PDF guide',
                 'Video' => 'Video', 'Poll' => 'Poll', 'Article' => 'Article (link)',
                 'Newsletter' => 'Newsletter (link)', 'Event' => 'Event (link)', 'Job' => 'Job (link)'];
    ?>
    <div class="pvmodal" id="pvModal" hidden>
      <div class="pvcard pvwide" role="dialog" aria-modal="true" aria-labelledby="pvTitle">
        <div class="pvhead">
          <div>
            <h3 id="pvTitle">Post</h3>
            <div class="pvmeta" id="pvMeta"></div>
          </div>
          <span class="pvpill" id="pvState"></span>
          <button type="button" class="pvx" id="pvClose" aria-label="Close">&times;</button>
        </div>

        <div class="pvbody">
          <section class="pvleft" aria-label="Preview">
            <div class="pvtabs" role="tablist">
              <button type="button" role="tab" data-pv="linkedin">LinkedIn</button>
              <button type="button" role="tab" data-pv="instagram">Instagram</button>
              <span class="pvready" id="pvReady"></span>
            </div>
            <div class="pvstage" id="pvStage"><div class="pvwait">Loading the post…</div></div>
          </section>

          <section class="pvright" aria-label="Edit and approve">
            <div class="pvsec">
              <label class="pvlab" for="pvType">LinkedIn post type</label>
              <select id="pvType" class="pvin">
                <?php foreach ($kinds as $v => $label): ?>
                  <option value="<?= e($v) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="pvsec">
              <label class="pvlab" for="pvCopyText">Post text <span class="pvcount" id="pvCopyCount"></span></label>
              <textarea id="pvCopyText" class="pvin" rows="8"></textarea>
            </div>

            <div class="pvsec" id="pvPollSec" hidden>
              <label class="pvlab" for="pvPollQ">Poll question <span class="pvcount" id="pvPollCount"></span></label>
              <input id="pvPollQ" class="pvin" maxlength="140">
              <div class="pvopts">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                  <input class="pvin pvopt" data-opt="<?= $i - 1 ?>" maxlength="30"
                         placeholder="Option <?= $i ?><?= $i > 2 ? ' (optional)' : '' ?>">
                <?php endfor; ?>
              </div>
              <label class="pvlab" for="pvPollDur">Runs for</label>
              <select id="pvPollDur" class="pvin">
                <option>1 day</option><option>3 days</option><option selected>1 week</option><option>2 weeks</option>
              </select>
            </div>

            <div class="pvsec" id="pvSlideSec" hidden>
              <label class="pvlab" for="pvSlides">Carousel slides</label>
              <textarea id="pvSlides" class="pvin" rows="7"></textarea>
              <div class="pvhint">One slide per line, as <em>Title — what the slide says</em>. The first
                is the cover, the last the call to action. The PDF is rebuilt when you save.</div>
            </div>

            <div class="pvsec pvrow2">
              <div>
                <label class="pvlab" for="pvDate">Goes out on</label>
                <input id="pvDate" type="date" class="pvin">
              </div>
              <div>
                <label class="pvlab" for="pvTime">At</label>
                <input id="pvTime" type="time" class="pvin">
              </div>
            </div>

            <div class="pvsec">
              <div class="pvlab">Your files <span class="pvhint">— used instead of the generated graphic or carousel</span></div>
              <ul class="pvfilelist" id="pvFileList"></ul>
              <label class="btn btn-sm pvupload" id="pvUploadBtn">
                + Upload image, PDF or video
                <input type="file" id="pvUpload" hidden
                       accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,video/quicktime">
              </label>
              <span class="pvupnote" id="pvUpNote"></span>
            </div>

            <div class="pvsec pvapprove" id="pvApprove">
              <div class="pvlab">Approval</div>
              <div class="pvdecision" id="pvDecision"></div>
              <div class="pvreject" id="pvRejectBox" hidden>
                <label class="pvlab" for="pvRejectWhy">What needs changing?</label>
                <textarea id="pvRejectWhy" class="pvin" rows="3"
                          placeholder="Required — the writer sees this."></textarea>
                <div class="pvbtns">
                  <button type="button" class="btn btn-sm" id="pvRejectCancel">Cancel</button>
                  <button type="button" class="btn btn-sm btn-danger" id="pvRejectSend">Reject</button>
                </div>
              </div>
              <div class="pvbtns" id="pvDecideBtns">
                <button type="button" class="btn btn-sm pvgo" id="pvApproveBtn">✓ Approve &amp; schedule</button>
                <button type="button" class="btn btn-sm btn-danger" id="pvRejectBtn">Reject</button>
                <button type="button" class="btn btn-sm" id="pvAskBtn">Send for approval…</button>
              </div>
              <div class="pvhint" id="pvDecideNote"></div>
            </div>
          </section>
        </div>

        <div class="pvfoot">
          <span class="pvcheck" id="pvCheck"></span>
          <span class="pvdirty" id="pvDirty" hidden>● Unsaved changes</span>
          <span class="pvsaved" id="pvSaved"></span>
          <button type="button" class="btn btn-sm" id="pvCopy">Copy text</button>
          <button type="button" class="btn btn-sm" id="pvDiscard" disabled>Discard</button>
          <button type="button" class="btn btn-sm btn-primary" id="pvSave" disabled>Save changes</button>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var API = <?= json_encode($api) ?>, SAVE = <?= json_encode($saveApi) ?>,
          DECIDE = <?= json_encode($decideApi) ?>, MEDIA = <?= json_encode($media) ?>,
          CSRF = <?= json_encode(fabo_csrf_token()) ?>;
      var $ = function (id) { return document.getElementById(id); };
      var modal = $('pvModal'), stage = $('pvStage');
      /* orig is the post as stored; draft is what is on screen. Save sends
         the difference; Discard copies orig back over draft. */
      var orig = null, draft = null, tab = 'linkedin', page = 0, current = null;
      var pending = [];          // uploaded this session, not yet saved
      var FIELDS = ['linkedin_type', 'linkedin_copy', 'poll_question', 'poll_options', 'poll_duration',
                    'carousel_slides', 'scheduled_date', 'scheduled_time'];

      function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text != null) { n.textContent = text; }
        return n;
      }
      function clone(o) { return JSON.parse(JSON.stringify(o)); }
      function filesKey(p) { return JSON.stringify((p.files || []).map(function (f) { return f.file; })); }
      function changes() {
        var out = {};
        FIELDS.forEach(function (f) {
          if (String(draft[f] || '') !== String(orig[f] || '')) { out[f] = draft[f] || ''; }
        });
        if (filesKey(draft) !== filesKey(orig)) { out.post_media = JSON.stringify(draft.files || []); }
        return out;
      }
      function dirty() { return !!draft && Object.keys(changes()).length > 0; }

      /* ------------------------------------------------------- the preview */
      function rich(text) {
        var box = el('div', 'pvtext');
        String(text || '').split(/(#[\p{L}\p{N}_]+)/u).forEach(function (bit) {
          if (bit === '') { return; }
          box.appendChild(bit.charAt(0) === '#' && bit.length > 1
            ? el('span', 'pvtag', bit) : document.createTextNode(bit));
        });
        return box;
      }
      function withTags(copy, tags) {
        copy = String(copy || '').trim();
        tags = String(tags || '').trim();
        if (!tags || copy.indexOf('#') !== -1) { return copy; }
        return copy + '\n\n' + tags.split(/[\s,]+/).filter(Boolean).map(function (t) {
          return t.charAt(0) === '#' ? t : '#' + t;
        }).join(' ');
      }
      function igText() {
        return withTags([draft.instagram_hook, draft.instagram_copy].filter(Boolean).join('\n\n'), draft.hashtags);
      }
      function avatar(name) {
        return el('div', 'pvavatar', (String(name || '?').trim().charAt(0) || '?').toUpperCase());
      }
      function files(kind) { return (draft.files || []).filter(function (f) { return f.kind === kind; }); }
      function img(src, alt) { var i = el('img'); i.src = src; i.alt = alt || ''; i.loading = 'lazy'; return i; }
      function mainImage() {
        var mine = files('image');
        if (mine.length) { return MEDIA + mine[0].file; }
        return draft.image ? MEDIA + draft.image : '';
      }
      function picture(square) {
        var wrap = el('div', 'pvimg' + (square ? ' sq' : ''));
        var src = mainImage();
        if (src) { wrap.appendChild(img(src, draft.alt_text || draft.headline)); }
        else {
          var ph = el('div', 'pvph');
          ph.appendChild(el('strong', null, draft.headline || draft.linkedin_headline || 'No graphic yet'));
          wrap.appendChild(ph);
        }
        return wrap;
      }
      function host(url) { try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return ''; } }
      function multi() {
        var srcs = files('image').map(function (f) { return MEDIA + f.file; });
        if (srcs.length < 2) {
          if (draft.image) { srcs.push(MEDIA + draft.image); }
          (draft.pages || []).forEach(function (p) { srcs.push(MEDIA + p); });
        }
        var g = el('div', 'pvgrid n' + Math.min(srcs.length, 4));
        srcs.slice(0, 4).forEach(function (s, i) {
          var cell = el('div', 'pvgcell');
          cell.appendChild(img(s, ''));
          if (i === 3 && srcs.length > 4) { cell.appendChild(el('span', 'pvmore4', '+' + (srcs.length - 4))); }
          g.appendChild(cell);
        });
        return g;
      }
      /* ------------------------------------------- documents, as a book
         LinkedIn shows a document post one page at a time, turned with a
         swipe -- so the preview does too, whether the pages are the
         carousel the portal made or a PDF somebody uploaded. A PDF is drawn
         page by page in the browser with PDF.js (loaded only when a PDF is
         opened), and every drawn page is kept, so turning back is instant. */
      var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
      var pdfCache = {};
      var turnBook = null;            // the open book's turn(), for the arrow keys
      function loadPdfJs() {
        if (window.pdfjsLib) { return Promise.resolve(window.pdfjsLib); }
        if (loadPdfJs.p) { return loadPdfJs.p; }
        /* The worker's code is loaded into the page itself, so PDF.js runs
           on the page rather than in a background worker. A browser will not
           start a worker from another site's address, and when PDF.js cannot
           start one it can wait for ever; on the page it always works, and a
           handful of pages is quick either way. */
        function script(src) {
          return new Promise(function (ok, no) {
            var s = document.createElement('script');
            s.src = src;
            s.onload = ok;
            s.onerror = function () { no(new Error('The PDF reader could not load.')); };
            document.head.appendChild(s);
          });
        }
        loadPdfJs.p = script(PDFJS + 'pdf.min.js')
          .then(function () { return script(PDFJS + 'pdf.worker.min.js'); })
          .then(function () {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
            return window.pdfjsLib;
          })
          .catch(function (e) { loadPdfJs.p = null; throw e; });
        return loadPdfJs.p;
      }
      function pdfSource(url) {
        if (!pdfCache[url]) {
          pdfCache[url] = { pages: {}, doc: loadPdfJs().then(function (lib) { return lib.getDocument(url).promise; }) };
        }
        var c = pdfCache[url];
        return {
          count: c.doc.then(function (d) { return d.numPages; }),
          page: function (i) {
            if (!c.pages[i]) {
              c.pages[i] = c.doc.then(function (d) { return d.getPage(i + 1); }).then(function (p) {
                var v = p.getViewport({ scale: 1 });
                v = p.getViewport({ scale: 1200 / v.width });
                var cv = document.createElement('canvas');
                cv.width = Math.round(v.width);
                cv.height = Math.round(v.height);
                return p.render({ canvasContext: cv.getContext('2d'), viewport: v }).promise
                  .then(function () { return cv.toDataURL('image/jpeg', 0.9); });
              });
            }
            return c.pages[i];
          }
        };
      }
      function imageSource(urls) {
        return { count: Promise.resolve(urls.length), page: function (i) { return Promise.resolve(urls[i]); } };
      }
      function preload(src) {
        return new Promise(function (ok) { var im = new Image(); im.onload = im.onerror = function () { ok(src); }; im.src = src; });
      }

      function book(source, title, label, fallback) {
        var box = el('div', 'pvdoc'), bar = el('div', 'pvdocbar');
        bar.appendChild(el('strong', null, title));
        var ctr = el('span', null, label || '');
        bar.appendChild(ctr);
        box.appendChild(bar);
        var view = el('div', 'pvbook');
        view.setAttribute('role', 'region');
        view.setAttribute('aria-label', 'Document — use the arrow keys or swipe to turn the page');
        view.tabIndex = 0;
        var base = el('img', 'pvpage');
        base.alt = '';
        base.draggable = false;
        view.appendChild(base);
        var prev = el('button', 'pvdocnav prev', '‹'), next = el('button', 'pvdocnav next', '›');
        prev.type = next.type = 'button';
        prev.setAttribute('aria-label', 'Previous page');
        next.setAttribute('aria-label', 'Next page');
        view.appendChild(prev);
        view.appendChild(next);
        var loading = el('div', 'pvbookwait', 'Opening…');
        view.appendChild(loading);
        box.appendChild(view);
        var track = el('div', 'pvbookbar'), fill = el('span');
        track.appendChild(fill);
        box.appendChild(track);
        var n = 0, busy = false;

        function update() {
          ctr.textContent = (label ? label + ' · ' : '') + (page + 1) + ' / ' + n;
          prev.disabled = page <= 0;
          next.disabled = page >= n - 1;
          fill.style.width = (n ? ((page + 1) / n) * 100 : 0) + '%';
          base.alt = 'Page ' + (page + 1) + ' of ' + n;
        }
        /* The turn. Forward, the page you were on lifts from the spine and
           folds over to the left, showing the next one beneath; back, the
           previous page folds in from the left over the one you were on. */
        function turn(dir) {
          var to = page + dir;
          if (busy || to < 0 || to >= n) { return; }
          busy = true;
          source.page(to).then(preload).then(function (src) {
            var leaf = el('div', 'pvleaf'), front = el('div', 'pvface'), back = el('div', 'pvface pvback');
            var fimg = el('img');
            fimg.draggable = false;
            front.appendChild(fimg);
            leaf.appendChild(front);
            leaf.appendChild(back);
            /* Placed at its starting angle, laid out, and only then given
               the transition and its end angle -- in that order, or the
               browser can jump straight to the end with no turn at all. */
            fimg.src = dir > 0 ? base.src : src;
            leaf.style.transform = dir > 0 ? 'rotateY(0deg)' : 'rotateY(-180deg)';
            view.appendChild(leaf);
            if (dir > 0) { base.src = src; }
            void leaf.offsetWidth;
            leaf.classList.add('turning');
            void leaf.offsetWidth;
            leaf.style.transform = dir > 0 ? 'rotateY(-180deg)' : 'rotateY(0deg)';
            page = to;
            update();
            setTimeout(function () {
              if (dir < 0) { base.src = src; }
              leaf.remove();
              busy = false;
              if (page + 1 < n) { source.page(page + 1).then(preload); }
            }, 640);
          }).catch(function () { busy = false; });
        }
        prev.onclick = function (ev) { ev.stopPropagation(); turn(-1); };
        next.onclick = function (ev) { ev.stopPropagation(); turn(1); };

        /* Swipe or drag sideways to turn; a tap on the right half turns
           forward, on the left half back -- as a reader would expect. */
        var x0 = null, y0 = null;
        view.addEventListener('pointerdown', function (ev) { x0 = ev.clientX; y0 = ev.clientY; });
        view.addEventListener('pointerup', function (ev) {
          if (x0 === null || ev.target.closest('.pvdocnav')) { x0 = null; return; }
          var dx = ev.clientX - x0, dy = ev.clientY - y0;
          if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) { turn(dx < 0 ? 1 : -1); }
          else if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
            var r = view.getBoundingClientRect();
            turn(ev.clientX - r.left > r.width / 2 ? 1 : -1);
          }
          x0 = null;
        });
        view.addEventListener('keydown', function (ev) {
          if (ev.key === 'ArrowRight') { ev.preventDefault(); turn(1); }
          if (ev.key === 'ArrowLeft') { ev.preventDefault(); turn(-1); }
        });
        turnBook = turn;

        source.count.then(function (c) {
          n = c;
          if (!n) { throw new Error('This document has no pages.'); }
          page = Math.max(0, Math.min(page, n - 1));
          return source.page(page).then(preload);
        }).then(function (src) {
          base.src = src;
          loading.remove();
          update();
          if (page + 1 < n) { source.page(page + 1).then(preload); }
        }).catch(function (e) {
          turnBook = null;
          view.remove();
          track.remove();
          if (fallback) { box.appendChild(fallback()); }
          else { box.appendChild(el('div', 'pvwait', e.message)); }
        });
        return box;
      }

      function documentView() {
        var title = draft.linkedin_headline || draft.headline || 'Document';
        var mine = files('pdf');
        if (mine.length) {
          var url = MEDIA + mine[0].file;
          return book(pdfSource(url), title, mine[0].name || 'Your PDF', function () {
            /* No PDF reader to hand (offline, or the address blocked): the
               browser's own viewer, which at least shows the document. */
            var fr = el('iframe', 'pvpdf');
            fr.src = url + '#toolbar=0&view=FitH';
            fr.title = 'Your PDF';
            return fr;
          });
        }
        var pages = draft.pages || [];
        var slidesChanged = String(draft.carousel_slides || '') !== String(orig.carousel_slides || '');
        if (!pages.length) {
          var box = el('div', 'pvdoc');
          box.appendChild(el('div', 'pvwait', draft.linkedin_type === 'Carousel'
            ? 'Add slides on the right and save — the carousel is made from them.'
            : 'Upload a PDF on the right.'));
          return box;
        }
        var b = book(imageSource(pages.map(function (p) { return MEDIA + p; })), title, '');
        if (draft.linkedin_type === 'Carousel' && slidesChanged) {
          b.insertBefore(el('div', 'pvbooknote', 'The slides have changed — the carousel is rebuilt when you save.'),
                         b.children[1]);
        }
        return b;
      }
      function videoView() {
        var mine = files('video');
        if (!mine.length) { return el('div', 'pvempty', 'Upload a video on the right. LinkedIn takes MP4 or MOV.'); }
        var v = el('video', 'pvvideo');
        v.src = MEDIA + mine[0].file;
        v.controls = true;
        v.preload = 'metadata';
        return v;
      }
      function pollView() {
        var box = el('div', 'pvpoll');
        box.appendChild(el('div', 'pvpollq', draft.poll_question || 'Write the poll question on the right'));
        String(draft.poll_options || '').split('|').map(function (o) { return o.trim(); })
          .filter(Boolean).forEach(function (o) { box.appendChild(el('div', 'pvpollopt', o)); });
        box.appendChild(el('div', 'pvpollmeta', '0 votes · ' + (draft.poll_duration || '1 week') + ' left'));
        return box;
      }
      function linkCard(kind) {
        var url = draft.cta_url || draft.source_url || '';
        var a = el('a', 'pvlinkcard' + (kind === 'Event' || kind === 'Job' ? ' pvside' : ''));
        a.href = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
        a.appendChild(picture(false));
        var meta = el('div', 'pvlinkmeta');
        if (kind === 'Event') {
          meta.appendChild(el('span', 'pvkicker', [draft.scheduled_date, draft.scheduled_time].filter(Boolean).join(' · ')));
        } else if (kind === 'Job' || kind === 'Newsletter') {
          meta.appendChild(el('span', 'pvkicker', kind + ' · ' + (draft.brand_name || '')));
        }
        meta.appendChild(el('strong', null, draft.linkedin_headline || draft.headline || url));
        meta.appendChild(el('span', null, host(url)));
        if (kind === 'Event' || kind === 'Job') { meta.appendChild(el('span', 'pvact', kind === 'Event' ? 'View event' : 'Apply')); }
        a.appendChild(meta);
        return a;
      }
      function linkedin() {
        var kind = draft.linkedin_type || 'Image';
        var card = el('div', 'pvli'), top = el('div', 'pvwho'), who = el('div');
        top.appendChild(avatar(draft.brand_name));
        who.appendChild(el('strong', null, draft.brand_name || 'Your brand'));
        who.appendChild(el('span', null, 'now · 🌐'));
        top.appendChild(who);
        card.appendChild(top);
        var text = rich(withTags(draft.linkedin_copy, draft.hashtags));
        text.classList.add('pvclamp');
        card.appendChild(text);
        var more = el('button', 'pvmore', '…see more');
        more.type = 'button';
        more.onclick = function () { text.classList.remove('pvclamp'); more.remove(); };
        card.appendChild(more);
        if (kind === 'Image') { card.appendChild(picture(false)); }
        else if (kind === 'Multi-image') { card.appendChild(multi()); }
        else if (kind === 'Carousel' || kind === 'Document') { card.appendChild(documentView()); }
        else if (kind === 'Video') { card.appendChild(videoView()); }
        else if (kind === 'Poll') { card.appendChild(pollView()); }
        else if (kind !== 'Text') { card.appendChild(linkCard(kind)); }
        var bar = el('div', 'pvbar');
        ['👍 Like', '💬 Comment', '🔁 Repost', '➤ Send'].forEach(function (a) { bar.appendChild(el('span', null, a)); });
        card.appendChild(bar);
        requestAnimationFrame(function () {
          if (text.scrollHeight <= text.clientHeight + 2) { more.remove(); text.classList.remove('pvclamp'); }
        });
        return card;
      }
      function instagram() {
        var card = el('div', 'pvig'), top = el('div', 'pvwho'), who = el('div');
        var handle = String(draft.brand_name || '').toLowerCase().replace(/[^a-z0-9._]+/g, '') || 'yourbrand';
        top.appendChild(avatar(draft.brand_name));
        who.appendChild(el('strong', null, handle));
        top.appendChild(who);
        card.appendChild(top);
        card.appendChild(picture(true));
        var bar = el('div', 'pvbar ig');
        ['♡', '💬', '➤'].forEach(function (a) { bar.appendChild(el('span', null, a)); });
        card.appendChild(bar);
        var cap = el('div', 'pvcap');
        cap.appendChild(el('strong', null, handle + ' '));
        cap.appendChild(rich(igText()));
        card.appendChild(cap);
        return card;
      }

      /* What a kind needs before it can go, judged on the draft. */
      function readiness() {
        var k = draft.linkedin_type;
        var opts = String(draft.poll_options || '').split('|').filter(function (o) { return o.trim(); });
        if (!String(draft.linkedin_copy || '').trim()) { return 'The post has no text.'; }
        if (k === 'Video' && !files('video').length) { return 'Upload a video for a video post.'; }
        if (k === 'Document' && !files('pdf').length && !(draft.pages || []).length) { return 'Upload a PDF for a document post.'; }
        if (k === 'Carousel' && !files('pdf').length && String(draft.carousel_slides || '').split('\n').filter(function (l) { return l.trim(); }).length < 2) {
          return 'A carousel needs at least two slides.';
        }
        if (k === 'Poll' && (!String(draft.poll_question || '').trim() || opts.length < 2)) { return 'A poll needs a question and two options.'; }
        if (!draft.scheduled_date) { return 'Give it a date to go out.'; }
        return '';
      }

      function drawPreview() {
        turnBook = null;
        stage.innerHTML = '';
        modal.querySelectorAll('[data-pv]').forEach(function (b) {
          b.setAttribute('aria-selected', b.getAttribute('data-pv') === tab ? 'true' : 'false');
        });
        if (!draft) { return; }
        stage.appendChild(tab === 'instagram' ? instagram() : linkedin());
        var why = readiness(), r = $('pvReady');
        r.textContent = why ? '⚠ ' + why : '✓ Ready to post';
        r.className = 'pvready ' + (why ? 'warn' : 'ok');
      }

      /* -------------------------------------------------------- the editor */
      function count(id, len, max) {
        var c = $(id);
        c.textContent = len + ' / ' + max;
        c.className = 'pvcount' + (len > max ? ' over' : '');
      }
      function drawEditor() {
        var a = orig.approval || {}, edit = a.can_edit && !a.locked;
        $('pvType').value = draft.linkedin_type || 'Image';
        $('pvCopyText').value = draft.linkedin_copy || '';
        count('pvCopyCount', (draft.linkedin_copy || '').length, 3000);
        $('pvPollSec').hidden = draft.linkedin_type !== 'Poll';
        $('pvPollQ').value = draft.poll_question || '';
        count('pvPollCount', (draft.poll_question || '').length, 140);
        var opts = String(draft.poll_options || '').split('|').map(function (o) { return o.trim(); });
        modal.querySelectorAll('.pvopt').forEach(function (inp) { inp.value = opts[+inp.dataset.opt] || ''; });
        $('pvPollDur').value = draft.poll_duration || '1 week';
        $('pvSlideSec').hidden = draft.linkedin_type !== 'Carousel';
        $('pvSlides').value = draft.carousel_slides || '';
        $('pvDate').value = draft.scheduled_date || '';
        $('pvTime').value = draft.scheduled_time || '';
        modal.querySelectorAll('.pvright .pvin').forEach(function (n) { n.disabled = !edit; });
        $('pvUploadBtn').hidden = !edit;
        drawFiles(edit);
        drawApproval();
        drawFoot();
      }
      function drawFiles(edit) {
        var list = $('pvFileList');
        list.innerHTML = '';
        (draft.files || []).forEach(function (f, i) {
          var li = el('li');
          var a = el('a', null, (f.kind === 'image' ? '🖼 ' : f.kind === 'pdf' ? '📄 ' : '🎬 ') + (f.name || f.file));
          a.href = MEDIA + f.file; a.target = '_blank'; a.rel = 'noopener';
          li.appendChild(a);
          if (pending.indexOf(f.file) !== -1) { li.appendChild(el('span', 'pvnew', 'new')); }
          if (edit) {
            var x = el('button', 'pvfx', '×');
            x.type = 'button';
            x.title = 'Take this file off the post';
            x.onclick = function () { draft.files.splice(i, 1); changed(); };
            li.appendChild(x);
          }
          list.appendChild(li);
        });
        if (!(draft.files || []).length) { list.appendChild(el('li', 'pvnone', 'None — the generated graphic is used.')); }
      }
      function drawApproval() {
        var a = orig.approval || {};
        var pill = $('pvState'), d = $('pvDecision');
        var tone = a.state === 'approved' ? 'ok' : (a.state === 'rejected' ? 'bad' : 'wait');
        pill.textContent = orig.status === 'PUBLISHED' ? 'Published'
                         : (a.state === 'approved' ? (orig.status === 'SCHEDULED' ? 'Approved · scheduled' : 'Approved')
                         : (a.state === 'rejected' ? 'Rejected' : 'Waiting for approval'));
        pill.className = 'pvpill ' + (orig.status === 'PUBLISHED' ? 'ok' : tone);
        d.innerHTML = '';
        d.appendChild(el('span', 'pvdot ' + tone));
        d.appendChild(el('strong', null, a.label || 'Pending review'));
        if (a.by) { d.appendChild(el('span', null, ' · ' + a.by + (a.when ? ', ' + a.when : ''))); }
        if (a.remarks) { d.appendChild(el('div', 'pvremark', a.remarks)); }
        var locked = a.locked, can = a.can_decide && !locked;
        $('pvApproveBtn').hidden = !can || a.state === 'approved';
        $('pvRejectBtn').hidden = !can || a.state === 'rejected';
        $('pvAskBtn').hidden = !a.can_request || locked;
        $('pvDecideBtns').hidden = !can && !a.can_request;
        $('pvApproveBtn').textContent = draft.scheduled_date ? '✓ Approve & schedule' : '✓ Approve';
      }
      function drawFoot() {
        var d = dirty();
        $('pvDirty').hidden = !d;
        $('pvSave').disabled = !d;
        $('pvDiscard').disabled = !d;
        var why = d ? 'Save your changes first — what is approved must be what is stored.' : '';
        ['pvApproveBtn', 'pvRejectBtn', 'pvAskBtn'].forEach(function (id) {
          $(id).disabled = d;
          $(id).title = why;
        });
        $('pvDecideNote').textContent = d ? why : '';
      }
      function changed() {
        $('pvSaved').textContent = '';
        drawPreview();
        drawFiles((orig.approval || {}).can_edit);
        $('pvPollSec').hidden = draft.linkedin_type !== 'Poll';
        $('pvSlideSec').hidden = draft.linkedin_type !== 'Carousel';
        drawApproval();
        drawFoot();
      }

      /* Every editor field writes into the draft, and the preview follows. */
      function bind(id, field, after) {
        $(id).addEventListener('input', function () {
          draft[field] = this.value;
          if (after) { after(); }
          changed();
        });
      }
      bind('pvType', 'linkedin_type', function () { page = 0; });
      $('pvType').addEventListener('change', function () { draft.linkedin_type = this.value; page = 0; changed(); });
      bind('pvCopyText', 'linkedin_copy', function () { count('pvCopyCount', draft.linkedin_copy.length, 3000); });
      bind('pvPollQ', 'poll_question', function () { count('pvPollCount', draft.poll_question.length, 140); });
      bind('pvPollDur', 'poll_duration');
      $('pvPollDur').addEventListener('change', function () { draft.poll_duration = this.value; changed(); });
      bind('pvSlides', 'carousel_slides');
      bind('pvDate', 'scheduled_date');
      bind('pvTime', 'scheduled_time');
      modal.querySelectorAll('.pvopt').forEach(function (inp) {
        inp.addEventListener('input', function () {
          var vals = [];
          modal.querySelectorAll('.pvopt').forEach(function (o) { if (o.value.trim()) { vals.push(o.value.trim()); } });
          draft.poll_options = vals.join(' | ');
          changed();
        });
      });

      /* ---------------------------------------------------- talk to server */
      function post(url, fields, file) {
        var fd = new FormData();
        fd.append('csrf', CSRF);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        if (file) { fd.append('file', file); }
        return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json().catch(function () { throw new Error('The server did not answer properly (' + r.status + ').'); });
          })
          .then(function (res) {
            if (!res || !res.ok) { throw new Error((res && res.error) || 'That did not go through.'); }
            return res;
          });
      }
      function load(id) {
        current = id;
        return fetch(API + (API.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(id),
                     { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res || !res.ok) { throw new Error((res && res.error) || 'it could not be read'); }
            orig = res.post;
            draft = clone(orig);
            $('pvMeta').textContent = [orig.wc_id, orig.brand_name, orig.status].filter(Boolean).join(' · ');
            var chk = $('pvCheck'), clean = orig.hallucination_check === 'clean';
            chk.textContent = clean ? '✓ Claims match the website'
              : (orig.hallucination_check ? '⚠ Check: ' + (orig.generation_notes || orig.hallucination_check) : '');
            chk.className = 'pvcheck ' + (clean ? 'ok' : 'warn');
            chk.title = chk.textContent;
            drawPreview();
            drawEditor();
          });
      }
      /* The grid row underneath, brought up to date without a reload. */
      function refreshRow() {
        var tr = document.querySelector('tr[data-id="' + current + '"]');
        if (!tr || !orig) { return; }
        var vals = {
          linkedin_type: orig.linkedin_type, linkedin_copy: orig.linkedin_copy,
          poll_question: orig.poll_question, poll_options: orig.poll_options,
          poll_duration: orig.poll_duration, carousel_slides: orig.carousel_slides,
          scheduled_date: orig.scheduled_date, scheduled_time: orig.scheduled_time,
          status: orig.status, approval_state: (orig.approval || {}).label || '',
          post_media: (orig.files || []).length ? (orig.files || []).map(function (f) { return f.name; }).join(', ') : ''
        };
        Object.keys(vals).forEach(function (f) {
          var td = tr.querySelector('td[data-field="' + f + '"]');
          if (!td) { return; }
          var inp = td.querySelector('input, select, textarea');
          if (inp && inp.type !== 'checkbox') { inp.value = vals[f]; }
          else { var sp = td.querySelector('.cell'); if (sp) { sp.textContent = vals[f]; } }
        });
      }

      function save() {
        var ch = changes();
        if (!Object.keys(ch).length) { return; }
        ch.action = 'save';
        ch.id = current;
        $('pvSave').disabled = true;
        $('pvSave').textContent = 'Saving…';
        post(SAVE, ch).then(function (res) {
          pending = [];
          return load(current).then(function () {
            $('pvSaved').textContent = '✓ ' + res.message;
            $('pvSaved').className = 'pvsaved' + (res.reapproval ? ' warn' : '');
            refreshRow();
          });
        }).catch(function (e) {
          $('pvSaved').textContent = e.message;
          $('pvSaved').className = 'pvsaved bad';
        }).then(function () { $('pvSave').textContent = 'Save changes'; drawFoot(); });
      }
      function discard(silent) {
        if (pending.length) { post(SAVE, { action: 'discard', files: JSON.stringify(pending) }).catch(function () {}); }
        pending = [];
        if (orig) { draft = clone(orig); page = 0; }
        if (!silent) { drawPreview(); drawEditor(); $('pvSaved').textContent = 'Changes discarded.'; $('pvSaved').className = 'pvsaved'; }
      }
      function decide(state, remarks) {
        var btns = ['pvApproveBtn', 'pvRejectBtn', 'pvRejectSend'];
        btns.forEach(function (b) { $(b).disabled = true; });
        post(DECIDE + '&id=' + encodeURIComponent(current), { action: 'decide', id: current, state: state, remarks: remarks || '' })
          .then(function () {
            $('pvRejectBox').hidden = true;
            $('pvRejectWhy').value = '';
            return load(current).then(function () {
              $('pvSaved').textContent = state === 'approved'
                ? (orig.status === 'SCHEDULED' ? '✓ Approved — it goes out ' + orig.scheduled_date + (orig.scheduled_time ? ' at ' + orig.scheduled_time : '') + '.' : '✓ Approved.')
                : 'Rejected — it will not go out until it is approved.';
              $('pvSaved').className = 'pvsaved' + (state === 'approved' ? '' : ' warn');
              refreshRow();
            });
          })
          .catch(function (e) { $('pvSaved').textContent = e.message; $('pvSaved').className = 'pvsaved bad'; })
          .then(function () { btns.forEach(function (b) { $(b).disabled = false; }); drawFoot(); });
      }

      /* ------------------------------------------------------------ wiring */
      function open(id) {
        orig = draft = null; page = 0; tab = 'linkedin'; pending = [];
        modal.hidden = false;
        stage.innerHTML = '';
        stage.appendChild(el('div', 'pvwait', 'Loading the post…'));
        ['pvMeta', 'pvCheck', 'pvSaved', 'pvState', 'pvReady'].forEach(function (i) { $(i).textContent = ''; });
        $('pvRejectBox').hidden = true;
        load(id).catch(function (e) {
          stage.innerHTML = '';
          stage.appendChild(el('div', 'pvwait', 'Could not load this post: ' + e.message));
        });
      }
      function close() {
        if (dirty() && !confirm('You have unsaved changes. Close and lose them?')) { return; }
        discard(true);
        modal.hidden = true;
      }
      document.addEventListener('click', function (ev) {
        var b = ev.target.closest('.js-preview');
        if (b) { var tr = b.closest('tr[data-id]'); if (tr) { open(tr.getAttribute('data-id')); } return; }
        var t = ev.target.closest('#pvModal [data-pv]');
        if (t) { tab = t.getAttribute('data-pv'); drawPreview(); }
      });
      $('pvSave').addEventListener('click', save);
      $('pvDiscard').addEventListener('click', function () { discard(false); });
      $('pvApproveBtn').addEventListener('click', function () { decide('approved', ''); });
      $('pvRejectBtn').addEventListener('click', function () { $('pvRejectBox').hidden = false; $('pvRejectWhy').focus(); });
      $('pvRejectCancel').addEventListener('click', function () { $('pvRejectBox').hidden = true; });
      $('pvRejectSend').addEventListener('click', function () {
        var why = $('pvRejectWhy').value.trim();
        if (!why) { $('pvRejectWhy').focus(); $('pvRejectWhy').classList.add('pvneed'); return; }
        decide('rejected', why);
      });
      /* Asking somebody else is the portal's own approval drawer, opened on
         this row: who to send it to, a note, the emails. */
      $('pvAskBtn').addEventListener('click', function () {
        var tr = document.querySelector('tr[data-id="' + current + '"]');
        var rv = tr && tr.querySelector('.js-review');
        if (!rv) { alert('Open the approval button (✓) at the start of this row to send it.'); return; }
        modal.hidden = true;
        rv.click();
      });
      $('pvUpload').addEventListener('change', function () {
        var f = this.files && this.files[0];
        this.value = '';
        if (!f || !draft) { return; }
        $('pvUpNote').textContent = 'Uploading ' + f.name + '…';
        post(SAVE, { action: 'upload' }, f).then(function (res) {
          draft.files = draft.files || [];
          draft.files.push(res.file);
          pending.push(res.file.file);
          /* A file of a kind implies the post: a video makes a video post,
             a PDF a document -- unless it already is a kind that uses it. */
          var k = draft.linkedin_type, kind = res.file.kind;
          if (kind === 'video' && k !== 'Video') { draft.linkedin_type = 'Video'; }
          if (kind === 'pdf' && k !== 'Document' && k !== 'Carousel') { draft.linkedin_type = 'Document'; }
          $('pvUpNote').textContent = 'Added — press Save to keep it.';
          page = 0;
          $('pvType').value = draft.linkedin_type;
          changed();
        }).catch(function (e) { $('pvUpNote').textContent = e.message; });
      });
      $('pvClose').addEventListener('click', close);
      modal.addEventListener('click', function (ev) { if (ev.target === modal) { close(); } });
      document.addEventListener('keydown', function (ev) {
        if (modal.hidden) { return; }
        if (ev.key === 'Escape') { close(); }
        /* ← and → turn the document's pages -- unless the person is typing. */
        var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
        if (turnBook && !typing && (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft')
            && !ev.target.closest('.pvbook')) {
          ev.preventDefault();
          turnBook(ev.key === 'ArrowRight' ? 1 : -1);
        }
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') { ev.preventDefault(); if (dirty()) { save(); } }
      });
      window.addEventListener('beforeunload', function (ev) {
        if (!modal.hidden && dirty()) { ev.preventDefault(); ev.returnValue = ''; }
      });
      $('pvCopy').addEventListener('click', function () {
        if (!draft) { return; }
        var text = tab === 'instagram' ? igText() : withTags(draft.linkedin_copy, draft.hashtags), btn = this;
        function done() { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy text'; }, 1500); }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy the post:', text); });
        } else { window.prompt('Copy the post:', text); }
      });
    })();
    </script>
    <?php
}
