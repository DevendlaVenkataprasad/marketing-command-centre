// Work Management end-to-end browser test, LOCAL ONLY (never deploy).
//
//   node tests/wm_e2e.mjs <base url> <admin email> <admin password> <output dir>
//
// Needs a running copy of the portal on an EMPTY throwaway database (e.g.
// php -S localhost:8781 on a copy whose config.local.php points at one) and
// Google Chrome. Drives headless Chrome over the DevTools protocol:
//   admin signs in → creates a department → creates a task (and a second one
//   in a second department made from the task form's "+ New department") →
//   moves it on the board and changes its status in the side panel → invites a
//   client → the client sets a password from the one-time link, signs in through
//   the normal login and sees only what is shared → admin downloads the Excel
//   report, which is opened and its columns checked. Also checks every page at
//   1366 and 390 px for horizontal scrolling and saves screenshots.
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';

const [BASE, ADMIN_EMAIL, ADMIN_PASS, OUT] = process.argv.slice(2);
if (!BASE || !OUT) { console.error('usage: node tests/wm_e2e.mjs <base> <admin email> <admin password> <out dir>'); process.exit(2); }
mkdirSync(OUT, { recursive: true });
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const PORT = 9333;
const profile = join(tmpdir(), 'wm-e2e-chrome-' + Date.now());
const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + profile, '--no-first-run', '--disable-gpu', '--window-size=1366,900', 'about:blank'], { stdio: 'ignore' });

let pass = 0, failed = 0;
const ok = (c, what) => { if (c) { pass++; console.log('  ok   ' + what); } else { failed++; console.log('  FAIL ' + what); } };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function browserWs() {
  for (let i = 0; i < 50; i++) {
    try { const j = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json(); return j.webSocketDebuggerUrl; } catch { await sleep(200); }
  }
  throw new Error('Chrome did not start');
}

const ws = new WebSocket(await browserWs());
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0;
const waiting = new Map(), listeners = [];
ws.addEventListener('message', (m) => {
  const d = JSON.parse(m.data);
  if (d.id && waiting.has(d.id)) { const w = waiting.get(d.id); waiting.delete(d.id); d.error ? w.rej(new Error(d.error.message)) : w.res(d.result); }
  else if (d.method) { listeners.forEach((l) => l(d)); }
});
const send = (method, params = {}, sessionId) => new Promise((res, rej) => { const id = ++seq; waiting.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params, sessionId })); });

async function newPage() {
  const { browserContextId } = await send('Target.createBrowserContext');
  const { targetId } = await send('Target.createTarget', { url: 'about:blank', browserContextId });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const s = (m, p) => send(m, p, sessionId);
  await s('Page.enable'); await s('Runtime.enable');
  const errors = [];
  listeners.push((d) => {
    if (d.sessionId !== sessionId) { return; }
    if (d.method === 'Runtime.exceptionThrown') { errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text); }
  });
  const P = {
    errors,
    async size(w, h, mobile = false) { await s('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile }); },
    async go(url) {
      const loaded = new Promise((r) => { const l = (d) => { if (d.sessionId === sessionId && d.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); r(); } }; listeners.push(l); });
      await s('Page.navigate', { url: url.startsWith('http') ? url : BASE + url });
      await loaded;
    },
    async eval(expr) {
      const r = await s('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
      if (r.exceptionDetails) { throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text)); }
      return r.result.value;
    },
    async wait(expr, what, ms = 8000) {
      const t0 = Date.now();
      while (Date.now() - t0 < ms) { try { if (await P.eval(expr)) { return true; } } catch { /* page navigating */ } await sleep(100); }
      throw new Error('Timed out waiting for: ' + what);
    },
    async click(sel) { await P.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)}); if(!e) throw new Error('no ${sel.replace(/'/g, '')}'); e.click(); return 1})()`); },
    async fill(sel, v) { await P.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)}); if(!e) throw new Error('no field'); e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); return 1})()`); },
    async submitNav(sel) {
      const loaded = new Promise((r) => { const l = (d) => { if (d.sessionId === sessionId && d.method === 'Page.loadEventFired') { listeners.splice(listeners.indexOf(l), 1); r(); } }; listeners.push(l); });
      await P.click(sel); await loaded;
    },
    async shot(name) { const { data } = await s('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); writeFileSync(join(OUT, name + '.png'), Buffer.from(data, 'base64')); },
    async noHScroll() { return P.eval('document.documentElement.scrollWidth <= window.innerWidth + 1'); },
    async mouse(type, x, y) { await s('Input.dispatchMouseEvent', { type, x, y, button: 'left', buttons: type === 'mouseReleased' ? 0 : 1, clickCount: 1 }); },
  };
  return P;
}

const A = await newPage();
await A.size(1366, 900);
try {
  /* ---- admin signs in through the portal's own login */
  console.log('Admin');
  await A.go('/login.php?admin=1&next=' + encodeURIComponent('/admin/work.php?p=departments'));
  await A.fill('#em', ADMIN_EMAIL); await A.fill('#pw', ADMIN_PASS);
  await A.submitNav('form button.authgo');
  ok((await A.eval('location.pathname')).endsWith('/admin/work.php'), 'admin signed in and landed on Work → Departments');
  await A.wait('document.querySelector(".wm-empty h3")', 'empty state');
  ok(await A.eval('document.querySelector(".wm-empty h3").textContent') === 'No departments yet', 'fresh install: no departments, friendly empty state');
  ok(await A.eval('[...document.querySelectorAll("#sidenav .wm-sub")].map(a=>a.textContent).join(",")') === 'Overview,Tasks,Departments,Access,Reports', 'sidebar shows the five Work pages');

  /* ---- department */
  await A.click('#nd2');
  await A.wait('document.querySelector(".wm-dialog [name=name]")', 'department dialog');
  await A.fill('.wm-dialog [name=name]', 'Design');
  await A.click('.wm-dialog button[type=submit]');
  await A.wait('[...document.querySelectorAll(".wm-dcard h3")].some(h=>h.textContent.includes("Design"))', 'department card');
  ok(true, 'department "Design" created');
  await A.wait('!document.querySelector(".wm-dialog")', 'dialog closed');

  /* ---- tasks: empty, then the first task */
  await A.go('/admin/work.php?p=tasks');
  await A.wait('document.querySelector(".wm-empty h3")', 'tasks empty');
  ok(await A.eval('document.querySelector(".wm-empty h3").textContent') === 'No tasks yet', 'fresh install: no tasks');
  await A.click('.wm-empty [data-newtask]');
  await A.wait('document.querySelector("#nt [name=title]")', 'task dialog');
  await A.fill('#nt [name=title]', 'Logo design');
  await A.fill('#nt [name=description]', 'Three logo options for the client');
  const designId = await A.eval('[...document.querySelectorAll("#nt [name=department_id] option")].find(o=>o.textContent==="Design").value');
  await A.fill('#nt [name=department_id]', designId);
  await A.fill('#nt [name=priority]', 'High');
  await A.fill('#nt [name=due_date]', new Date(Date.now() + 5 * 864e5).toISOString().slice(0, 10));
  await A.click('.wm-dialog button[type=submit]');
  await A.wait('[...document.querySelectorAll(".wm-row .t")].some(t=>t.textContent==="Logo design")', 'task in list');
  ok(true, 'task "Logo design" created');
  await A.wait('!document.querySelector(".wm-dialog")', 'dialog closed');

  /* ---- a second task, in a department made from the task form */
  await A.click('.wm-head [data-newtask]');
  await A.wait('document.querySelector("#nt [name=title]")', 'task dialog 2');
  ok(await A.eval('!!document.querySelector("#nt [name=department_id] option[value=__new]")'), 'department dropdown offers "+ New department" to the admin');
  await A.fill('#nt [name=title]', 'Landing page');
  await A.fill('#nt [name=department_id]', '__new');
  await A.wait('document.querySelector("#df [name=name]")', 'new department from task form');
  await A.fill('#df [name=name]', 'Web');
  await A.click('button[form=df]');
  await A.wait('document.querySelector("#nt [name=department_id]").selectedOptions[0].textContent==="Web"', 'new department selected');
  ok(true, '"+ New department" created "Web" and selected it');
  await A.click('button[form=nt]');
  await A.wait('[...document.querySelectorAll(".wm-row .t")].some(t=>t.textContent==="Landing page")', 'second task');
  ok(true, 'task "Landing page" created in Web');
  await A.wait('!document.querySelector(".wm-dialog")', 'dialog closed');

  /* ---- board: drag Logo design from To Do to In Progress */
  await A.click('[data-view=board]');
  await A.wait('document.querySelector(".wm-board .wm-tcard")', 'board');
  const geo = await A.eval(`(()=>{const c=[...document.querySelectorAll('.wm-tcard')].find(x=>x.textContent.includes('Logo design')).getBoundingClientRect();
    const col=document.querySelector('.wm-col[data-status="In Progress"]').getBoundingClientRect(); return {x:c.x+40,y:c.y+20,tx:col.x+col.width/2,ty:col.y+120}})()`);
  await A.mouse('mousePressed', geo.x, geo.y);
  for (let i = 1; i <= 10; i++) { await A.mouse('mouseMoved', geo.x + (geo.tx - geo.x) * i / 10, geo.y + (geo.ty - geo.y) * i / 10); await sleep(20); }
  await A.shot('1366-board-dragging');
  await A.mouse('mouseReleased', geo.tx, geo.ty);
  await A.wait(`[...document.querySelectorAll('.wm-col[data-status="In Progress"] .wm-tcard')].some(x=>x.textContent.includes('Logo design'))`, 'card in In Progress');
  await A.wait('[...document.querySelectorAll(".wm-toast")].some(t=>t.textContent.includes("moved to In Progress"))', 'saved toast');
  ok(true, 'dragging the card to In Progress saved the status');

  /* ---- side panel: progress and status change */
  await A.eval(`[...document.querySelectorAll('.wm-tcard')].find(x=>x.textContent.includes('Logo design')).click()`);
  await A.wait('document.querySelector("#tp-form [name=status]")', 'task panel');
  ok(await A.eval('document.querySelectorAll("#tp-hist li").length') === 2, 'status history shows creation and the board move');
  await A.fill('#tp-form [name=progress]', '60');
  await A.fill('#tp-form [name=note]', 'Two options approved internally');
  await A.click('#tp-form button[type=submit]');
  await A.wait('document.querySelectorAll("#tp-hist li").length===3', 'history row for progress');
  await A.fill('#tp-cf [name=body]', 'First drafts are ready for review');
  await A.eval('document.querySelector("#tp-cf [name=internal]").checked=false');
  await A.click('#tp-cf button[type=submit]');
  await A.wait('document.querySelectorAll("#tp-coms .wm-com").length===1', 'comment');
  await A.fill('#tp-cf [name=body]', 'Internal: budget is tight');
  await A.click('#tp-cf button[type=submit]');
  await A.wait('document.querySelectorAll("#tp-coms .wm-com").length===2', 'internal comment');
  await A.fill('#tp-lf [name=h]', '2'); await A.fill('#tp-lf [name=m]', '30');
  await A.click('#tp-lf button[type=submit]');
  await A.wait('document.querySelector("#tp-total").textContent==="2h 30m"', 'time logged');
  ok(true, 'progress saved, client-visible and internal comments added, 2h 30m logged');
  await A.shot('1366-task-panel');
  await A.eval('document.querySelector(".wm-panel [data-close]").click()');
  await sleep(300);

  /* ---- invite a client with access to Design only */
  await A.go('/admin/work.php?p=access');
  await A.wait('document.querySelector("#a-out .wm-people, #a-out .wm-empty")', 'access page');
  ok(await A.eval('document.body.textContent.includes("No clients yet")'), 'fresh install: no clients yet');
  await A.click('#inv');
  await A.wait('document.querySelector("#if [name=role][value=client]")', 'invite dialog');
  await A.eval('document.querySelector("#if [name=role][value=client]").click()');
  await A.fill('#if [name=name]', 'Acme Client');
  await A.fill('#if [name=email]', 'client@acme.example.com');
  await A.eval(`[...document.querySelectorAll('#if [name=dept]')].find(x=>x.closest('label').textContent.includes('Design')).click()`);
  await A.eval('document.querySelector("#if [name=can_comment]").click()');
  await A.click('button[form=if]');
  await A.wait('document.querySelector("#lk")', 'invite result');
  const link = await A.eval('document.querySelector("#lk").value');
  ok(/\/setpassword\.php\?t=[0-9a-f]{64}$/.test(link), 'one-time invite link shown');
  ok(await A.eval('document.body.textContent.includes("The email was not sent")'), 'mail not configured: the copyable link is offered instead');
  await A.shot('1366-invite-link');
  await A.eval('document.querySelector(".wm-dialog [data-close]").click()');
  await A.wait('document.body.textContent.includes("Acme Client")', 'member listed');

  /* ---- the client signs in and sees only what is shared */
  console.log('Client');
  const C = await newPage();
  await C.size(1366, 900);
  await C.go(link);
  await C.fill('#np', 'acme-pass-2026'); await C.fill('#cp', 'acme-pass-2026');
  await C.submitNav('form button.btn-primary');
  await C.go('/logout.php');
  await C.go('/login.php?next=work.php');
  await C.fill('#em', 'client@acme.example.com'); await C.fill('#pw', 'acme-pass-2026');
  await C.submitNav('form button.authgo');
  await C.go('/work.php?p=tasks');
  await C.wait('document.querySelector(".wm-row, .wm-empty")', 'client tasks');
  const titles = await C.eval('[...document.querySelectorAll(".wm-row .t")].map(t=>t.textContent)');
  ok(JSON.stringify(titles) === JSON.stringify(['Logo design']), 'client sees only the Design task (' + titles.join(', ') + ')');
  ok(await C.eval('[...document.querySelectorAll("#sidenav .wm-sub")].map(a=>a.textContent).join(",")') === 'Tasks,Reports', 'client menu: Tasks and Reports only');
  ok(await C.eval('!document.querySelector(".wm-newtask, .wm-fab")'), 'client has no "New task"');
  await C.click('.wm-row');
  await C.wait('document.querySelector("#tp-coms")', 'client panel');
  ok(await C.eval('!document.querySelector("#tp-form") && !document.querySelector("#tp-lf")'), 'client panel is read-only, no time logs');
  ok(await C.eval('document.querySelector("#tp-coms").textContent.includes("First drafts") && !document.querySelector("#tp-coms").textContent.includes("budget")'), 'client sees the client-visible comment, not the internal one');
  ok(await C.eval('!!document.querySelector("#tp-cf")'), 'client with "can comment" can add a comment');
  ok(await C.eval('!document.querySelector("#tp-hist").textContent.includes("approved internally")'), 'client does not see staff notes in the status history');
  await C.shot('1366-client-panel');
  const ids = await A.eval('fetch("/api/work.php?as=admin&r=tasks").then(r=>r.json()).then(j=>j.tasks.map(t=>[t.id,t.title]))');
  const webId = ids.find((t) => t[1] === 'Landing page')[0];
  const logoId = ids.find((t) => t[1] === 'Logo design')[0];
  ok(await C.eval(`fetch('/api/work.php?r=task&id=${webId}').then(r=>r.status)`) === 404, 'client opening another department\'s task gets 404');
  ok(await C.eval(`(()=>{const f=new FormData();f.append('csrf',JSON.parse(document.getElementById('wm-conf').textContent).csrf);f.append('payload',JSON.stringify({id:${logoId},status:'Completed'}));return fetch('/api/work.php?r=task_status',{method:'POST',body:f}).then(r=>r.status)})()`) === 403, 'client trying to change the status of a task they can see gets 403');
  ok(await C.eval(`(()=>{const f=new FormData();f.append('csrf',JSON.parse(document.getElementById('wm-conf').textContent).csrf);f.append('payload',JSON.stringify({role:'team',name:'x',email:'x@example.com'}));return fetch('/api/work.php?r=member_invite',{method:'POST',body:f}).then(r=>r.status)})()`) === 403, 'client trying to invite users gets 403');
  ok(await C.eval('fetch("/admin/work.php",{redirect:"manual"}).then(r=>r.type==="opaqueredirect"||r.status===302)'), 'client cannot open the admin portal');
  await C.go('/work.php?p=reports');
  await C.wait('document.querySelector("#r-out table, #r-out .wm-empty")', 'client report');
  ok(await C.eval('[...document.querySelectorAll("#r-out tbody tr")].filter(r=>r.cells.length===17).length') === 1, 'client report preview: one row, their own');
  ok(await C.eval('!document.querySelector("#rf [name=client]") && !document.querySelector("#rf [name=assignee]")'), 'client report has no client/assignee filters');
  await C.size(390, 844, true);
  await C.go('/work.php?p=tasks');
  await C.wait('document.querySelector(".wm-row")', 'client 390');
  ok(await C.noHScroll(), 'client tasks at 390px: no horizontal scroll');
  await C.shot('390-client-tasks');

  /* ---- reports and the Excel file */
  console.log('Report');
  await A.go('/admin/work.php?p=reports');
  await A.wait('document.querySelector("#r-out table")', 'report preview');
  const cols = await A.eval('[...[...document.querySelectorAll("#r-out table")].pop().querySelectorAll("thead th")].map(t=>t.textContent)');
  ok(cols.length === 17 && cols[9] === 'Status (Last Week)' && cols[10] === 'Status (Today)', 'preview has the 17 report columns');
  ok(await A.eval('[...document.querySelectorAll("#r-out tbody tr.changed")].length') === 2, 'both tasks show as changed since last week');
  await A.shot('1366-reports');
  const href = await A.eval('document.querySelector("#rx").href');
  const dl = await A.eval(`fetch(${JSON.stringify(href)}).then(async r=>{const b=new Uint8Array(await r.arrayBuffer());let s='';for(const x of b){s+=String.fromCharCode(x)}return {type:r.headers.get('content-type'),cd:r.headers.get('content-disposition'),b64:btoa(s)}})`);
  ok(dl.type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'download is an .xlsx');
  const today = new Date(); const ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
  ok(dl.cd.includes('Work-Report_All_' + ymd + '.xlsx'), 'file name Work-Report_All_<date>.xlsx (' + dl.cd + ')');
  const file = join(OUT, 'Work-Report.xlsx');
  writeFileSync(file, Buffer.from(dl.b64, 'base64'));
  const php = process.env.PHP || 'C:/xampp/php/php.exe';
  const check = spawnSync(php, ['-r', `
    $z = new ZipArchive(); if ($z->open($argv[1]) !== true) { echo "NOZIP"; exit; }
    $wb = simplexml_load_string($z->getFromName('xl/workbook.xml')); $n = []; foreach ($wb->sheets->sheet as $s) { $n[] = (string) $s['name']; }
    $sh = simplexml_load_string($z->getFromName('xl/worksheets/sheet1.xml')); $h = []; foreach ($sh->sheetData->row[0]->c as $c) { $h[] = (string) $c->is->t; }
    $rows = []; foreach ($sh->sheetData->row as $i => $r) { if ($i === 0) continue; $v = []; foreach ($r->c as $c) { $v[] = isset($c->is) ? (string) $c->is->t : (string) $c->v; } $rows[] = $v; }
    echo json_encode(['sheets' => $n, 'head' => $h, 'rows' => $rows]);`, file], { encoding: 'utf8' });
  const x = JSON.parse(check.stdout);
  const want = ['S.No', 'Task ID', 'Task Name', 'Description', 'Department', 'Assigned To', 'Priority', 'Start Date', 'Due Date', 'Status (Last Week)', 'Status (Today)', 'Progress % (Last Week)', 'Progress % (Today)', 'Change', 'Completed On', 'Hours Logged (in period)', 'Latest Update / Remarks'];
  ok(JSON.stringify(x.sheets) === JSON.stringify(['Task Status', 'Summary']), 'the file opens: sheets Task Status and Summary');
  ok(JSON.stringify(x.head) === JSON.stringify(want), 'the file\'s columns are correct and in order');
  const logo = x.rows.find((r) => r[2] === 'Logo design');
  ok(logo && logo[9] === 'Not created' && logo[10] === 'In Progress' && logo[13] === 'Not created → In Progress' && logo[12] === '60' && logo[15] === '2.5', 'Logo design row: last week, today, change, progress, hours');

  /* ---- every admin page at 1366 and 390: no horizontal page scroll */
  console.log('Layout');
  for (const [w, h, m] of [[1440, 900, false], [1366, 900, false], [1280, 800, false], [1024, 768, false], [390, 844, true]]) {
    await A.size(w, h, m);
    for (const p of ['overview', 'tasks', 'departments', 'access', 'reports']) {
      await A.go('/admin/work.php?p=' + p);
      await A.wait('document.querySelector("#wm-body") && !document.querySelector("#wm-body .wm-sk")', p + ' loaded', 10000);
      await sleep(450);
      ok(await A.noHScroll(), `${p} at ${w}px: no horizontal page scroll`);
      await A.shot(`${w}-${p}`);
    }
    await A.go('/admin/work.php?p=tasks');
    await A.wait('document.querySelector(".wm-row, .wm-tcard, .wm-table tbody tr")', 'tasks');
    for (const v of ['board', 'table']) {
      await A.click(`[data-view=${v}]`);
      await sleep(350);
      ok(await A.noHScroll(), `tasks ${v} at ${w}px: no horizontal page scroll (scrolls in its own box)`);
      await A.shot(`${w}-tasks-${v}`);
    }
    await A.click('[data-view=list]');
    if (w === 390) { ok(await A.eval('getComputedStyle(document.querySelector(".wm-fab")).display') !== 'none', 'floating + button on phones'); }
  }
  await A.size(1366, 900);
  await A.go('/admin/work.php?p=overview');
  await A.wait('document.querySelector(".wm-kpi .val")', 'kpis');
  await sleep(900);
  ok(await A.eval('[...document.querySelectorAll(".wm-kpi .val")].map(v=>v.textContent).join(",")') === '2,1,0,0,0', 'overview KPIs: 2 open, 1 in progress');
  ok(await A.eval('[...document.querySelectorAll(".wm-chart rect.wm-cbar")].reduce((s,r)=>s+(+r.getAttribute("height")),0)') > 120, 'status-change chart bars are drawn at full height');
  ok(await A.eval('[...document.querySelectorAll(".wm-chart text")].slice(0,5).map(t=>t.textContent).join(",")') === '0,1,2,3,4', 'chart ticks are whole numbers');
  await A.eval('document.querySelector(\'.wm[data-theme]\') && document.getElementById("wm-theme").click()');
  await sleep(300);
  ok(await A.eval('document.getElementById("main").getAttribute("data-theme")') === 'dark', 'dark mode toggles');
  await A.shot('1366-overview-dark');
  await A.eval('document.getElementById("wm-theme").click()');
  ok(A.errors.length === 0 && C.errors.length === 0, 'no JavaScript errors (' + A.errors.concat(C.errors).join(' | ') + ')');
} catch (e) {
  failed++;
  console.log('  FAIL ' + e.message);
  try { await A.shot('failure'); } catch { /* ignore */ }
} finally {
  try { await send('Browser.close'); } catch { /* ignore */ }
  chrome.kill();
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* ignore */ }
}
console.log(`${pass} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
