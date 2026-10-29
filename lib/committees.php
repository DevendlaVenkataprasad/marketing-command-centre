<?php
/**
 * Approval committees for events.
 *
 * A committee is a named group of people who decide on an event together.
 * Each organisation keeps its own -- ours at scope 0, a client's at the id of
 * their organisation (the same scoping as every list and sheet setting) --
 * and builds them on the Committees tab of Sheet Management.
 *
 * An event is given a committee from its Committee column. Every member then
 * approves or rejects it, a rejection always with a justification, and the
 * committee's rule turns the votes into one decision:
 *
 *   all       -- everyone must approve; any rejection rejects it.
 *   majority  -- more than half must approve; enough rejections that a
 *                majority can no longer be reached reject it.
 *   any       -- one approval is enough; it is rejected only when every
 *                member has rejected it.
 *   count     -- a set number of members (the committee's `required`) must
 *                approve; enough rejections that the number can no longer be
 *                reached reject it.
 *
 * That decision is written to the event's own approval (lib/approvals.php),
 * so the Approval column, the dashboard's approved and pending figures and
 * everything else that reads approvals agree with the committee.
 *
 * The event stores the committee's id (committee_id) as well as its name
 * (committee): the name is what the grid shows and the dropdown offers; the
 * id is what the votes hang on, so two organisations' "Finance" committees
 * are never confused, and a renamed committee keeps its events and votes.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lists.php';

/** The rules a committee can decide by, with how each reads on the page. */
function fabo_cm_rules(): array
{
    return [
        'all'      => 'Everyone must approve',
        'majority' => 'A majority must approve',
        'any'      => 'Any one member can approve',
        'count'    => 'A set number must approve',
    ];
}

/**
 * How many approvals decide it, for a committee of $n members. The set
 * number is held to 1..$n, so a committee that has lost members since it was
 * set can still be decided.
 */
function fabo_cm_needed(array $c, int $n): int
{
    switch ((string) ($c['rule'] ?? 'all')) {
        case 'any':      return min(1, $n);
        case 'majority': return intdiv($n, 2) + 1;
        case 'count':    return max(1, min($n, (int) ($c['required'] ?? 1)));
        default:         return $n;
    }
}

/** The rule as it reads for this committee: "At least 3 of 5 must approve". */
function fabo_cm_rule_label(array $c, ?int $n = null): string
{
    $rule = (string) ($c['rule'] ?? 'all');
    if ($rule !== 'count') {
        return fabo_cm_rules()[$rule] ?? $rule;
    }
    $need = $n === null ? max(1, (int) ($c['required'] ?? 1)) : fabo_cm_needed($c, $n);
    return 'At least ' . $need . ($n !== null ? ' of ' . $n : '') . ' must approve';
}

/** "the Finance committee", but "the Programme Approval Committee" -- never "Committee committee". */
function fabo_cm_title(string $name): string
{
    $name = trim($name);
    return preg_match('~(^|\s)committee$~i', $name) ? 'the ' . $name : 'the ' . $name . ' committee';
}

/** The organisation a viewer's committees belong to: 0 for us. */
function fabo_cm_scope(?array $viewer): int
{
    return fabo_customiser($viewer);
}

/** The person a viewer is, or 0 for the administrator (who is nobody's member). */
function fabo_cm_person(?array $viewer): int
{
    return !empty($viewer['is_admin']) ? 0 : (int) ($viewer['client']['id'] ?? 0);
}

/* ------------------------------------------------------------- people */

/**
 * Who may sit on a committee of this organisation.
 *
 * Only accounts created as committee members (the Committee member tick,
 * offered wherever people are made: Team Management and Clients for the
 * administrator, My Team and My Clients for a client) -- nobody else.
 *
 * For us, every ticked account the administrator created, team or client.
 * For a client, the ticked accounts of their organisation.
 * Only accounts that can still sign in: a committee of people who cannot act
 * would leave every event waiting for ever.
 */
function fabo_cm_candidates(int $scope): array
{
    $rows = $scope > 0
        ? fabo_all('SELECT * FROM clients WHERE (id = ? OR parent_id = ?) AND is_committee = 1
                      AND revoked_at IS NULL ORDER BY (id = ?) DESC, name', 'iii', [$scope, $scope, $scope])
        : fabo_all('SELECT * FROM clients WHERE COALESCE(parent_id, 0) = 0
                      AND is_committee = 1 AND revoked_at IS NULL ORDER BY name');
    return array_values(array_filter($rows, static fn($p) => fabo_client_state($p) === 'ok'));
}

/* --------------------------------------------------------- committees */

/** This organisation's committees, each with its members. */
function fabo_cm_list(int $scope): array
{
    $out = [];
    foreach (fabo_all('SELECT * FROM committees WHERE client_id = ? ORDER BY name', 'i', [$scope]) as $c) {
        $c['members'] = fabo_cm_members((int) $c['id']);
        $out[] = $c;
    }
    return $out;
}

/** One committee of this organisation, or null. */
function fabo_cm_get(int $id, ?int $scope = null): ?array
{
    $c = $scope === null
        ? fabo_one('SELECT * FROM committees WHERE id = ?', 'i', [$id])
        : fabo_one('SELECT * FROM committees WHERE id = ? AND client_id = ?', 'ii', [$id, $scope]);
    if ($c) {
        $c['members'] = fabo_cm_members((int) $c['id']);
    }
    return $c ?: null;
}

/** A committee's members, as people, in name order. */
function fabo_cm_members(int $committeeId): array
{
    return fabo_all('SELECT c.* FROM committee_members m JOIN clients c ON c.id = m.person_id
                      WHERE m.committee_id = ? ORDER BY c.name', 'i', [$committeeId]);
}

/** The committee names a viewer may assign: their organisation's own. */
function fabo_cm_names(?array $viewer): array
{
    try {
        return array_column(fabo_all('SELECT name FROM committees WHERE client_id = ? ORDER BY name',
                                     'i', [fabo_cm_scope($viewer)]), 'name');
    } catch (Throwable $e) {
        return [];                          // before the table exists
    }
}

/**
 * Create or change a committee. Returns its id.
 *
 * Members are checked against who this organisation may put on one -- an id
 * posted from a browser is a claim, not a fact. A renamed committee carries
 * its events with it.
 */
function fabo_cm_save(int $scope, ?int $id, string $name, string $rule, array $memberIds,
                      string $who, int $required = 0): int
{
    $name = trim(preg_replace('~\s+~u', ' ', $name) ?? $name);
    if ($name === '') {
        throw new InvalidArgumentException('Give the committee a name.');
    }
    if (mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Keep the name under 120 characters.');
    }
    if (!isset(fabo_cm_rules()[$rule])) {
        throw new InvalidArgumentException('Choose how the committee decides.');
    }
    $allowed = array_column(fabo_cm_candidates($scope), 'id');
    $members = array_values(array_unique(array_filter(array_map('intval', $memberIds),
                   static fn($m) => in_array($m, array_map('intval', $allowed), true))));
    if (!$members) {
        throw new InvalidArgumentException('Tick at least one member.');
    }
    if ($rule === 'count') {
        if ($required < 1) {
            throw new InvalidArgumentException('Say how many members must approve.');
        }
        if ($required > count($members)) {
            throw new InvalidArgumentException('You asked for ' . $required . ' approvals but the committee has only '
                                               . count($members) . ' member' . (count($members) === 1 ? '' : 's') . '.');
        }
    }
    $req   = $rule === 'count' ? $required : null;
    $clash =fabo_one('SELECT id FROM committees WHERE client_id = ? AND name = ? AND id <> ?',
                      'isi', [$scope, $name, (int) $id]);
    if ($clash) {
        throw new InvalidArgumentException('There is already a committee called "' . $name . '".');
    }

    if ($id) {
        $was = fabo_cm_get($id, $scope);
        if (!$was) {
            throw new InvalidArgumentException('That committee is not here any more.');
        }
        fabo_exec('UPDATE committees SET name = ?, rule = ?, required = ? WHERE id = ?', 'ssii',
                  [$name, $rule, $req, $id]);
        if ((string) $was['name'] !== $name) {
            fabo_exec('UPDATE `events` SET committee = ? WHERE committee_id = ?', 'si', [$name, $id]);
        }
    } else {
        $id = (int) fabo_exec('INSERT INTO committees (client_id, name, rule, required, created_by) VALUES (?,?,?,?,?)',
                              'issis', [$scope, $name, $rule, $req, mb_substr($who, 0, 160)]);
    }
    fabo_exec('DELETE FROM committee_members WHERE committee_id = ?', 'i', [$id]);
    foreach ($members as $m) {
        fabo_exec('INSERT INTO committee_members (committee_id, person_id) VALUES (?,?)', 'ii', [$id, $m]);
    }
    /* A member taken off no longer has a say: their vote goes, and every
       event of this committee is decided again on who is left. */
    fabo_exec('DELETE FROM committee_votes WHERE committee_id = ? AND person_id NOT IN ('
              . implode(',', array_fill(0, count($members), '?')) . ')',
              'i' . str_repeat('i', count($members)), array_merge([$id], $members));
    foreach (fabo_all('SELECT id FROM `events` WHERE committee_id = ?', 'i', [$id]) as $e) {
        fabo_cm_settle((int) $e['id'], $who);
    }
    return $id;
}

/** Remove a committee. Its events are left without one, and its votes go. */
function fabo_cm_delete(int $id, int $scope): void
{
    if (!fabo_cm_get($id, $scope)) {
        throw new InvalidArgumentException('That committee is not here any more.');
    }
    fabo_exec('UPDATE `events` SET committee = NULL, committee_id = NULL WHERE committee_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM committee_votes WHERE committee_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM committee_members WHERE committee_id = ?', 'i', [$id]);
    fabo_exec('DELETE FROM committees WHERE id = ?', 'i', [$id]);
}

/**
 * The toolbar control on the Events grid for giving many events one
 * committee: tick the rows (a checkbox on each, drawn by fabo_render_row()),
 * choose the committee, press Assign. Posts to api/cmassign.php, which
 * assigns them one by one exactly as the Committee cell would.
 * "Remind committee" posts the ticked rows to api/cmremind.php: each member
 * who still owes a vote gets one email listing all of theirs (fabo_cm_remind()).
 */
function fabo_cm_bulk_bar(array $names): string
{
    $opts = '<option value="">Choose committee…</option>';
    foreach ($names as $n) {
        $opts .= '<option value="' . e($n) . '">' . e(fabo_cm_title((string) $n)) . '</option>';
    }
    return '<span class="cmbulk" id="cmbulk"><span class="cmbulk-n" id="cmbulk-n">0 selected</span>'
         . '<select id="cmbulk-pick" aria-label="Committee for the selected programmes">' . $opts . '</select>'
         . '<button type="button" class="btn btn-sm btn-primary" id="cmbulk-go" disabled>Assign committee to selected</button>'
         . '<button type="button" class="btn btn-sm" id="cmbulk-remind" disabled title="Email each committee member who has not voted one reminder listing all the selected programmes still waiting on them">Remind committee</button></span>'
         . '<style>.cmbulk{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;padding:3px 8px;border:1px solid var(--line,#dfe3ea);border-radius:8px}'
         . '.cmbulk-n{font-size:12.5px;color:var(--ink-soft,#68707f);white-space:nowrap}.cmbulk select{max-width:220px}'
         . '.rowtools .js-cmpick,.corner .js-cmall{margin:0 4px 0 0;width:15px;height:15px;cursor:pointer;vertical-align:middle}'
         . 'tr.cm-picked > *{background:var(--accent-soft,#eaf1fd)!important}</style>'
         . '<script>document.addEventListener("DOMContentLoaded",function(){'
         . 'var cfg=window.FABO_GRID||{},body=document.getElementById("gridbody"),n=document.getElementById("cmbulk-n"),'
         . 'pick=document.getElementById("cmbulk-pick"),go=document.getElementById("cmbulk-go"),all=document.querySelector(".js-cmall"),rm=document.getElementById("cmbulk-remind");'
         . 'if(!body||!go){return;}'
         . 'function boxes(){return Array.prototype.slice.call(body.querySelectorAll(".js-cmpick"));}'
         . 'function upd(){var b=boxes(),on=b.filter(function(x){return x.checked;});'
         . 'b.forEach(function(x){var tr=x.closest("tr");if(tr){tr.classList.toggle("cm-picked",x.checked);}});'
         . 'n.textContent=on.length+" selected";go.disabled=!on.length||!pick.value;if(rm){rm.disabled=!on.length;}'
         . 'if(all){all.checked=b.length>0&&on.length===b.length;all.indeterminate=on.length>0&&on.length<b.length;}}'
         . 'body.addEventListener("change",function(ev){if(ev.target.classList.contains("js-cmpick")){upd();}});'
         . 'if(all){all.addEventListener("change",function(){boxes().forEach(function(x){x.checked=all.checked;});upd();});}'
         . 'pick.addEventListener("change",upd);'
         . 'go.addEventListener("click",function(){var ids=boxes().filter(function(x){return x.checked;}).map(function(x){return x.value;});'
         . 'if(!ids.length||!pick.value){return;}'
         . 'if(!confirm("Assign "+pick.options[pick.selectedIndex].text+" to "+ids.length+" programme"+(ids.length===1?"":"s")+"? Each one goes to the committee with its budget, and the members are told.")){return;}'
         . 'go.disabled=true;go.textContent="Assigning…";'
         . 'var p=new URLSearchParams();if(cfg.token){p.set("k",cfg.token);}if(cfg.admin){p.set("as","admin");}'
         . 'p.set("sheet","events");p.set("csrf",cfg.csrf);p.set("committee",pick.value);ids.forEach(function(i){p.append("ids[]",i);});'
         . 'fetch(cfg.api+"cmassign.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"fetch","Accept":"application/json"},body:p.toString()})'
         . '.then(function(r){return r.text().then(function(t){try{return JSON.parse(t);}catch(e){return{ok:false,error:"Server error ("+r.status+"). Nothing was changed."};}});})'
         . '.then(function(res){alert(res.ok?res.message:(res.error||"That did not work."));if(res.ok){window.location.reload();}else{go.textContent="Assign committee to selected";upd();}})'
         . '.catch(function(){alert("Network error — nothing was changed.");go.textContent="Assign committee to selected";upd();});});'
         . 'if(rm){rm.addEventListener("click",function(){var ids=boxes().filter(function(x){return x.checked;}).map(function(x){return x.value;});'
         . 'if(!ids.length){return;}'
         . 'var note=prompt("Remind the committee members about "+ids.length+" selected programme"+(ids.length===1?"":"s")+"? Each member who has not voted gets ONE email listing all of theirs that are still pending.\n\nOptional note to include (leave blank for none):","");if(note===null){return;}'
         . 'rm.disabled=true;rm.textContent="Sending…";'
         . 'var p=new URLSearchParams();if(cfg.token){p.set("k",cfg.token);}if(cfg.admin){p.set("as","admin");}'
         . 'p.set("sheet","events");p.set("csrf",cfg.csrf);p.set("note",note);ids.forEach(function(i){p.append("ids[]",i);});'
         . 'fetch(cfg.api+"cmremind.php",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"fetch","Accept":"application/json"},body:p.toString()})'
         . '.then(function(r){return r.text().then(function(t){try{return JSON.parse(t);}catch(e){return{ok:false,error:"Server error ("+r.status+"). No reminder was sent."};}});})'
         . '.then(function(res){alert(res.ok?res.message:(res.error||"That did not work."));rm.textContent="Remind committee";upd();})'
         . '.catch(function(){alert("Network error — no reminder was sent.");rm.textContent="Remind committee";upd();});});}'
         . 'upd();});</script>';
}

/* ------------------------------------------------------------- events */

/**
 * Give an event a committee, by the name the viewer picked.
 *
 * The name must be one of the viewer's own organisation's committees. The
 * members are told it is waiting for them. Returns [committee or null, note].
 * With $mail false the members get the notice but no email: the bulk assign
 * (api/cmassign.php) sends each one a single email for all its events instead
 * (fabo_cm_assign_many_mail()).
 */
function fabo_cm_assign(array $viewer, int $eventId, string $name, bool $mail = true): array
{
    $name = trim($name);
    if ($name === '') {
        fabo_exec('UPDATE `events` SET committee = NULL, committee_id = NULL WHERE id = ?', 'i', [$eventId]);
        return [null, 'Committee taken off.'];
    }
    $c = fabo_one('SELECT * FROM committees WHERE client_id = ? AND name = ?', 'is',
                  [fabo_cm_scope($viewer), $name]);
    if (!$c) {
        throw new InvalidArgumentException('"' . $name . '" is not one of your committees.');
    }
    $was = (int) (fabo_one('SELECT committee_id FROM `events` WHERE id = ?', 'i', [$eventId])['committee_id'] ?? 0);
    fabo_exec('UPDATE `events` SET committee = ?, committee_id = ? WHERE id = ?', 'sii',
              [(string) $c['name'], (int) $c['id'], $eventId]);
    if ($was === (int) $c['id']) {
        return [$c, ''];
    }
    require_once __DIR__ . '/approvals.php';

    /* A new committee decides afresh: the event and its whole budget go to
       it, and every member hears it is theirs to decide. */
    $who = !empty($viewer['is_admin']) ? 'Admin' : (string) ($viewer['client']['name'] ?? '');
    [$told, $mailed] = fabo_cm_open_round($c, $eventId, $who, false, $mail);
    if (!$mail) {
        return [$c, ''];
    }
    return [$c, 'Sent to ' . fabo_cm_title((string) $c['name']) . ' with its budget — ' . $told . ' member'
               . ($told === 1 ? '' : 's') . ' told, ' . $mailed . ' by email.'];
}

/* ------------------------------------------- the committee decides it all */

/*
 * THE FLOW ON EVENTS
 *
 *   1. The team creates the event and fills in Budget Required: an amount per
 *      head, with the quotations. Nothing goes to an owner.
 *   2. A Committee is chosen. The event and every amount on its budget go to
 *      it ("with the committee"), and each member is told and emailed.
 *   3. The members approve or reject, a rejection with remarks; the
 *      committee's rule turns the votes into one decision.
 *   4. That decision is the event's: its Approval, its Sign-off, every budget
 *      line (approved, or sent back with the remarks) and Budget Allocated.
 *      The event's owner is told.
 *   5. Sent back: the team corrects the budget and presses "Send to committee
 *      again", which starts a fresh round -- votes cleared, members told.
 *
 * Nobody else approves an event or its budget: fabo_may_decide() refuses
 * Events, and api/budget.php refuses an owner's decision on an event line.
 */

/**
 * Starts a review round: the committee's earlier votes on this event cleared,
 * the event and its budget waiting on it, every member told and emailed.
 * Returns [members told, emailed]. With $mail false nobody is emailed, and
 * with $notify false nobody gets a notice either: the caller tells them once
 * about many events instead.
 */
function fabo_cm_open_round(array $c, int $eventId, string $who, bool $again, bool $mail = true,
                            bool $notify = true): array
{
    require_once __DIR__ . '/approvals.php';
    fabo_exec('DELETE FROM committee_votes WHERE event_id = ? AND committee_id = ?', 'ii',
              [$eventId, (int) $c['id']]);
    fabo_set_approval('events', $eventId, 'pending',
                      ($again ? 'Sent to ' . fabo_cm_title((string) $c['name']) . ' again, for a fresh review.'
                              : 'Sent to ' . fabo_cm_title((string) $c['name']) . '.'), $who);
    fabo_cm_budget_send($eventId);
    fabo_cm_signoff($eventId, 'pending', 'With ' . fabo_cm_title((string) $c['name']) . ' for review.');

    $event = fabo_one('SELECT * FROM `events` WHERE id = ?', 'i', [$eventId]) ?: [];
    $title = trim((string) ($event['event_name'] ?? '')) ?: (string) ($event['event_id'] ?? 'An event');
    $told  = 0;
    $mailed = 0;
    if (!$notify) {
        return [0, 0];
    }
    try {
        require_once __DIR__ . '/notify.php';
        foreach (fabo_cm_members((int) $c['id']) as $m) {
            fabo_notify((int) $m['id'], ($again ? 'Sent again for committee review: ' : 'Committee approval needed: ') . $title,
                        $who . ($again ? ' corrected this event and sent it to ' : ' sent this event to ')
                        . fabo_cm_title((string) $c['name'])
                        . '. Check the event and its budget, then approve or reject it from your Committee Dashboard.',
                        fabo_row_link((int) $m['id'], 'events', $eventId));
            $told++;
            /* And by email, so they hear of it without signing in first. A
               message the mail server will not take yet waits in the outbox;
               it never undoes the assignment. */
            try {
                if ($mail && fabo_cm_assign_mail($m, $event, $c, $who, $again)) {
                    $mailed++;
                }
            } catch (Throwable $e) {
                error_log('FABO committee email: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('FABO committee notify: ' . $e->getMessage());
    }
    return [$told, $mailed];
}

/**
 * The team's "Send to committee again", after the committee sent the event
 * back or after the budget was changed. Returns [committee, note].
 */
function fabo_cm_send_again(array $viewer, int $eventId): array
{
    $ev = fabo_one('SELECT committee_id FROM `events` WHERE id = ?', 'i', [$eventId]);
    $c  = $ev && (int) $ev['committee_id'] > 0 ? fabo_cm_get((int) $ev['committee_id']) : null;
    if (!$c) {
        throw new InvalidArgumentException('Choose a Committee for this event first (the Committee column). '
                                         . 'The committee reviews the event and its budget.');
    }
    $s = fabo_cm_status($eventId);
    $changed = (int) (fabo_one('SELECT COUNT(*) n FROM budget_lines WHERE sheet_key = "events" AND row_id = ?
                                 AND required > 0 AND decision = "draft"', 'i', [$eventId])['n'] ?? 0);
    if ($s && $s['state'] === 'approved' && $changed === 0) {
        throw new InvalidArgumentException('The committee has already approved this event. '
                                         . 'Change an amount first if it needs another look.');
    }
    if ($s && $s['state'] === 'pending' && $changed === 0 && $s['yes'] + $s['no'] === 0) {
        throw new InvalidArgumentException('It is already with ' . fabo_cm_title((string) $c['name'])
                                         . ' and nothing has changed since.');
    }
    $who = !empty($viewer['is_admin']) ? 'Admin' : (string) ($viewer['client']['name'] ?? '');
    [$told, $mailed] = fabo_cm_open_round($c, $eventId, $who, true);
    return [$c, 'Sent to ' . fabo_cm_title((string) $c['name']) . ' again — ' . $told . ' member'
               . ($told === 1 ? '' : 's') . ' told, ' . $mailed . ' by email.'];
}

/**
 * The event's budget as the committee reviews it: each head with an amount,
 * its note, where it stands and how many quotations / invoices back it. The
 * heads' own allocations are not here -- they belong to Sheet Management.
 */
function fabo_cm_budget_lines(int $eventId): array
{
    try {
        $rows = fabo_all('SELECT b.*, (SELECT COUNT(*) FROM attachments a WHERE a.sheet_key = "budget" AND a.row_id = b.id) files
                            FROM budget_lines b WHERE b.sheet_key = "events" AND b.row_id = ? AND b.required > 0
                           ORDER BY b.id', 'i', [$eventId]);
    } catch (Throwable $e) {
        return [];
    }
    require_once __DIR__ . '/budget.php';
    require_once __DIR__ . '/ui.php';          // fabo_money()
    return array_map(static function ($b) {
        $state = (string) $b['decision'];
        $state = in_array($state, ['pending', 'approved', 'rejected'], true) ? $state : 'draft';
        return [
            'head'     => (string) $b['item_name'],
            'amount'   => (float) $b['required'],
            'money'    => fabo_money((float) $b['required']),
            'note'     => (string) ($b['note'] ?? ''),
            'state'    => $state,
            'word'     => ['pending' => 'With the committee', 'approved' => 'Approved', 'rejected' => 'Sent back',
                           'draft' => 'Changed, not sent yet'][$state],
            'remarks'  => (string) ($b['remarks'] ?? ''),
            'files'    => (int) $b['files'],
        ];
    }, $rows);
}

/** The total asked for on an event's budget, as money text ('' when nothing is asked). */
function fabo_cm_budget_total(int $eventId): string
{
    $sum = array_sum(array_column(fabo_cm_budget_lines($eventId), 'amount'));
    return $sum > 0 ? fabo_money($sum) : '';
}

/** Every amount on the event's budget not yet decided goes to the committee. Returns how many. */
function fabo_cm_budget_send(int $eventId): int
{
    try {
        $n = fabo_exec('UPDATE budget_lines SET decision = "pending", remarks = NULL, decided_by = NULL,
                               decided_at = NULL, approved_by = NULL
                         WHERE sheet_key = "events" AND row_id = ? AND required > 0
                           AND decision IN ("draft", "rejected", "pending", "none")', 'i', [$eventId]);
        require_once __DIR__ . '/budget.php';
        fabo_budget_sync_allocated('events', $eventId);
        return $n;
    } catch (Throwable $e) {
        return 0;                           // no budget_lines table yet
    }
}

/** The committee's decision, written onto every budget line it was looking at. */
function fabo_cm_budget_apply(int $eventId, string $state, string $remark, string $title): void
{
    try {
        require_once __DIR__ . '/budget.php';
        $by = in_array('Committee Approved', fabo_budget_approvers(), true) ? 'Committee Approved' : null;
        fabo_exec('UPDATE budget_lines SET decision = ?, remarks = ?, decided_by = ?, decided_at = NOW(),
                          approved_by = ?
                    WHERE sheet_key = "events" AND row_id = ? AND required > 0 AND decision = "pending"',
                  'ssssi', [$state, $remark !== '' ? mb_substr($remark, 0, 500) : null, mb_substr($title, 0, 160),
                            $state === 'approved' ? $by : null, $eventId]);
        fabo_budget_sync_allocated('events', $eventId);
    } catch (Throwable $e) {
        error_log('FABO committee budget: ' . $e->getMessage());
    }
}

/** Sign-off follows the committee, so the dashboards that read it agree with it. */
function fabo_cm_signoff(int $eventId, string $state, string $note): void
{
    $word = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'][$state] ?? '';
    if ($word === '') {
        return;
    }
    require_once __DIR__ . '/lists.php';
    $opts = fabo_list_values('Sign-off');
    if ($opts && !in_array($word, $opts, true)) {
        $word = null;                       // this organisation does not use that word: leave it
    }
    try {
        fabo_exec('UPDATE `events` SET approval_status = COALESCE(?, approval_status), approval_note = ? WHERE id = ?',
                  'ssi', [$word, mb_substr($note, 0, 250), $eventId]);
    } catch (Throwable $e) {
        error_log('FABO committee sign-off: ' . $e->getMessage());
    }
}

/**
 * Who hears the committee's decision by email, and what they are told.
 *
 *   Rejected                    -> the event's SPOC, with the committee's remarks.
 *   Approved, design support    -> the design requisition is raised as the
 *     asked for                    approval lands (lib/eventdesign.php) and the
 *                                  design team -- the requisition's owners, the
 *                                  addresses on Sheet Management > Requisition
 *                                  types -- get it through the queue's own mail.
 *                                  Nothing more is sent here.
 *   Approved, no design support -> the event's SPOC, with the link to the
 *                                  organisation's Design Requisition Form.
 *
 * The SPOC is the event's SPOC Email. An event without one falls back to the
 * accounts whose Owner / Team is the event's Owner, and failing those to the
 * organisation's main account, so a decision is never told to nobody. Those
 * owner accounts keep their portal notice either way.
 */
function fabo_cm_tell_owner(int $eventId, array $s, string $remark): void
{
    $e = fabo_one('SELECT * FROM `events` WHERE id = ?', 'i', [$eventId]);
    if (!$e) {
        return;
    }
    require_once __DIR__ . '/notify.php';
    require_once __DIR__ . '/mailtpl.php';
    require_once __DIR__ . '/outbox.php';
    require_once __DIR__ . '/brand.php';
    require_once __DIR__ . '/eventdesign.php';
    $title  = trim((string) ($e['event_name'] ?? '')) ?: (string) ($e['event_id'] ?? 'An event');
    $ok     = $s['state'] === 'approved';
    $design = $ok && fabo_evdesign_wanted($e);
    $head   = ($ok ? 'Approved by ' : 'Sent back by ') . $s['committee']['title'] . ': ' . $title;
    $org    = (int) ($e['client_id'] ?? 0);
    $form   = $ok && !$design ? fabo_cm_req_form_url($org) : '';
    $next   = $ok
        ? ($design
            ? 'Its design requisition has been raised and is with the design team.'
            : 'If the event needs posters, creatives or other publicity, raise a design requisition'
              . ($form !== '' ? ' using the Design Requisition Form below.' : ' with the design team.'))
        : 'Read the remarks, correct the event or its budget, then send it to the committee again.';

    // Portal notices for the owner team, as before. Only this organisation's people: names repeat.
    $owners = [];
    $owner  = trim((string) ($e['owner'] ?? ''));
    if ($owner !== '') {
        $mine = $org > 0 ? '(id = ' . $org . ' OR parent_id = ' . $org . ')' : "(kind = 'team' AND parent_id IS NULL)";
        $owners = fabo_all('SELECT * FROM clients WHERE owner_name = ? AND revoked_at IS NULL AND ' . $mine, 's', [$owner]);
        foreach ($owners as $p) {
            try {
                fabo_notify((int) $p['id'], $head, ($remark !== '' ? $remark . ' ' : '') . $next,
                            fabo_row_link((int) $p['id'], 'events', $eventId));
            } catch (Throwable $ex) {
                error_log('FABO committee owner notice: ' . $ex->getMessage());
            }
        }
    }
    if ($design) {
        return;                     // the design team's requisition mail is the one that goes
    }

    // Who gets the email: the SPOC, else the owner team, else the organisation's main account.
    $to   = [];
    $spoc = trim((string) ($e['spoc_email'] ?? ''));
    if ($spoc !== '' && filter_var($spoc, FILTER_VALIDATE_EMAIL)) {
        $to[strtolower($spoc)] = [trim((string) ($e['spoc_name'] ?? '')) ?: 'there', $spoc, null];
    }
    if (!$to) {
        $people = $owners ?: ($org > 0 ? fabo_all('SELECT * FROM clients WHERE id = ? AND revoked_at IS NULL', 'i', [$org]) : []);
        foreach ($people as $p) {
            $m = trim((string) ($p['email'] ?? ''));
            if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL)) {
                $to[strtolower($m)] = [(string) $p['name'], $m, (int) $p['id']];
            }
        }
    }
    if (!$to) {
        return;
    }

    $date  = static fn($d) => $d ? date('d M Y', strtotime((string) $d)) : '';
    $when  = $date($e['start_date'] ?? null);
    if (!empty($e['end_date']) && $e['end_date'] !== ($e['start_date'] ?? '')) {
        $when .= ($when !== '' ? ' to ' : '') . $date($e['end_date']);
    }
    $facts = array_filter([
        'Event'     => $title,
        'Event ID'  => (string) ($e['event_id'] ?? ''),
        'Dates'     => $when,
        'Committee' => $s['committee']['title'],
        'Decision'  => $ok ? 'Approved' : 'Rejected (sent back)',
        'Votes'     => $s['yes'] . ' of ' . $s['total'] . ' approved' . ($s['no'] ? ', ' . $s['no'] . ' rejected' : ''),
    ], static fn($v) => trim((string) $v) !== '');
    $brand = fabo_org_brand($org)[1];

    foreach ($to as [$name, $addr, $pid]) {
        try {
            $text = 'Hi ' . $name . ",\n\n" . $head . ".\n\n";
            foreach ($facts as $k => $v) {
                $text .= str_pad($k, 10) . ' ' . $v . "\n";
            }
            $text .= ($remark !== '' ? "\nREMARKS\n  " . $remark . "\n" : '')
                   . "\nWHAT NEXT\n  " . $next . "\n"
                   . ($form !== '' ? "\nDESIGN REQUISITION FORM\n  " . $form . "\n" : '')
                   . fabo_sign_off($brand);
            $rows = [];
            foreach ($facts as $k => $v) {
                $rows[$k] = $k === 'Event' || $k === 'Decision' ? '<strong>' . e($v) . '</strong>' : e($v);
            }
            $html = fabo_mail_wrap($brand, $ok ? 'Your event has been approved' : 'Your event was sent back',
                fabo_mail_p('Hi ' . e($name) . ',')
              . fabo_mail_p('<strong>' . e($title) . '</strong> was ' . ($ok ? 'approved' : 'sent back') . ' by <strong>'
                          . e($s['committee']['title']) . '</strong>.')
              . fabo_mail_rows($rows)
              . ($remark !== '' ? fabo_mail_p('<strong>' . ($ok ? 'Remarks' : 'Why it was sent back') . '</strong>') . fabo_mail_quote($remark) : '')
              . fabo_mail_p(e($next))
              . ($form !== '' ? fabo_mail_own_link($form, 'Open the Design Requisition Form',
                                                   'Fill it in for the creatives this event needs.') : ''),
                $title);
            fabo_post_or_queue($addr, $head, $text, $html, $pid, 'cmdecision ' . $eventId);
        } catch (Throwable $ex) {
            error_log('FABO committee decision mail: ' . $ex->getMessage());
        }
    }
}

/** The organisation's live Design Requisition Form link, or '' when it has none. */
function fabo_cm_req_form_url(int $org): string
{
    try {
        $f = fabo_one("SELECT token FROM form_links WHERE sheet_key = 'requisitions' AND client_id = ? AND active = 1
                         AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1", 'i', [$org]);
    } catch (Throwable $e) {
        return '';
    }
    if (!$f) {
        return '';
    }
    require_once __DIR__ . '/google.php';
    $base = PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])
        ? fabo_setting('portal_base_url') : rtrim(fabo_portal_url(), '/') . '/';
    return $base !== '' ? rtrim($base, '/') . '/form.php?k=' . $f['token'] : '';
}

/**
 * Emails one committee member that an event has been assigned to their
 * committee, asking them to sign in and decide. Returns true when it went
 * (or is queued to go); false when they have no email address.
 */
function fabo_cm_assign_mail(array $member, array $event, array $c, string $who, bool $again = false): bool
{
    $to = trim((string) ($member['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    require_once __DIR__ . '/mailtpl.php';
    require_once __DIR__ . '/outbox.php';

    $title = trim((string) ($event['event_name'] ?? '')) ?: (string) ($event['event_id'] ?? 'An event');
    $cmTitle = fabo_cm_title((string) $c['name']);
    $date = static fn($d) => $d ? date('d M Y', strtotime((string) $d)) : '';
    $when = $date($event['start_date'] ?? null);
    if (!empty($event['end_date']) && ($event['end_date'] ?? '') !== ($event['start_date'] ?? '')) {
        $when .= ($when !== '' ? ' to ' : '') . $date($event['end_date']);
    }
    $where = implode(', ', array_filter([(string) ($event['location'] ?? ''), (string) ($event['city'] ?? '')]));
    $facts = array_filter([
        'Event'      => $title,
        'Event ID'   => (string) ($event['event_id'] ?? ''),
        'Type'       => (string) ($event['event_type'] ?? ''),
        'Dates'      => $when,
        'Where'      => $where,
        'Hub / Spoke'=> (string) ($event['hub_spoke'] ?? ''),
        'Budget asked'=> fabo_cm_budget_total((int) ($event['id'] ?? 0)),
        'Committee'  => $cmTitle,
        'Decided by' => fabo_cm_rule_label($c),
        ($again ? 'Sent again by' : 'Assigned by') => $who,
    ], static fn($v) => trim((string) $v) !== '');
    $loginId = trim((string) ($member['login_id'] ?? ''));
    $signIn  = fabo_mail_home() . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');
    $brand   = fabo_app_name();

    $lead = $again
        ? 'The event "' . $title . '" has been corrected and sent to ' . $cmTitle . ' again for a fresh review.'
        : 'You have been assigned the event "' . $title . '" for review as a member of ' . $cmTitle . '.';
    $subject = ($again ? 'Sent again for committee review: ' : 'You have been assigned an event for committee review: ') . $title;
    $text = 'Hi ' . $member['name'] . ",\n\n"
          . $lead . "\n"
          . "Please sign in and check the event and its budget (amounts and quotations) on your Committee Dashboard, then approve or reject it.\n\n";
    foreach ($facts as $k => $v) {
        $text .= str_pad($k, 12) . ' ' . $v . "\n";
    }
    $text .= "\nSIGN IN\n  " . $signIn . "\n"
           . ($loginId !== '' ? '  Your user ID is ' . $loginId . ".\n" : '')
           . "\nA rejection needs a short remark saying why."
           . fabo_sign_off($brand);

    $rows = [];
    foreach ($facts as $k => $v) {
        $rows[$k] = $k === 'Event' ? '<strong>' . e($v) . '</strong>' : e($v);
    }
    $inner = fabo_mail_p('Hi ' . e((string) $member['name']) . ',')
           . fabo_mail_p(e($lead) . ' Please sign in and check the event and its <strong>budget</strong> (amounts and quotations) '
                       . 'on your Committee Dashboard, then approve or reject it.')
           . fabo_mail_rows($rows)
           . fabo_mail_button('Sign in and review the event')
           . ($loginId !== '' ? fabo_mail_note('Your user ID is <strong>' . e($loginId) . '</strong>.') : '')
           . fabo_mail_note('A rejection needs a short remark saying why; it goes back to whoever sent the event.');
    $html = fabo_mail_wrap($brand, $again ? 'An event is back with your committee' : 'An event has been assigned to your committee', $inner, $title);

    // One kind per event: the outbox keeps only the newest waiting message of a kind per person.
    [$sent, , $queued] = fabo_post_or_queue($to, $subject, $text, $html, (int) $member['id'],
                                            'committee ' . (int) ($event['id'] ?? 0));
    return $sent || $queued;
}

/**
 * The bulk "Assign committee to selected": every member of $c gets ONE email
 * listing all the events just sent to it, rather than one per event. A single
 * event gets the usual fabo_cm_assign_mail(). Returns members emailed (or queued).
 */
function fabo_cm_assign_many_mail(array $c, array $eventIds, string $who): int
{
    $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
    if (!$eventIds) {
        return 0;
    }
    $events = fabo_all('SELECT * FROM `events` WHERE id IN (' . implode(',', array_fill(0, count($eventIds), '?')) . ')',
                       str_repeat('i', count($eventIds)), $eventIds);
    if (!$events) {
        return 0;
    }
    require_once __DIR__ . '/mailtpl.php';
    require_once __DIR__ . '/outbox.php';

    $n       = count($events);
    $cmTitle = fabo_cm_title((string) $c['name']);
    $date    = static fn($d) => $d ? date('d M Y', strtotime((string) $d)) : '';
    $lines   = [];
    $list    = '';
    foreach ($events as $i => $ev) {
        $title = trim((string) ($ev['event_name'] ?? '')) ?: (string) ($ev['event_id'] ?? 'An event');
        $bits  = array_filter([
            (string) ($ev['event_id'] ?? ''),
            (string) ($ev['event_type'] ?? ''),
            $date($ev['start_date'] ?? null),
            ($b = fabo_cm_budget_total((int) $ev['id'])) !== '' ? 'Budget ' . $b : '',
        ], static fn($v) => trim((string) $v) !== '');
        $lines[] = '<strong>' . e($title) . '</strong>'
                 . ($bits ? '<br><span style="color:' . fabo_mail_soft() . ';font-size:13px;">' . e(implode(' · ', $bits)) . '</span>' : '');
        $list   .= '  ' . ($i + 1) . '. ' . $title . ($bits ? ' (' . implode(', ', $bits) . ')' : '') . "\n";
    }
    $brand   = fabo_app_name();
    $lead    = 'You have been assigned ' . $n . ' events for review as a member of ' . $cmTitle . '.';
    $subject = 'You have been assigned ' . $n . ' events for committee review';
    $kind    = 'committee batch ' . substr(sha1(implode(',', array_column($events, 'id'))), 0, 12);

    $mailed = 0;
    foreach (fabo_cm_members((int) $c['id']) as $m) {
        try {
            if ($n === 1) {
                if (fabo_cm_assign_mail($m, $events[0], $c, $who)) {
                    $mailed++;
                }
                continue;
            }
            $to = trim((string) ($m['email'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $loginId = trim((string) ($m['login_id'] ?? ''));
            $signIn  = fabo_mail_home() . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');
            $text = 'Hi ' . $m['name'] . ",\n\n" . $lead . "\n"
                  . "Please sign in and check each event and its budget (amounts and quotations) on your Committee Dashboard, then approve or reject it.\n\n"
                  . "ASSIGNED TO YOU\n" . $list
                  . "\nCommittee    " . $cmTitle . "\nDecided by   " . fabo_cm_rule_label($c) . "\nAssigned by  " . $who . "\n"
                  . "\nSIGN IN\n  " . $signIn . "\n"
                  . ($loginId !== '' ? '  Your user ID is ' . $loginId . ".\n" : '')
                  . "\nA rejection needs a short remark saying why."
                  . fabo_sign_off($brand);
            $inner = fabo_mail_p('Hi ' . e((string) $m['name']) . ',')
                   . fabo_mail_p(e($lead) . ' Please sign in and check each event and its <strong>budget</strong> (amounts and quotations) '
                               . 'on your Committee Dashboard, then approve or reject it.')
                   . fabo_mail_list($lines)
                   . fabo_mail_rows(array_filter(['Committee' => e($cmTitle), 'Decided by' => e(fabo_cm_rule_label($c)),
                                                  'Assigned by' => e($who)], static fn($v) => $v !== ''))
                   . fabo_mail_button('Sign in and review the events')
                   . ($loginId !== '' ? fabo_mail_note('Your user ID is <strong>' . e($loginId) . '</strong>.') : '')
                   . fabo_mail_note('A rejection needs a short remark saying why; it goes back to whoever sent the event.');
            $html = fabo_mail_wrap($brand, $n . ' events have been assigned to your committee', $inner, $subject);
            [$sent, , $queued] = fabo_post_or_queue($to, $subject, $text, $html, (int) $m['id'], $kind);
            if ($sent || $queued) {
                $mailed++;
            }
        } catch (Throwable $e) {
            error_log('FABO committee bulk email: ' . $e->getMessage());
        }
    }
    return $mailed;
}

/* ------------------------------------- automatic: upcoming events go on their own */

/*
 * An organisation can name one committee on its Committees tab that every
 * upcoming event goes to without anybody choosing it: an event with a name
 * and a start date of today or later, and no committee yet, is sent to it
 * (fabo_cm_open_round(), just as choosing it in the Committee cell would) and
 * shows on the members' Committee Dashboard to approve or reject.
 *
 * Left alone: events already approved or rejected, and events whose
 * Committee somebody deliberately cleared (the audit trail says so). Choosing
 * another committee in the cell still works and overrides it.
 *
 * Each run gathers everything newly sent, and every member hears ONCE -- one
 * notice and one email listing all of them -- however many there were.
 */

/** The committee an organisation's upcoming events go to on their own, or null when that is off. */
function fabo_cm_auto_committee(int $scope): ?array
{
    require_once __DIR__ . '/google.php';
    $id = (int) fabo_setting('cm_auto_assign_' . $scope, '0');
    return $id > 0 ? fabo_cm_get($id, $scope) : null;
}

/** Sets (or, with 0, turns off) the automatic committee; the next page load sends what is waiting. */
function fabo_cm_auto_save(int $scope, int $committeeId): ?array
{
    require_once __DIR__ . '/google.php';
    $c = $committeeId > 0 ? fabo_cm_get($committeeId, $scope) : null;
    if ($committeeId > 0 && !$c) {
        throw new InvalidArgumentException('That committee is not here any more.');
    }
    fabo_setting_put('cm_auto_assign_' . $scope, $c ? (string) (int) $c['id'] : null);
    fabo_setting_put('cm_autoassign_at', null);
    return $c;
}

/** What the last automatic run for this organisation did, for the Committees tab. */
function fabo_cm_auto_last_run(int $scope): string
{
    require_once __DIR__ . '/google.php';
    return fabo_setting('cm_autoassign_log_' . $scope, '');
}

/**
 * Sends every organisation's waiting upcoming events to its automatic
 * committee. Run from cron.php and, after the page has gone out, from
 * fabo_head() -- at most once a minute unless $force. Each event
 * is claimed with a conditional update, so overlapping runs never send one
 * twice. Returns events sent.
 */
function fabo_cm_autoassign_sweep(bool $force = false): int
{
    require_once __DIR__ . '/google.php';
    try {
        if (!$force && (int) fabo_setting('cm_autoassign_at', '0') > time() - 60) {
            return 0;
        }
        fabo_setting_put('cm_autoassign_at', (string) time());
        $scopes = array_map('intval', array_column(
            fabo_all("SELECT DISTINCT c.client_id FROM committees c
                        JOIN app_settings s ON s.k = CONCAT('cm_auto_assign_', c.client_id) AND s.v = c.id"),
            'client_id'));
    } catch (Throwable $e) {
        return 0;
    }
    $sent = 0;
    foreach ($scopes as $scope) {
        try {
            $sent += fabo_cm_autoassign_scope($scope);
        } catch (Throwable $e) {
            error_log('FABO committee auto-assign (org ' . $scope . '): ' . $e->getMessage());
        }
    }
    return $sent;
}

/**
 * The sweep after this page has been sent, so nobody waits on it (the first
 * run can have hundreds of events to send). Without a way to finish the
 * response first it is left to cron.php.
 */
function fabo_cm_autoassign_later(): void
{
    if (PHP_SAPI === 'cli' || !function_exists('litespeed_finish_request') && !function_exists('fastcgi_finish_request')) {
        return;
    }
    try {
        require_once __DIR__ . '/google.php';
        if ((int) fabo_setting('cm_autoassign_at', '0') > time() - 60) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    static $queued = false;
    if ($queued) {
        return;
    }
    $queued = true;
    register_shutdown_function(static function (): void {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        function_exists('litespeed_finish_request') ? litespeed_finish_request() : fastcgi_finish_request();
        ignore_user_abort(true);
        set_time_limit(300);
        try {
            fabo_cm_autoassign_sweep();
        } catch (Throwable $e) {
            error_log('FABO committee auto-assign later: ' . $e->getMessage());
        }
    });
}

/**
 * "Upcoming" exactly as Event Management's Upcoming Events tab counts it, as
 * SQL on alias e: its last day (End Date, else Start Date) still to come, or
 * no date at all yet (still being planned) -- an event on today is already
 * Completed there (fabo_evstage_is_done()) -- and not a draft still being
 * filled in on a department's shared sheet, which no grid shows until it is
 * submitted (fabo_rows()).
 * Returns [sql, types, params].
 */
function fabo_cm_upcoming_sql(): array
{
    require_once __DIR__ . '/compute.php';
    require_once __DIR__ . '/departments.php';
    $sql = '(COALESCE(e.end_date, e.start_date) > ? OR COALESCE(e.end_date, e.start_date) IS NULL)';
    if (fabo_dept_has_submitted_column()) {
        $sql .= ' AND (e.department_id IS NULL OR e.dept_submitted_at IS NOT NULL)';
    }
    return [$sql, 's', [fabo_today()]];
}

/**
 * An event the committee deals with has both its dates: the automatic run
 * sends nothing else, and the dashboard and reminders show nothing else.
 * SQL on alias e.
 */
function fabo_cm_dated_sql(): string
{
    return 'e.start_date IS NOT NULL AND e.end_date IS NOT NULL';
}

/**
 * The organisation's upcoming events (as Upcoming Events counts them) that
 * are NOT with its automatic committee, each with why, for the Committees
 * tab -- so a gap between the two counts is never a mystery. Returns rows of
 * [id, event_id, event_name, start_date, why].
 */
function fabo_cm_auto_skipped(int $scope, array $c): array
{
    [$upSql, $upTypes, $upArgs] = fabo_cm_upcoming_sql();
    $rows = fabo_all("SELECT e.id, e.event_id, e.event_name, e.start_date, e.end_date, e.committee_id, e.committee
                        FROM `events` e
                       WHERE " . ($scope > 0 ? 'e.client_id = ?' : 'e.client_id IS NULL') . "
                         AND (e.committee_id IS NULL OR e.committee_id <> ?)
                         AND " . $upSql . "
                       ORDER BY e.start_date IS NULL, e.start_date, e.id",
                     ($scope > 0 ? 'i' : '') . 'i' . $upTypes,
                     array_merge($scope > 0 ? [$scope] : [], [(int) $c['id']], $upArgs));
    $out = [];
    foreach ($rows as $r) {
        if ((int) $r['committee_id'] > 0) {
            $why = 'With another committee: ' . (string) $r['committee'];
        } elseif (empty($r['start_date']) || empty($r['end_date'])) {
            $why = 'Needs both a Start Date and an End Date — sent as soon as both are filled';
        } else {
            $why = 'Not sent yet — goes on the next automatic run (within a minute)';
        }
        $out[] = ['id' => (int) $r['id'], 'event_id' => (string) $r['event_id'], 'event_name' => (string) $r['event_name'],
                  'start_date' => (string) ($r['start_date'] ?? ''), 'why' => $why];
    }
    return $out;
}

/**
 * One-off repair, once per organisation: the first automatic runs (26 Oct
 * 2026) also sent department drafts and events on or before that day, which
 * Upcoming Events does not count. Each such event nobody has voted on yet is
 * taken back as if never sent -- committee cleared, its earlier approval (or
 * none) restored, its budget lines back to not submitted, the "Committee
 * approval needed" notices corrected to the events that remain.
 */
function fabo_cm_autoassign_fix1(int $scope, array $c): void
{
    require_once __DIR__ . '/google.php';
    $flag = 'cm_autoassign_fix1_' . $scope;
    if (fabo_setting($flag) !== '') {
        return;
    }
    fabo_setting_put($flag, date('Y-m-d H:i:s'));
    $auto = 'Automatically (upcoming event)';
    [$upSql, $upTypes, $upArgs] = fabo_cm_upcoming_sql();
    $rows = fabo_all("SELECT e.id FROM `events` e
                       WHERE e.committee_id = ?
                         AND EXISTS (SELECT 1 FROM activity_audit x WHERE x.table_name = 'events' AND x.row_id = e.id
                                        AND x.field = 'committee' AND x.who = 'Automatic (upcoming event)')
                         AND NOT EXISTS (SELECT 1 FROM activity_audit y WHERE y.table_name = 'events' AND y.row_id = e.id
                                            AND y.field = 'committee' AND y.who <> 'Automatic (upcoming event)'
                                            AND y.id > (SELECT MAX(z.id) FROM activity_audit z WHERE z.table_name = 'events'
                                                         AND z.row_id = e.id AND z.field = 'committee'
                                                         AND z.who = 'Automatic (upcoming event)'))
                         AND NOT EXISTS (SELECT 1 FROM committee_votes v WHERE v.event_id = e.id)
                         AND NOT (" . $upSql . ")", 'i' . $upTypes, array_merge([(int) $c['id']], $upArgs));
    $released = 0;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        if (fabo_exec('UPDATE `events` SET committee = NULL, committee_id = NULL WHERE id = ? AND committee_id = ?',
                      'ii', [$id, (int) $c['id']]) !== 1) {
            continue;
        }
        fabo_exec("DELETE FROM approval_log WHERE sheet_key = 'events' AND row_id = ? AND decided_by = ?", 'is', [$id, $auto]);
        $prev = fabo_one("SELECT state, remarks, decided_by, created_at FROM approval_log
                           WHERE sheet_key = 'events' AND row_id = ? ORDER BY id DESC LIMIT 1", 'i', [$id]);
        if ($prev) {
            fabo_exec("UPDATE approvals SET state = ?, remarks = ?, decided_by = ?, decided_at = ?
                        WHERE sheet_key = 'events' AND row_id = ?", 'ssssi',
                      [$prev['state'], $prev['remarks'], $prev['decided_by'], $prev['created_at'], $id]);
        } else {
            fabo_exec("DELETE FROM approvals WHERE sheet_key = 'events' AND row_id = ?", 'i', [$id]);
            try {
                fabo_exec('UPDATE budget_lines SET decision = "draft" WHERE sheet_key = "events" AND row_id = ? AND decision = "pending"',
                          'i', [$id]);
                require_once __DIR__ . '/budget.php';
                fabo_budget_sync_allocated('events', $id);
            } catch (Throwable $e) {
                // no budget_lines table
            }
            fabo_exec("UPDATE `events` SET approval_status = NULL, approval_note = NULL
                        WHERE id = ? AND approval_note LIKE 'With % for review.'", 'i', [$id]);
        }
        fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
                   VALUES (?,?,?,?,?,?,?)', 'sisisss',
                  ['Automatic (upcoming event)', null, 'events', $id, '(committee auto-release)', (string) $c['name'],
                   'not upcoming on Upcoming Events (draft, or on/before ' . fabo_today() . ')']);
        $released++;
    }
    if (!$released) {
        return;
    }
    /* The notices said how many were sent; say how many remain. */
    $left = (int) (fabo_one("SELECT COUNT(*) n FROM `events` e WHERE e.committee_id = ?
                              AND EXISTS (SELECT 1 FROM activity_audit x WHERE x.table_name = 'events' AND x.row_id = e.id
                                             AND x.field = 'committee' AND x.who = 'Automatic (upcoming event)')
                              AND NOT EXISTS (SELECT 1 FROM committee_votes v WHERE v.event_id = e.id)",
                            'i', [(int) $c['id']])['n'] ?? 0);
    $pids = array_map('intval', array_column(fabo_cm_members((int) $c['id']), 'id'));
    if ($pids) {
        $in = implode(',', $pids);
        if ($left === 0) {
            fabo_exec("DELETE FROM notifications WHERE client_id IN ($in) AND title LIKE 'Committee approval needed: % upcoming event%'");
        } else {
            fabo_exec("UPDATE notifications SET title = ?, body = ?, link = '' WHERE client_id IN ($in)
                        AND title LIKE 'Committee approval needed: % upcoming event%'", 'ss',
                      ['Committee approval needed: ' . $left . ' upcoming event' . ($left === 1 ? '' : 's'),
                       $left . ' upcoming event' . ($left === 1 ? ' has' : 's have') . ' been sent to '
                       . fabo_cm_title((string) $c['name'])
                       . '. Check each one and its budget, then approve or reject it from your Committee Dashboard.']);
        }
    }
    fabo_setting_put('cm_autoassign_log_' . $scope, date('d M Y, H:i') . ' — corrected: ' . $released
                     . ' event' . ($released === 1 ? '' : 's') . ' that Upcoming Events does not count (department drafts, '
                     . 'or on/before today) taken back; ' . $left . ' remain with ' . fabo_cm_title((string) $c['name']) . '.');
}

/** One organisation's run (see fabo_cm_autoassign_sweep()). Returns events sent. */
function fabo_cm_autoassign_scope(int $scope): int
{
    $c = fabo_cm_auto_committee($scope);
    if (!$c) {
        return 0;
    }
    if (!fabo_cm_members((int) $c['id'])) {
        fabo_setting_put('cm_autoassign_log_' . $scope, date('d M Y, H:i') . ' — not sent: '
                         . fabo_cm_title((string) $c['name']) . ' has no members.');
        return 0;
    }
    /* From cron there is no web request: borrow the remembered portal
       address, so links in the mail point at the portal. */
    if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
        $base = parse_url(fabo_setting('portal_base_url'));
        if (empty($base['host'])) {
            return 0;
        }
        $_SERVER['HTTP_HOST']   = $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $_SERVER['HTTPS']       = ($base['scheme'] ?? 'https') === 'https' ? 'on' : 'off';
        $_SERVER['SCRIPT_NAME'] = rtrim($base['path'] ?? '/', '/') . '/api/cron.php';
    }

    fabo_cm_autoassign_fix1($scope, $c);

    [$upSql, $upTypes, $upArgs] = fabo_cm_upcoming_sql();
    /* Every upcoming event with both its dates, whatever else: one already
       approved or rejected (before committees decided events) goes with that
       decision kept, so it shows on the dashboard as decided; any other goes
       for the committee to decide. */
    $rows = fabo_all("SELECT e.id, a.state AS appr FROM `events` e
                        LEFT JOIN approvals a ON a.sheet_key = 'events' AND a.row_id = e.id
                       WHERE " . ($scope > 0 ? 'e.client_id = ?' : 'e.client_id IS NULL') . "
                         AND e.committee_id IS NULL
                         AND " . $upSql . "
                         AND " . fabo_cm_dated_sql() . "
                       ORDER BY e.start_date, e.id",
                     ($scope > 0 ? 'i' : '') . $upTypes, array_merge($scope > 0 ? [$scope] : [], $upArgs));
    if (!$rows) {
        return 0;
    }
    require_once __DIR__ . '/approvals.php';
    $who  = 'Automatically (upcoming event)';
    $sent = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        // Claimed here: a cell edit or an overlapping run that got there first wins.
        if (fabo_exec('UPDATE `events` SET committee = ?, committee_id = ? WHERE id = ? AND committee_id IS NULL', 'sii',
                      [(string) $c['name'], (int) $c['id'], $id]) !== 1) {
            continue;
        }
        $decided = in_array($r['appr'], ['approved', 'rejected'], true);
        if (!$decided) {
            fabo_cm_open_round($c, $id, $who, false, false, false);
        }
        fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
                   VALUES (?,?,?,?,?,?,?)', 'sisisss',
                  ['Automatic (upcoming event)', null, 'events', $id, 'committee', null, (string) $c['name']]);
        if (!$decided) {
            $sent[] = $id;           // only these wait on the members, so only these are told
        }
    }
    if (!$sent) {
        return 0;
    }
    $n      = count($sent);
    $title  = fabo_cm_title((string) $c['name']);
    $mailed = 0;
    try {
        require_once __DIR__ . '/notify.php';
        foreach (fabo_cm_members((int) $c['id']) as $m) {
            fabo_notify((int) $m['id'],
                        'Committee approval needed: ' . $n . ' upcoming event' . ($n === 1 ? '' : 's'),
                        $n . ' upcoming event' . ($n === 1 ? ' has' : 's have') . ' been sent to ' . $title
                        . '. Check each one and its budget, then approve or reject it from your Committee Dashboard.',
                        $n === 1 ? fabo_row_link((int) $m['id'], 'events', $sent[0]) : '');
        }
        $mailed = fabo_cm_assign_many_mail($c, $sent, $who);
    } catch (Throwable $e) {
        error_log('FABO committee auto-assign notify: ' . $e->getMessage());
    }
    fabo_setting_put('cm_autoassign_log_' . $scope, date('d M Y, H:i') . ' — ' . $n . ' upcoming event'
                     . ($n === 1 ? '' : 's') . ' sent to ' . $title . '; ' . $mailed . ' member'
                     . ($mailed === 1 ? '' : 's') . ' emailed once.');
    return $n;
}

/**
 * "Remind committee" on the Events grid: the ticked events still waiting on
 * their committee, gathered per member who has not yet voted, and each such
 * member sent ONE email listing all of theirs (plus a notice). Events with no
 * committee, or already decided, are skipped. $events are rows the caller has
 * already checked the viewer may open.
 * Returns ['events' => n waiting, 'members' => told, 'mailed' => n, 'skipped' => [labels],
 * 'reminded' => [event ids someone was reminded about]].
 */
function fabo_cm_remind(array $viewer, array $events, string $note = ''): array
{
    $byId = [];
    foreach ($events as $e) {
        $byId[(int) $e['id']] = $e;
    }
    $status  = fabo_cm_status_for(array_keys($byId));
    $owed    = [];   // person id => [event id, ...]
    $waiting = 0;
    $skipped = [];
    $reminded = [];
    foreach ($byId as $id => $e) {
        $label = trim((string) ($e['event_name'] ?? '')) ?: (string) ($e['event_id'] ?? ('#' . $id));
        $s = $status[$id] ?? null;
        if (!$s) {
            $skipped[] = $label . ' (no committee)';
            continue;
        }
        if ($s['state'] !== 'pending') {
            $skipped[] = $label . ' (already ' . $s['state'] . ')';
            continue;
        }
        $waiting++;
        foreach ($s['members'] as $m) {
            if ($m['decision'] === 'pending') {
                $owed[(int) $m['id']][] = $id;
                $reminded[$id] = true;
            }
        }
    }
    $who    = !empty($viewer['is_admin']) ? 'Admin' : (string) ($viewer['client']['name'] ?? '');
    $told   = 0;
    $mailed = 0;
    if ($owed) {
        require_once __DIR__ . '/approvals.php';
        require_once __DIR__ . '/notify.php';
        $pids = array_keys($owed);
        $people = fabo_all('SELECT * FROM clients WHERE id IN (' . implode(',', array_fill(0, count($pids), '?')) . ')',
                           str_repeat('i', count($pids)), $pids);
        foreach ($people as $p) {
            $list = [];
            foreach ($owed[(int) $p['id']] as $eid) {
                $list[] = $byId[$eid] + ['_cm' => $status[$eid]];
            }
            $n = count($list);
            try {
                fabo_notify((int) $p['id'], 'Reminder: ' . $n . ' event' . ($n === 1 ? '' : 's') . ' waiting for your committee decision',
                            $who . ' reminded you that ' . $n . ' event' . ($n === 1 ? ' is' : 's are')
                            . ' still waiting for your approval. Open your Committee Dashboard to approve or reject.'
                            . ($note !== '' ? ' Note: ' . $note : ''),
                            $n === 1 ? fabo_row_link((int) $p['id'], 'events', (int) $list[0]['id']) : '');
            } catch (Throwable $e) {
                error_log('FABO committee remind notify: ' . $e->getMessage());
            }
            $told++;
            try {
                if (fabo_cm_remind_mail($p, $list, $who, $note)) {
                    $mailed++;
                }
            } catch (Throwable $e) {
                error_log('FABO committee remind email: ' . $e->getMessage());
            }
        }
    }
    return ['events' => $waiting, 'members' => $told, 'mailed' => $mailed, 'skipped' => $skipped,
            'reminded' => array_keys($reminded)];
}

/**
 * An organisation's automatic-reminder settings, from the Committees tab of
 * Sheet Management: on/off, days an event waits before the first reminder,
 * days between reminders to the same member, and the hour they go out.
 * Unset, the defaults are on, 2, 2 and 10:00 (CM_REMIND_* in config.local.php
 * can change those defaults).
 */
function fabo_cm_remind_cfg(int $scope): array
{
    require_once __DIR__ . '/google.php';
    $every = (int) fabo_env('CM_REMIND_EVERY_DAYS', '2');
    $def = ['on' => $every > 0, 'after' => max(0, (int) fabo_env('CM_REMIND_AFTER_DAYS', '2')),
            'every' => max(1, $every), 'hour' => 10];
    $saved = json_decode(fabo_setting('cm_remind_cfg_' . $scope, ''), true);
    return fabo_cm_remind_clean(is_array($saved) ? $saved + $def : $def);
}

/** The settings held to sensible bounds. */
function fabo_cm_remind_clean(array $c): array
{
    return ['on'    => !empty($c['on']),
            'after' => max(0, min(30, (int) ($c['after'] ?? 2))),
            'every' => max(1, min(30, (int) ($c['every'] ?? 2))),
            'hour'  => max(0, min(23, (int) ($c['hour'] ?? 10)))];
}

function fabo_cm_remind_cfg_save(int $scope, array $c): array
{
    require_once __DIR__ . '/google.php';
    $c = fabo_cm_remind_clean($c);
    fabo_setting_put('cm_remind_cfg_' . $scope, json_encode($c));
    return $c;
}

/** What the last automatic run for this organisation did, for the Committees tab. */
function fabo_cm_remind_last_run(int $scope): string
{
    require_once __DIR__ . '/google.php';
    return fabo_setting('cm_autoremind_log_' . $scope, '');
}

/**
 * Automatic committee reminders, once a day per organisation, at the hour
 * set on its Committees tab.
 *
 * Every member who still owes a vote on an event that has waited at least
 * 'after' days with its committee gets ONE email listing all such events --
 * at most once every 'every' days. Run from cron.php and, as a fallback on a
 * portal with no cron, from fabo_head() on any page load. Each organisation's
 * day is claimed before anything is sent, so overlapping runs never send twice.
 * Returns members emailed (or queued).
 */
function fabo_cm_autoremind_sweep(): int
{
    require_once __DIR__ . '/google.php';
    $today = date('Y-m-d');
    $hour  = (int) date('G');
    try {
        $scopes = array_map('intval', array_column(
            fabo_all('SELECT DISTINCT client_id FROM committees'), 'client_id'));
    } catch (Throwable $e) {
        return 0;
    }
    $mailed = 0;
    foreach ($scopes as $scope) {
        $cfg = fabo_cm_remind_cfg($scope);
        if (!$cfg['on'] || $hour < $cfg['hour']
            || fabo_setting('cm_autoremind_day_' . $scope) === $today) {
            continue;
        }
        fabo_setting_put('cm_autoremind_day_' . $scope, $today);
        try {
            $mailed += fabo_cm_autoremind_scope($scope, $cfg);
        } catch (Throwable $e) {
            error_log('FABO committee auto-remind (org ' . $scope . '): ' . $e->getMessage());
        }
    }
    return $mailed;
}

/** One organisation's daily run (see fabo_cm_autoremind_sweep()). */
function fabo_cm_autoremind_scope(int $scope, array $cfg): int
{
    $today = date('Y-m-d');
    $log   = static function (string $what) use ($scope) {
        fabo_setting_put('cm_autoremind_log_' . $scope, date('d M Y, H:i') . ' — ' . $what);
    };

    /* From cron there is no web request: borrow the address remembered from
       one (lib/evresults.php), so links in the mail point at the portal. */
    if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
        $base = parse_url(fabo_setting('portal_base_url'));
        if (empty($base['host'])) {
            $log('not sent: the portal address is not known yet (open any portal page once).');
            return 0;
        }
        $_SERVER['HTTP_HOST']   = $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        $_SERVER['HTTPS']       = ($base['scheme'] ?? 'https') === 'https' ? 'on' : 'off';
        $_SERVER['SCRIPT_NAME'] = rtrim($base['path'] ?? '/', '/') . '/api/cron.php';
    }

    $rows = fabo_all("SELECT e.* FROM `events` e
                        JOIN committees c ON c.id = e.committee_id AND c.client_id = ?
                        JOIN approvals a ON a.sheet_key = 'events' AND a.row_id = e.id
                       WHERE a.state = 'pending' AND a.updated_at <= ? AND " . fabo_cm_dated_sql(), 'is',
                     [$scope, date('Y-m-d H:i:s', time() - $cfg['after'] * 86400)]);
    $byId   = array_column($rows, null, 'id');
    $status = $byId ? fabo_cm_status_for(array_keys($byId)) : [];
    $owed   = [];
    foreach ($status as $id => $s) {
        if ($s['state'] !== 'pending') {
            continue;
        }
        foreach ($s['members'] as $m) {
            if ($m['decision'] === 'pending') {
                $owed[(int) $m['id']][] = $byId[$id] + ['_cm' => $s];
            }
        }
    }
    if (!$owed) {
        $log('nobody to remind: no event has been waiting ' . $cfg['after'] . '+ day'
             . ($cfg['after'] === 1 ? '' : 's') . ' on a member\'s vote.');
        return 0;
    }
    $last = json_decode(fabo_setting('cm_autoremind_last', '{}'), true) ?: [];
    $due  = date('Y-m-d', strtotime($today . ' -' . $cfg['every'] . ' days'));
    require_once __DIR__ . '/notify.php';
    $pids   = array_keys($owed);
    $people = fabo_all('SELECT * FROM clients WHERE revoked_at IS NULL AND id IN ('
                       . implode(',', array_fill(0, count($pids), '?')) . ')', str_repeat('i', count($pids)), $pids);
    $mailed = 0;
    $names  = [];
    $events = [];
    foreach ($people as $p) {
        $pid = (int) $p['id'];
        if (isset($last[$pid]) && $last[$pid] > $due) {
            continue;                       // reminded within the last 'every' days
        }
        $list = $owed[$pid];
        $n    = count($list);
        try {
            fabo_notify($pid, 'Reminder: ' . $n . ' event' . ($n === 1 ? '' : 's') . ' waiting for your committee decision',
                        $n . ' event' . ($n === 1 ? ' is' : 's are') . ' still waiting for your approval. '
                        . 'Open your Committee Dashboard to approve or reject.',
                        $n === 1 ? fabo_row_link($pid, 'events', (int) $list[0]['id']) : '');
            if (fabo_cm_remind_mail($p, $list, '')) {
                $mailed++;
                $names[] = (string) $p['name'];
            }
        } catch (Throwable $e) {
            error_log('FABO committee auto-remind: ' . $e->getMessage());
        }
        foreach ($list as $ev) {
            $events[(int) $ev['id']] = true;
        }
        $last[$pid] = $today;
    }
    fabo_setting_put('cm_autoremind_last', json_encode($last));
    $log($names
        ? count($names) . ' member' . (count($names) === 1 ? '' : 's') . ' emailed about ' . count($events)
          . ' pending event' . (count($events) === 1 ? '' : 's') . ': ' . implode(', ', $names) . '.'
        : 'nobody due: every member owing a vote was reminded within the last ' . $cfg['every'] . ' day'
          . ($cfg['every'] === 1 ? '' : 's') . ' (or has no email address).');
    return $mailed;
}

/**
 * The one reminder email to a committee member, listing every event in
 * $events (each carrying its fabo_cm_status() as '_cm') still awaiting their
 * vote. $who is who sent it, or '' for the automatic reminder.
 * Returns true when it went or is queued; false with no email address.
 */
function fabo_cm_remind_mail(array $member, array $events, string $who, string $note = ''): bool
{
    $to = trim((string) ($member['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !$events) {
        return false;
    }
    require_once __DIR__ . '/mailtpl.php';
    require_once __DIR__ . '/outbox.php';

    $n     = count($events);
    $date  = static fn($d) => $d ? date('d M Y', strtotime((string) $d)) : '';
    $lines = [];
    $text  = '';
    foreach ($events as $i => $ev) {
        $title = trim((string) ($ev['event_name'] ?? '')) ?: (string) ($ev['event_id'] ?? 'An event');
        $bits  = array_filter([
            (string) ($ev['event_id'] ?? ''),
            $date($ev['start_date'] ?? null),
            (string) ($ev['_cm']['committee']['title'] ?? ''),
            ($b = fabo_cm_budget_total((int) $ev['id'])) !== '' ? 'Budget ' . $b : '',
        ], static fn($v) => trim((string) $v) !== '');
        $lines[] = '<strong>' . e($title) . '</strong>'
                 . ($bits ? '<br><span style="color:' . fabo_mail_soft() . ';font-size:13px;">' . e(implode(' · ', $bits)) . '</span>' : '');
        $text   .= '  ' . ($i + 1) . '. ' . $title . ($bits ? ' (' . implode(', ', $bits) . ')' : '') . "\n";
    }
    $loginId = trim((string) ($member['login_id'] ?? ''));
    $signIn  = fabo_mail_home() . ($loginId !== '' ? '?u=' . rawurlencode($loginId) : '');
    $brand   = fabo_app_name();
    $lead    = $n === 1
        ? 'This is a reminder that 1 event is still waiting for your approval as a committee member.'
        : 'This is a reminder that ' . $n . ' events are still waiting for your approval as a committee member.';
    $subject = 'Reminder: ' . $n . ' event' . ($n === 1 ? '' : 's') . ' pending your committee approval';

    $text = 'Hi ' . $member['name'] . ",\n\n" . $lead . "\n\n"
          . "PENDING YOUR DECISION\n" . $text
          . ($note !== '' ? "\nNote from " . $who . ":\n  " . $note . "\n" : '')
          . "\nPlease sign in and approve or reject each one from your Committee Dashboard.\n"
          . "\nSIGN IN\n  " . $signIn . "\n"
          . ($loginId !== '' ? '  Your user ID is ' . $loginId . ".\n" : '')
          . ($who !== '' ? "\nSent by " . $who . '.' : "\nThis is an automatic reminder; it repeats every few days until you decide.")
          . fabo_sign_off($brand);

    $inner = fabo_mail_p('Hi ' . e((string) $member['name']) . ',')
           . fabo_mail_p(e($lead) . ' Please sign in and approve or reject each one from your Committee Dashboard.')
           . fabo_mail_list($lines)
           . ($note !== '' ? fabo_mail_p('Note from ' . e($who) . ':') . fabo_mail_quote($note) : '')
           . fabo_mail_button('Sign in and review')
           . ($loginId !== '' ? fabo_mail_note('Your user ID is <strong>' . e($loginId) . '</strong>.') : '')
           . fabo_mail_note(($who !== '' ? 'Reminder sent by ' . e($who) . '.'
                                           : 'This is an automatic reminder; it repeats every few days until you decide.')
                            . ' A rejection needs a short remark saying why.');
    $html = fabo_mail_wrap($brand, $n === 1 ? 'An event is waiting for your decision'
                                            : $n . ' events are waiting for your decision', $inner, $subject);

    [$sent, , $queued] = fabo_post_or_queue($to, $subject, $text, $html, (int) $member['id'], 'committee reminder');
    return $sent || $queued;
}

/**
 * Where an event stands with its committee.
 *
 * Returns null when it has none, else: committee, rule, members (each with
 * their vote, justification and when), counts, and the decision -- state
 * ('approved', 'rejected', 'pending') with a label and a tone for the grid.
 */
function fabo_cm_status(int $eventId): ?array
{
    $all = fabo_cm_status_for([$eventId]);
    return $all[$eventId] ?? null;
}

/** fabo_cm_status() for many events at once: one query per table, not per row. */
function fabo_cm_status_for(array $eventIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $events = fabo_all('SELECT id, committee_id FROM `events` WHERE committee_id IS NOT NULL
                             AND committee_id > 0 AND id IN (' . $in . ')', str_repeat('i', count($ids)), $ids);
    } catch (Throwable $e) {
        return [];                          // before the column exists
    }
    if (!$events) {
        return [];
    }
    $cids = array_values(array_unique(array_map(static fn($e) => (int) $e['committee_id'], $events)));
    $cin  = implode(',', array_fill(0, count($cids), '?'));
    $committees = [];
    foreach (fabo_all('SELECT * FROM committees WHERE id IN (' . $cin . ')', str_repeat('i', count($cids)), $cids) as $c) {
        $committees[(int) $c['id']] = $c + ['members' => []];
    }
    foreach (fabo_all('SELECT m.committee_id, c.id, c.name, c.email, c.kind FROM committee_members m
                        JOIN clients c ON c.id = m.person_id WHERE m.committee_id IN (' . $cin . ')
                        ORDER BY c.name', str_repeat('i', count($cids)), $cids) as $m) {
        if (isset($committees[(int) $m['committee_id']])) {
            $committees[(int) $m['committee_id']]['members'][] = $m;
        }
    }
    $votes = [];
    foreach (fabo_all('SELECT * FROM committee_votes WHERE event_id IN (' . $in . ')',
                      str_repeat('i', count($ids)), $ids) as $v) {
        $votes[(int) $v['event_id']][(int) $v['committee_id']][(int) $v['person_id']] = $v;
    }

    $out = [];
    foreach ($events as $e) {
        $c = $committees[(int) $e['committee_id']] ?? null;
        if (!$c) {
            continue;
        }
        $mine = $votes[(int) $e['id']][(int) $c['id']] ?? [];
        $members = [];
        $yes = 0;
        $no  = 0;
        foreach ($c['members'] as $m) {
            $v = $mine[(int) $m['id']] ?? null;
            $decision = $v ? (string) $v['decision'] : 'pending';
            $yes += $decision === 'approved' ? 1 : 0;
            $no  += $decision === 'rejected' ? 1 : 0;
            $members[] = ['id' => (int) $m['id'], 'name' => (string) $m['name'],
                          'decision' => $decision,
                          'justification' => (string) ($v['justification'] ?? ''),
                          'when' => $v ? date('d M Y, H:i', strtotime((string) $v['decided_at'])) : ''];
        }
        $out[(int) $e['id']] = fabo_cm_decide($c, $members, $yes, $no);
    }
    return $out;
}

/** Turn a committee's votes into its decision, by its rule. */
function fabo_cm_decide(array $c, array $members, int $yes, int $no): array
{
    $n       = count($members);
    $pending = $n - $yes - $no;
    $rule    = (string) ($c['rule'] ?? 'all');
    /* Every rule is one question: have enough approved, or have so many
       rejected that enough never can? Only the number differs. */
    $need = fabo_cm_needed($c, $n);
    if ($n === 0) {
        $state = 'pending';
    } else {
        $state = $yes >= $need ? 'approved' : ($n - $no < $need ? 'rejected' : 'pending');
    }
    $label = $state === 'approved' ? 'Approved'
           : ($state === 'rejected' ? 'Rejected' : 'Waiting');
    $label .= ' · ' . $yes . ' of ' . $n . ' approved'
            . ($no ? ', ' . $no . ' rejected' : '')
            . ($pending && $state === 'pending' ? ', ' . $pending . ' pending' : '');
    return [
        'committee' => ['id' => (int) $c['id'], 'name' => (string) $c['name'],
                        'title' => fabo_cm_title((string) $c['name']),
                        'rule' => $rule, 'rule_label' => fabo_cm_rule_label($c, $n)],
        'members'   => $members,
        'yes' => $yes, 'no' => $no, 'pending' => $pending, 'total' => $n, 'needed' => $need,
        'state' => $state, 'label' => $label,
        'tone'  => $state === 'approved' ? 'tone-good' : ($state === 'rejected' ? 'tone-risk' : 'tone-warn'),
    ];
}

/**
 * Write the committee's decision onto the event's approval, if it has one.
 *
 * Only when it is decided: while votes are still coming in the approval is
 * left as it is (pending, from when the committee was assigned).
 */
function fabo_cm_settle(int $eventId, string $who): ?array
{
    $s = fabo_cm_status($eventId);
    if (!$s || $s['state'] === 'pending') {
        return $s;
    }
    require_once __DIR__ . '/approvals.php';
    $now = fabo_approval('events', $eventId);
    if ((string) $now['state'] === $s['state']) {
        return $s;
    }
    /* The members' remarks, on the side they voted: why it was sent back, or
       anything they noted while approving. */
    $why = [];
    foreach ($s['members'] as $m) {
        if ($m['decision'] === $s['state'] && trim((string) $m['justification']) !== '') {
            $why[] = $m['name'] . ': ' . trim((string) $m['justification']);
        }
    }
    $said = implode(' | ', $why);
    $remark = $s['state'] === 'approved'
        ? 'Approved by ' . $s['committee']['title'] . ' (' . $s['yes'] . ' of ' . $s['total'] . ').'
          . ($said !== '' ? ' ' . $said : '')
        : 'Sent back by ' . $s['committee']['title'] . '. ' . $said;
    fabo_set_approval('events', $eventId, $s['state'], mb_substr($remark, 0, 1000),
                      'Committee: ' . $s['committee']['name']);
    /* And the rest of the event follows the one decision: the budget lines it
       reviewed (Budget Allocated with them) and Sign-off. */
    fabo_cm_budget_apply($eventId, $s['state'], $said, $s['committee']['title']);
    fabo_cm_signoff($eventId, $s['state'], $remark);
    return $s;
}

/**
 * One member's vote. Returns the event's status after it.
 *
 * Only a member of the event's committee may vote, and only while the
 * committee has not yet decided -- a decided event is closed until the team
 * sends it again (fabo_cm_send_again()). A rejection must say why; remarks
 * with an approval are optional.
 */
function fabo_cm_vote(array $viewer, int $eventId, string $decision, string $justification): array
{
    $person = fabo_cm_person($viewer);
    $s = fabo_cm_status($eventId);
    if (!$s) {
        throw new InvalidArgumentException('This event has no committee.');
    }
    $isMember = $person > 0 && in_array($person, array_column($s['members'], 'id'), true);
    if (!$isMember) {
        throw new InvalidArgumentException('Only members of ' . $s['committee']['title']
                                           . ' can decide on this event.');
    }
    if ($s['state'] !== 'pending') {
        throw new InvalidArgumentException('The committee has already decided: '
                                           . strtolower($s['state']) . '.');
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Approve or reject.');
    }
    $justification = trim($justification);
    if ($decision === 'rejected' && mb_strlen($justification) < 5) {
        throw new InvalidArgumentException('Say why you are rejecting it -- the justification is required.');
    }
    fabo_exec('INSERT INTO committee_votes (event_id, committee_id, person_id, decision, justification, decided_at)
               VALUES (?,?,?,?,?,NOW())
               ON DUPLICATE KEY UPDATE decision = VALUES(decision), justification = VALUES(justification),
                                       decided_at = NOW()',
              'iiiss', [$eventId, (int) $s['committee']['id'], $person, $decision,
                        $justification === '' ? null : mb_substr($justification, 0, 2000)]);
    fabo_exec('INSERT INTO activity_audit (who, client_id, table_name, row_id, field, old_value, new_value)
               VALUES (?,?,?,?,?,?,?)', 'sisisss',
              [(string) ($viewer['client']['name'] ?? 'Member') . ' (committee)', $person, 'events', $eventId,
               '(committee vote)', null, $decision . ($justification !== '' ? ': ' . $justification : '')]);

    $after = fabo_cm_settle($eventId, (string) ($viewer['client']['name'] ?? 'Member')) ?? fabo_cm_status($eventId);
    if ($after && $after['state'] !== 'pending') {
        fabo_cm_announce_decision($eventId, $after);
        // The event's owner hears it too, by email, with what to do next.
        $appr = fabo_approval('events', $eventId);
        fabo_cm_tell_owner($eventId, $after, (string) ($appr['remarks'] ?? ''));
    }
    return $after;
}

/** Tell the committee's members, and the organisation it belongs to, how it was decided. */
function fabo_cm_announce_decision(int $eventId, array $s): void
{
    try {
        require_once __DIR__ . '/notify.php';
        $e = fabo_one('SELECT event_id, event_name, client_id FROM `events` WHERE id = ?', 'i', [$eventId]);
        $title = trim((string) ($e['event_name'] ?? '')) ?: (string) ($e['event_id'] ?? 'An event');
        $head  = ($s['state'] === 'approved' ? 'Approved' : 'Rejected') . ' by '
               . $s['committee']['title'] . ': ' . $title;
        $body  = $s['label'] . '.';
        foreach ($s['members'] as $m) {
            fabo_notify((int) $m['id'], $head, $body, fabo_row_link((int) $m['id'], 'events', $eventId));
        }
        $c = fabo_cm_get((int) $s['committee']['id']);
        if ((int) ($c['client_id'] ?? 0) > 0) {
            fabo_notify((int) $c['client_id'], $head, $body,
                        fabo_row_link((int) $c['client_id'], 'events', $eventId));
        } else {
            fabo_notify_admin($head, $body);
        }
    } catch (Throwable $e) {
        error_log('FABO committee decision notice: ' . $e->getMessage());
    }
}

/**
 * The events waiting on this person's vote: on a committee they sit on,
 * not yet decided, and not yet voted on by them. Empty for anyone who sits
 * on no committee -- which is what keeps the dashboard card theirs alone.
 */
function fabo_cm_waiting_for(?array $viewer): array
{
    $person = fabo_cm_person($viewer);
    if ($person <= 0) {
        return [];
    }
    try {
        $rows = fabo_all('SELECT e.* FROM `events` e
                            JOIN committee_members m ON m.committee_id = e.committee_id AND m.person_id = ?
                           WHERE e.committee_id IS NOT NULL AND ' . fabo_cm_dated_sql() . '
                             AND NOT EXISTS (SELECT 1 FROM approvals a WHERE a.sheet_key = "events" AND a.row_id = e.id
                                                AND a.state IN ("approved", "rejected"))
                             AND NOT EXISTS (SELECT 1 FROM committee_votes v
                                              WHERE v.event_id = e.id AND v.committee_id = e.committee_id
                                                AND v.person_id = ?)
                           ORDER BY COALESCE(e.start_date, "9999-12-31"), e.id', 'ii', [$person, $person]);
    } catch (Throwable $e) {
        return [];
    }
    $status = fabo_cm_status_for(array_column($rows, 'id'));
    return array_values(array_filter(array_map(static function ($r) use ($status) {
        $r['_cm'] = $status[(int) $r['id']] ?? null;
        return $r;
    }, $rows), static fn($r) => $r['_cm'] && $r['_cm']['state'] === 'pending'));
}

/** Does this person sit on any committee at all? */
function fabo_cm_is_member(?array $viewer): bool
{
    $person = fabo_cm_person($viewer);
    if ($person <= 0) {
        return false;
    }
    try {
        return (bool) fabo_one('SELECT 1 x FROM committee_members WHERE person_id = ? LIMIT 1', 'i', [$person]);
    } catch (Throwable $e) {
        return false;
    }
}

/* ------------------------------------------------- the committee desk */

/*
 * A committee member is created on Team Management with the Committee member
 * tick. They hold no sheets, no meetings and no other desk: they sign in to
 * committee.php, which shows only the events sent to the committees they sit
 * on, with the counts for those events, and where they approve or reject --
 * a rejection always with its justification.
 */

/**
 * Somebody is no longer a committee member: off every committee they sat on,
 * their votes gone, and each of those committees' events decided again on
 * the members who remain.
 */
function fabo_cm_drop_person(int $personId, string $who): void
{
    $cids = array_map('intval', array_column(
        fabo_all('SELECT committee_id FROM committee_members WHERE person_id = ?', 'i', [$personId]),
        'committee_id'));
    if (!$cids) {
        return;
    }
    fabo_exec('DELETE FROM committee_members WHERE person_id = ?', 'i', [$personId]);
    fabo_exec('DELETE FROM committee_votes WHERE person_id = ?', 'i', [$personId]);
    foreach ($cids as $cid) {
        foreach (fabo_all('SELECT id FROM `events` WHERE committee_id = ?', 'i', [$cid]) as $e) {
            fabo_cm_settle((int) $e['id'], $who);
        }
    }
}

/** Is this viewer a committee-only account? Never the administrator. */
function fabo_cm_only(?array $viewer): bool
{
    return empty($viewer['is_admin']) && !empty($viewer['client']['is_committee']);
}

/**
 * May this person open this event's details? When it sits with a committee
 * they are on, or when it is a row they can open on their own Events sheet
 * (the View details icon on Programs Data).
 */
function fabo_cm_sees_event(?array $viewer, int $eventId): bool
{
    $person = fabo_cm_person($viewer);
    if ($person <= 0 || $eventId <= 0) {
        return false;
    }
    try {
        if (fabo_one('SELECT 1 x FROM `events` e
                        JOIN committee_members m ON m.committee_id = e.committee_id AND m.person_id = ?
                       WHERE e.id = ?', 'ii', [$person, $eventId])) {
            return true;
        }
        if (fabo_permission($viewer, 'events') === 'none') {
            return false;
        }
        require_once __DIR__ . '/endpoint.php';
        return fabo_load_row($viewer, 'events', $eventId) !== null;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The events on the committee dashboard: those sent to a committee this
 * person sits on, soonest first.
 *
 * Each row carries _approval ('approved', 'rejected' or 'pending' -- the
 * event's own approval, which the committee's decision is written to), _cm
 * (the committee's status), _mine (this person's own vote) and _files.
 */
function fabo_cm_programs(?array $viewer): array
{
    $person = fabo_cm_person($viewer);
    if ($person <= 0) {
        return [];
    }
    try {
        $rows = fabo_all('SELECT e.* FROM `events` e
                            JOIN committee_members m ON m.committee_id = e.committee_id AND m.person_id = ?
                           WHERE e.committee_id IS NOT NULL AND ' . fabo_cm_dated_sql(), 'i', [$person]);
    } catch (Throwable $e) {
        error_log('FABO committee events: ' . $e->getMessage());
        return [];
    }
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int) $r['id']] = $r;         // each programme once
    }
    $rows = array_values($byId);
    usort($rows, static fn($a, $b) => [(string) ($a['start_date'] ?: '9999-12-31'), (int) $a['id']]
                                  <=> [(string) ($b['start_date'] ?: '9999-12-31'), (int) $b['id']]);

    $ids    = array_keys($byId);
    $status = fabo_cm_status_for($ids);
    $files  = fabo_cm_file_counts($ids);
    require_once __DIR__ . '/approvals.php';
    $appr   = fabo_approvals_for('events', $ids);
    foreach ($rows as &$r) {
        $id = (int) $r['id'];
        $s  = $status[$id] ?? null;
        $mine = null;
        foreach ($s['members'] ?? [] as $m) {
            if ($m['id'] === $person) {
                $mine = $m['decision'];
            }
        }
        $state = (string) ($appr[$id]['state'] ?? 'pending');
        $r['_approval'] = in_array($state, ['approved', 'rejected'], true) ? $state : 'pending';
        $r['_cm']    = $s;
        $r['_mine']  = $mine;
        $r['_files'] = $files[$id] ?? 0;
    }
    unset($r);
    return $rows;
}

/** The counts on the committee dashboard, from fabo_cm_programs(). */
function fabo_cm_metrics(array $rows): array
{
    $m = ['total' => count($rows), 'approved' => 0, 'rejected' => 0, 'pending' => 0,
          'awaiting_me' => 0, 'i_approved' => 0, 'i_rejected' => 0, 'upcoming' => 0, 'committees' => []];
    $today = date('Y-m-d');
    $soon  = date('Y-m-d', strtotime('+30 days'));
    foreach ($rows as $r) {
        $m[$r['_approval']]++;
        if ($r['_mine'] === 'approved') {
            $m['i_approved']++;
        } elseif ($r['_mine'] === 'rejected') {
            $m['i_rejected']++;
        } elseif ($r['_mine'] === 'pending' && $r['_cm']['state'] === 'pending' && $r['_approval'] === 'pending') {
            $m['awaiting_me']++;
        }
        $start = (string) ($r['start_date'] ?? '');
        if ($start !== '' && $start >= $today && $start <= $soon) {
            $m['upcoming']++;
        }
        if ($r['_cm']) {
            $s    = $r['_cm'];
            $name = $s['committee']['name'];
            $m['committees'][$name] ??= ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0,
                                         'rule' => $s['committee']['rule_label']];
            $m['committees'][$name]['total']++;
            $m['committees'][$name][$s['state']]++;
        }
    }
    ksort($m['committees']);
    return $m;
}

/** Attachments per event: its own files plus the papers filed on its budget lines. */
function fabo_cm_file_counts(array $eventIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
    if (!$ids) {
        return [];
    }
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        foreach (fabo_all("SELECT row_id, COUNT(*) n FROM attachments WHERE sheet_key = 'events'
                            AND row_id IN (" . $in . ') GROUP BY row_id', str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['row_id']] = (int) $r['n'];
        }
        foreach (fabo_all("SELECT b.row_id, COUNT(*) n FROM attachments a
                             JOIN budget_lines b ON b.id = a.row_id
                            WHERE a.sheet_key = 'budget' AND b.sheet_key = 'events'
                              AND b.row_id IN (" . $in . ') GROUP BY b.row_id', str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['row_id']] = ($out[(int) $r['row_id']] ?? 0) + (int) $r['n'];
        }
    } catch (Throwable $e) {
        // Before budget_lines exists: the event's own files are counted.
    }
    return $out;
}

/** An event's attachments, its own first, then each budget line's papers. */
function fabo_cm_event_files(int $eventId): array
{
    $out = [];
    foreach (fabo_all("SELECT * FROM attachments WHERE sheet_key = 'events' AND row_id = ?
                        ORDER BY created_at DESC, id DESC", 'i', [$eventId]) as $a) {
        $a['_where'] = 'Event';
        $a['_kind']  = 'event';
        $out[] = $a;
    }
    /* The budget's papers: quotations (planned costs, verified before the
       event) and invoices (actual costs, reviewed after it). An empty
       doc_kind is a quotation filed before the kinds existed. */
    try {
        foreach (fabo_all("SELECT a.*, b.item_name, b.approved_by, b.required FROM attachments a
                             JOIN budget_lines b ON b.id = a.row_id
                            WHERE a.sheet_key = 'budget' AND b.sheet_key = 'events' AND b.row_id = ?
                            ORDER BY b.item_name, a.created_at DESC", 'i', [$eventId]) as $a) {
            $a['_where'] = 'Budget · ' . (string) $a['item_name'];
            $a['_kind']  = ($a['doc_kind'] ?? '') === 'invoice' ? 'invoice' : 'quotation';
            $out[] = $a;
        }
    } catch (Throwable $e) {
        try {
            // Before doc_kind / approved_by exist: every budget paper is a quotation.
            foreach (fabo_all("SELECT a.*, b.item_name FROM attachments a
                                 JOIN budget_lines b ON b.id = a.row_id
                                WHERE a.sheet_key = 'budget' AND b.sheet_key = 'events' AND b.row_id = ?
                                ORDER BY b.item_name, a.created_at DESC", 'i', [$eventId]) as $a) {
                $a['_where'] = 'Budget · ' . (string) $a['item_name'];
                $a['_kind']  = 'quotation';
                $out[] = $a;
            }
        } catch (Throwable $e2) {
            // No budget lines table yet.
        }
    }
    return $out;
}

/**
 * An event's filled-in fields, as label/value pairs, in sheet order.
 *
 * Read-only: computed columns, pictures and the committee's own columns are
 * left out (the panel shows those its own way), and so is anything empty.
 */
function fabo_cm_event_details(array $row): array
{
    require_once __DIR__ . '/ui.php';
    $sheet = fabo_sheet('events');
    $skip  = ['committee', 'approval_to'];
    $out   = [];
    foreach ($sheet['columns'] ?? [] as $c) {
        [$label, $field, $type] = [$c[0], $c[1], $c[2] ?? 'text'];
        $opts = $c[3] ?? [];
        if (in_array($type, ['computed', 'images', 'image', 'file'], true) || in_array($field, $skip, true)) {
            continue;
        }
        $v = $row[$field] ?? null;
        if ($v === null || trim((string) $v) === '') {
            continue;
        }
        switch ($type) {
            case 'date':
                $t = strtotime((string) $v);
                $v = $t ? date('d M Y', $t) : (string) $v;
                break;
            case 'money':
                $v = fabo_money($v);
                break;
            case 'checkbox':
                $v = (int) $v ? 'Yes' : 'No';
                break;
            default:
                $v = (string) $v;
        }
        $out[] = ['label' => !empty($opts['group']) ? $opts['group'] . ' — ' . $label : $label,
                  'value' => $v, 'long' => $type === 'long'];
    }
    return $out;
}
