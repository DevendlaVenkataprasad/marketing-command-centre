# FABO &amp; Pristino — Central Marketing Reporting Portal

The workbook **FABO & PRISTINO — CENTRAL MARKETING REPORTING SYSTEM**, rebuilt as a
local PHP + MySQL portal. Every sheet is a live web sheet, every formula is a
server-side computed column, and the Dashboard reads the Master Log live.

Plain PHP 8.0+ and MySQL 8 / MariaDB. No Docker, no Composer, no framework, no build
step, no npm. Vanilla JS, one shared stylesheet, charts drawn as inline SVG, and a
`ZipArchive`-based .xlsx writer — nothing is downloaded at runtime.

## Starting with your own data

The portal ships with the workbook's sample rows so you can see it working. When you are
ready to use only real data, open `admin/index.php` → **Start with your own data**, type
`DELETE` and confirm. That empties every sheet; it keeps the reference Lists (the
dropdowns read them), your clients, team members and uploaded files. Export first if you
want a copy — it cannot be undone.              
Nothing in the code carries fixed data: brands, business units, states, store types,
functions, statuses, dropdown options and column names all come from the database at
runtime.

## Getting it running

1. **Create the database**

   ```sql
   CREATE DATABASE fabo_marketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Import the two SQL files**

   ```sh
   mysql -u root -p fabo_marketing < database/schema.sql
   mysql -u root -p fabo_marketing < database/seed.sql
   ```

   `seed.sql` carries the workbook's sample rows, its reference lists, and the
   39-entry 2026 Indian festival calendar.

   The app also self-installs: it runs `CREATE TABLE IF NOT EXISTS` for every table on
   each request, so an empty database fills itself in. Importing `seed.sql` is what
   gives you data to look at. If the database cannot be reached at all you get a setup
   page with these instructions, never a blank 500.

3. **Check `config.php`**

   It reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` from the environment
   and falls back to `127.0.0.1`, `3306`, `fabo_marketing`, `root`, empty password —
   the XAMPP / WAMP / Laragon defaults. Set `APP_TZ` if you are not on `Asia/Kolkata`.

4. **Open `admin/index.php`**

   Under XAMPP, drop the folder in `htdocs` and visit
   `http://localhost/Fabo/admin/index.php`. Or serve it directly:

   ```sh
   php -S localhost:8000
   ```

   and open `http://localhost:8000/admin/index.php`.

## What is where

| Path | What it is |
|---|---|
| `admin/index.php` | Admin landing — every sheet, row counts, exports |
| `admin/sheet.php?sheet=<key>` | One sheet's grid |
| `admin/dashboard.php` | The live dashboard |
| `admin/clients.php` | Create clients, assign sheets, set validity, rotate / revoke |
| `c.php?k=<32-hex>` | A client's portal — only their sheets, only their data |
| `export.php?scope=all` | Every sheet the viewer may see, as one .xlsx |
| `export.php?scope=sheet&sheet=<key>` | One sheet |
| `lib/sheets.php` | **The single source of truth for every column** |
| `lib/rules.php` | Brands / units / states read from Lists, plus the workbook's business rules declared once |
| `admin/team.php` | Team — members with personal links and sheet access; assigned / done / outstanding per member; workload by owner |
| `lib/work.php` | Work items across every sheet that names an owner |
| `lib/uploads.php` · `download.php` · `admin/image.php` | File storage, and the guarded routes that serve files and people images |
| `lib/approvals.php` | Approve / reject, with mandatory rejection remarks |
| `lib/notify.php` | Portal notices and outgoing access emails |
| `admin/forms.php` · `form.php?k=<32-hex>` | Intake forms — shareable links that add rows to a sheet |
| `api/column.php` · `api/listopts.php` | Inline column rename and dropdown-option editing (admin, inside the grid) |
| `lib/events_blocks.php` | The Marketing Creative Calendar under the Events sheet |
| `lib/forms.php` | Intake-form validation and insert |
| `database/gen_schema.php` | Regenerates `schema.sql` from `lib/sheets.php` |
| `admin/hr.php` · `hr.php` | HR Management — the administrator's desk, and the same pages for people granted an HR role |
| `lib/hr/` | HR libraries (`core`, `masters`, `employees`, `leave`, `attendance`, `payroll`, `lifecycle`, `talent`, `workplace`, `attention`, `reports`, `pdf`) and `lib/hr/pages/` |

Sheet keys: `master`, `store`, `calendar`, `content`, `website`, `design`,
`campaigns`, `influencers`, `btl`, `events`, `lists`, plus `dashboard`.

## Access model

There are no logins and no identity sessions anywhere. A session cookie exists only to
hold the CSRF token.

**Admin** is the whole app at its own URLs. **Clients** each get one unguessable
`c.php?k=<32-hex-token>` link showing a nav strip of just their assigned sheets — no
link to the admin portal, no other client's sheets, `noindex, nofollow` throughout.

Per client, per sheet: no access / view only / can edit. Per link: 24 hours, 7 days,
30 days, 90 days, or no expiry. Admin can rotate the token (new URL, old one dead) or
revoke it. An optional **data scope** — brand (FABO / Pristino / both) and business
unit (B2B / B2C / both) — filters rows on every sheet, enforced on read, write and
export. Rows marked `Both`, or left blank, stay visible to either scope.

When a link expires the URL still resolves and shows a **Request access** form. The
request lands on the admin's Clients page, where you can **Reopen** for a new duration
— the same URL starts working again, with nothing to re-send — **Dismiss**, or
**Revoke**.

Authorisation is re-checked in the page controller *and* in every JSON, save and
export endpoint. The Lists sheet is admin-only and is never offered to a client. The
Dashboard is read-only for everyone.

## Computed columns

These are computed on read and never stored, never typed:

- **Days to Deadline** — Due Date − today; `-` once Completed or Cancelled.
- **RAG Status** — green on track, amber within 3 days, red past due, `Done` when
  completed. The cell is coloured the way the workbook colours it.
- **Month / Quarter / Week Number / Year** — from the sheet's driving date (Due Date
  on the Master Log; the workbook's own choice on each function sheet). Week Number
  reproduces Excel's `WEEKNUM(date, 1)`.
- **Budget Remaining**, **Cost per Lead**, **CTR %**, **Engagement Rate**,
  **Budget Utilization**, **ROI %** — each division guards against zero and returns
  blank or 0%, never an error.
- **Blended ROI %** for any group is `(Σ Revenue − Σ Spend) ÷ Σ Spend` — spend-weighted,
  never the average of row ROIs.
- **Master Log** budget / spend / leads / revenue / ROI resolve by joining Linked
  Detail ID against DM Campaigns, DM Influencers or BTL Activities, the SQL equivalent
  of the workbook's `INDEX/MATCH` on Function + Linked Detail ID. They stay blank on
  Content, Website and Design rows. BTL rows resolve budget, spend and leads only —
  the BTL sheet carries no revenue column, exactly as in the workbook.
- **Store Tracker** roll-ups match each store's Match Keyword inside the free-text
  location fields of BTL Activities, Design Tracker and DM Campaigns, filtered to the
  same brand. Adding a store is adding a row with a distinct keyword — no code change.

"Completed in period" counts use **Completion Date**, not Due Date, in every dashboard
block: a task can be due in one period and completed in another. Everything else in a
period (activities due, budget, leads) buckets on Due Date.

## What is dynamic

Nothing that is data is hardcoded. All of these come from the **Lists** sheet at
runtime and pick up a new row with no code change:

- **Brands** — every dashboard block, tile row, monthly / quarterly / annual column
  group, chart series, store tile, client scope dropdown and export tab. Add
  `Brand = Aurora` to Lists and a third brand appears everywhere. (`Both` is a row
  marker meaning "belongs to every brand", never a brand of its own.)
- **Business units** — client scope dropdowns and row filtering.
- **Functions** and **Statuses** — dashboard tables and charts, in Lists order.
- **States** — the Store Tracker's By State table and chart show every active State
  value. To hide `Pan-India` / `Other` there, set their `Active` to 0 on the Lists
  sheet; nothing else changes.
- **Store types** — summary tiles, By Store Type, the spend-split pie, and which type
  the Launch Readiness table lists (the first store type in Lists is "new").
- **Store roll-ups** — matched on each store's own Match Keyword.
- **Columns** — `lib/sheets.php` drives grids, schema, live-sync and export.

The workbook's *rules* — which statuses close a task, which Functions carry a
Linked Detail ID, the three launch-readiness checks, the RAG thresholds — are formulas,
not data. They are declared once in `lib/rules.php` so a change is one edit in one
place rather than a hunt for strings.

## Colour coding

One tone per meaning, used identically everywhere — grid cells, pills, dashboard tables,
the calendar — with a dot beside each label so meaning never rests on colour alone:

| Tone | Meaning | Examples |
|---|---|---|
| green | on track / done | Completed, Published/Live, Approved, Live, On Track |
| blue | in progress | In Progress, In Review, Launching Soon, Medium priority |
| amber | attention / due soon | On Hold, High priority, due within 3 days, Heavy load |
| red | overdue / at risk | Delayed, Urgent, overdue, At Risk, Overloaded |
| grey | not started / closed | Not Started, Cancelled, Closed, Low priority |

The value → tone mapping is declared once in `lib/rules.php` (`fabo_tone_map()`). A grid
shows a colour key above it whenever it has a toned column.

## Events and the Marketing Creative Calendar

`Events` is a sheet like any other (assignable to clients, exported, scoped). Beneath its
grid sits the **Marketing Creative Calendar**: a month view laying every dated marketing
moment on one page — events (spanning their dates), creative-asset due dates, festivals
from the Social Media Calendar, content going live, campaigns starting and design due
dates — each colour-keyed by source with a filter, plus a "Next 60 days" list that flags
late creative. A client sees only sources from sheets they hold.

## Team members, assigning work, and monitoring

**Team members** are created on `admin/team.php`. Each gets a personal link
(`c.php?k=…`, no expiry), the sheets they may work in (view / edit), an email to send
the link to, and an **Owner / Team value** — the same value the Owner dropdowns use.
There is no password: the link is the login, exactly as for clients, and every edit they
make is audited under their name.

**Assigning work** is setting a row's Owner to that person's value, on any sheet. Nothing
else to maintain. Their portal opens on **My Work**: every row on their sheets whose Owner
is them, with status, due date, days left, and a jump to the sheet.

**Monitoring**: the Team page shows, per member, assigned / completed / open / overdue /
due in 7 days and a progress bar, plus a workload table across every owner named anywhere
(with balance against the team average) and two charts. A member's own "My Work" counts
only the sheets they hold; the admin's view counts every sheet — so the admin number can
be higher.

**Sending a link**: on a client's or member's page, *Send by email* opens your mail app
with the link and a message filled in (needs an email on the record), *Send on WhatsApp*
opens a prefilled chat, and *Copy message* copies the text for anything else. Nothing is
sent from the server, so no mail configuration is needed.

## Files and approvals (Content & Design)

Both sheets carry three live columns — **Content / Design Files**, **Approval** and
**Approval Remarks** — and two buttons on every row:

- **📎 Files** — drag a file in or click to choose. Images preview as thumbnails; the
  count shows on the button and in the Files column. Allowed: images, PDF, Office files,
  txt/csv, mp4/mov, zip, up to 25 MB. Anything else, including `.php`, is refused, and a
  file claiming to be an image but isn't is refused too.
- **✓ Review** — Approve, Reject, or send back to Pending, with the full decision history.

**Rejecting requires remarks.** The rule is enforced in `lib/approvals.php`, not just in
the form, so it holds however the endpoint is called — a blank or whitespace-only reason
is refused. Rejected rows turn red in the grid with the reason beside them.

Files are stored under `uploads/` with random names and served only through
`download.php`, which re-checks the viewer's access to that row's sheet and forces a
download for anything that is not a plain image. The folder carries a deny rule as well.

## People, images and access emails

Clients and team members both take an **image** (logo or photo), shown on their page and
in the lists. When you **assign or change someone's sheets**, the portal immediately:

1. writes a notice to **their dashboard** listing exactly what they can now open, and
2. emails them their link and that same list.

The notice always appears — that never depends on mail working. The email goes through
PHP's `mail()`; a local XAMPP box usually has no mail transport, so the admin is told
plainly ("email not sent — no mail transport is configured") and the **Send by email /
WhatsApp / Copy message** buttons are there to send it manually. Set `MAIL_FROM` and a
working SMTP in `php.ini` to have it send by itself. **Re-send access email** repeats it
any time.

## Campaign performance score (DM Campaigns)

A computed **Performance Score** column grades every campaign against the others on four
metrics — Leads Generated, Cost per Lead (lower is better), Conversions and ROI %. Each
metric ranks the campaigns into quartiles worth 25 / 18 / 12 / 6 points; the total (0–100)
becomes **A ≥ 80 · B ≥ 60 · C ≥ 40 · D**. The four metric cells are coloured by their own
quartile (green / blue / amber / red) so you can see *why* a campaign scored as it did.
Ties share a quartile; a missing metric scores 0. Thresholds live in `lib/rules.php`.

## Team Workload (detail)

`admin/team.php` reads every sheet that names an owner and shows, per person: open
items, overdue, due in 7 days, completed in the last 30 days and how many were on time,
budget managed, and where the work sits. **Balance** compares each person's open load
with the team average: ≥1.5× is *Overloaded*, ≥1.2× *Heavy*, ≤0.5× *Spare capacity*.
Rows with no owner surface as *Needs an owner*.

## Intake forms

`admin/forms.php` creates a shareable link (`form.php?k=<32-hex>`) that adds rows to one
sheet — Store Tracker by default. The form shows only the sheet's input columns, uses the
same dropdowns, can be locked to a brand, and can be closed, given an expiry, rotated or
deleted. Each submission gets the next ID, an audit entry under the form's label, and
counts on the admin page. No login is needed to submit.

## CRM

`admin/crm.php` (sidebar → **CRM**) holds any number of CRMs — admissions, job applications,
a sales pipeline — each a board of records moving through stages. One CRM has six tabs:

- **Board** — a column per stage; drag a card to another column to move it.
- **Table** — search every field, filter by stage, sort by any column, tick rows to move or
  delete many at once.
- **Fields** — the Field Studio. Nineteen types: single line, paragraph, email, phone,
  website, whole number, decimal, currency (₹), percent, rating, date, date & time, time,
  dropdown, single choice, multi-select, yes/no, file upload and auto number
  (`APP-0001`…). Each can be required, unique, and shown in the table, with help text and a
  live preview.
- **Stages** — name, colour, order, and whether a stage means won / lost. Deleting a stage
  moves its records to another first.
- **Import** — upload a .xlsx or .csv (first row = headings, up to 50,000 rows). Every
  column is matched to a field by name, which you can change, send to **Stage**, skip, or
  turn into a new field. Choose a field that recognises records already there (email,
  application no.) and whether a match is skipped or updated. Values are checked against
  each field's type: dates in any common shape (day-first) and Excel dates, ₹ and commas in
  numbers, yes/no words, dropdown values without regard to capitals (unknown ones can be
  added to the list). A bad value is left blank and noted; a missing required value or a
  clash on a unique field skips the row. Every import is listed with its notes per row,
  and **Undo** removes the records it added. **Download the import template** gives an
  Excel file whose headings are exactly the CRM's fields.
- **Settings** — name, what a record is called, which field titles a card, the owner,
  export to .xlsx, delete.

The sidebar has two entries: **CRM Dashboard** (every CRM you can open — totals, conversion,
what is assigned to you, latest activity) and **CRM** (the list, and each CRM's tabs,
which start with that CRM's own **Dashboard**: by stage, added per week, split by any
dropdown field, by person).

**Who owns and who sees what.**

- A CRM belongs to the organisation that made it — your own team when the admin makes it,
  a client's organisation when that client does. **Belongs to** picks the person on that
  organisation's *own team* who runs it (fields, stages, access).
- Every record has an **Assigned to** — someone on the CRM's team. Assigning notifies them.
- Each CRM is shared on its own, as **View only** or **Can edit**, and with **All records** or
  **Only assigned to them** (they then see, edit, import and export only their own records).
  Share from the CRM's **Access** tab, or from a person's page under **CRM access**
  (Team Management, Clients, or a client's My Team).
- Your CRMs can be shared with your team and with clients. A client's CRMs can be shared
  with that client's own team only — never outside their organisation, enforced on every
  read, not just in the forms.
- A client's head creates and runs their own CRMs once given **CRM — their own** = *Can edit*
  in Clients → Sheet and module access.

Records are stored as JSON in `crm_records`, keyed by each field's fixed storage key, so
adding or renaming a field never alters a table. The tables (`crm_*`) create themselves on
first use. Code: `lib/crm.php`, `admin/crm.php`, `crm.php`, `assets/crm.css`, `assets/crm.js`.

## Incubation Management

`admin/incubation.php` (sidebar → **Incubation Management**, administrator only) is a CRM of its own
for startup applications, evaluation and incubation. It is **not** a board of the CRM above:
it has its own tables (`inc_*`), pipelines, assignments, activity and reports, and never reads
or writes `crm_*`. It shares only sign-in, team members, the notification centre, mail
delivery (the outbox) and file storage with the rest of the portal.

**Forms** (Settings → Forms). A form's purpose — walk-in, incubation, program, event, startup
referral, partner referral, other — decides the fields it starts with. Fields (short/long
text, email, phone, number, date, dropdown, multi-select, radio, checkboxes, yes/no, URL,
file, section heading) are added, edited, reordered, required or not, activated or not, given
validation and one visibility condition ("show only when *field* equals / is one of / is
answered…"; on a section heading it governs the whole section). Edits happen on a draft
version; publishing freezes it. Every field has a stable uid, and answers are stored by uid
with the label the applicant saw, so renames and new versions never detach old answers.
A form is closed to the public until its link is opened; staff can always enter an
application from **Applications → New application**. The public page is
`incubation-apply.php?f=<token>` (throttled, CSRF, honeypot, one-time ticket).

**Applications** enter at the first pipeline stage with a reference (`INC-2026-00042`). An
email/phone/startup-name match within the look-back window is flagged as a possible
duplicate for review; a form can refuse a second live application from the same email.
An application whose startup already exists is *suggested* the match and linked only when
someone confirms it — no duplicate startup is created.

**Four separate states**, never moved by one another: the application's **pipeline stage**
(12 configurable defaults), the startup's **development stage** (Idea, Validation,
Prototype/MVP, Early Market, Growth — changes are kept with who, when and why), the
**evaluation status**, and the workspace's **incubation lifecycle stage** (11 configurable
defaults). Stages can be added, renamed, reordered, deactivated, limited to certain next
stages, and made to require a reason, an approved evaluation or a workspace.

**Evaluation.** Nine pillars with default weights 14/14/14/14/9/9/9/9/8 (Problem & Solution,
Product Development, Market Opportunity, Team & Execution, Business Model, Financial
Readiness, Traction & Validation, Growth & Impact, **Uniqueness & Differentiation**). The
questions asked are those mapped to the startup's development stage; each pillar shows what
is expected at that stage. Each answer is Rated 1–5, N/A (only where allowed), Pending or
Insufficient Evidence — only Rated answers carry a number. Pillar rating = mean of its rated
answers. **Final Score = Σ(pillar rating × weight) ÷ Σ(weight of rated pillars) × 20**; N/A
pillars leave the denominator, and nothing missing is ever scored zero. The engine is one
pure function, `inc_score_calculate()` in `lib/inc/scoring.php`. An evaluation can be
submitted only when the template's completion rules hold (coverage, required questions,
every applicable pillar, evidence); otherwise it shows **Evaluation Incomplete**. Draft →
In Progress → Submitted → Under Review → Approved, or Returned with a note. Approval freezes
the result (`result_json`, template version, calculation version). Templates are versioned:
a published one is read-only, a new version is a draft copy, and publishing never
recalculates an approved evaluation. A published template without the ninth pillar stays as
it is; a nine-pillar draft is prepared for review.

**Screening** shows a card per application (official score only when approved; uniqueness
from that saved evaluation). Filters run in SQL (form, purpose, source, dates, stages, spoke,
sector, team, owner, evaluation status, score and uniqueness ranges, any form answer). Bulk
actions — change stage, set owner, assign for incubation, create evaluation tasks, shortlist
against your own criteria (saved as presets), export to Excel/PDF, screening report — work on
ticked rows (kept across pages) or on everything matching, need a confirmation, and record
what was done, skipped (with the reason) and failed.

**Assignment.** The first assignment creates exactly one workspace at "Assigned Application";
reassignment reuses it, changes only team and owner, keeps the lifecycle stage and all
history, and records a "Reassigned Application" event. The owner must be a member or lead of
the team and the team must cover the application's spoke. The application row is locked
during the decision, the database refuses a second workspace per application or per open
startup, and a repeated request (same key) does nothing. Notices go out after the commit, once
per event per person, by email and in the portal; a failed email is logged and can be retried
(Settings → Notifications, or the workspace) without touching the assignment.

Code: `lib/inc/` (schema, core, forms, scoring, eval, apps, assign, screen, reports, render,
app, nav, `pages/`), `admin/incubation.php`, `incubation-apply.php`, `assets/inc.css`,
`assets/inc.js`. Schema reference: `database/incubation_schema.sql` (the portal installs it
itself). Tests: `tests/incubation/inc_cli_test.php <scratch-app-dir>` and
`tests/incubation/inc_http.sh` — throwaway copy and database only; the CLI test drops and
recreates every `inc_*` table.

## HR Management

**HR Management** in the sidebar opens HR. While an HR page is open, its desks are listed under
it in groups, each showing only what the viewer may open, with a count of what is waiting:

| Group | Desks |
|---|---|
| (top) | My HR · Dashboard |
| People | Employees · Org chart · Recruitment · Onboarding & exits |
| Time | Attendance · Leave · Holidays |
| Pay | Payroll · Expense claims |
| Growth | Performance · Training |
| Workplace | Assets · Helpdesk & notices |
| Insights & setup | Reports · HR setup · Access control |

The **Dashboard** opens with *Needs your attention* — one list, across every module, of what is
waiting on the viewer (leave and claims to approve, resignations to review, onboarding tasks due,
reviews, interview feedback, open queries, overdue asset returns, probations ending) — then quick
actions, today's attendance, the workforce, and the trends. **My HR** is the employee's own home
with the same list for them, one-tap shortcuts, notices, and their leave, pay, claims and assets.
Pages carry a closed *How this works* note for whoever needs it.

**Setting it up.** No import is needed: the `hr_*` tables create themselves the first time an HR
page is opened. `database/hr_schema.sql` holds the same tables if you prefer to create them by hand
(`database/hr_update_2026-10-20.sql` for an existing install). Nothing is pre-filled. **HR setup**
lists every configuration area in order, each marked Done, To do or Optional:

1. departments and designations (Organisation Structure)
2. a shift marked as the default (Shifts & Work Calendars)
3. a holiday calendar and its holidays
4. leave types, a leave policy with your own entitlements, and the approval steps (Policies & Rules)
5. the two payroll rules that payroll will not guess — how payable days are counted and how unpaid
   days are deducted — and the salary components (Policies & Rules → Payroll)
6. optional: onboarding and exit checklists, claim categories and limits, asset, helpdesk and
   training categories, review rules, and who can use HR

Each setting lives in exactly one place: the old *Sheet Management* is now **Policies & Rules**,
*Departments & Designations* is **Organisation Structure**, *Work Schedules & Shifts* is **Shifts &
Work Calendars**, and *HR Settings & Access Control* is **Access Control**. Old links still work.

For several companies, the administrator switches with the **Company** selector at the top of
every HR page. Each client organisation's HR data is separate.

**Access.** Only the administrator can open HR to begin with. Under **Access Control** you create
roles. Each role has:

- a grid of module × action permissions: View, Create, Edit, Approve, Delete, Export, Configure,
  Process Payroll
- a coverage: *their own record*, *their team* (everyone reporting to them, at any depth), or
  *the whole organisation*

You then add people (their existing portal sign-in accounts). A role grants nothing until
someone is added to it. Templates (Employee self-service, Manager, HR staff, Payroll officer)
only pre-tick boxes.

A few limits apply whatever is ticked:

- An own-record role can never approve, configure or run payroll.
- A team role can never change organisation-wide setup.
- Access control itself is administrator-only.

Roles made before Recruitment, Onboarding & Exits, Performance, Training, Assets, Expense Claims and
the Helpdesk existed do not include them; Access Control says so and offers one button that adds each
template's permissions for those modules to the roles made from it (it only adds).

Self-service and team access need the person's account to be linked on their employee record.
People with a role open `hr.php` (signed-in sessions only, never `?k=` links). Every page, action
and download re-checks the organisation, the permission and the specific employee, so changing
an ID in the address gives a 404.

**Employee dashboards.** When adding an employee, the administrator can tick **Give this
employee their own HR dashboard**. This also works later, from the employee's profile.

It creates the employee's sign-in, links it to their record, and gives them the *Employee
self-service* role, created on first use. Their user ID and a one-off password are emailed; if
mail cannot go, the password is shown once on screen.

On their dashboard (My HR) they can:

- apply for leave and follow each approval
- see the leave left this year and what was taken and credited this month, with a month-by-month table
- check attendance, holidays and payslips

Someone whose only access is HR lands on My HR when they sign in.

**Leave approval steps.** Set these in HR setup → Policies & Rules → Leave → Leave approval steps.

- **Who approves:** up to five steps, each one of:
  - the reporting manager
  - the manager's manager
  - a named person
  - anyone in HR
  - the administrator
- **Per leave type:** a default applies to every leave type, and any type can have its own steps.
- **When steps are fixed:** a request keeps the steps it was submitted with.
- **What's skipped:** steps that don't apply (no manager, or the employee approving themselves).
- **When leave is granted:** only after the last step. A rejection at any step ends the request.
- **Administrator override:** the administrator can give the final approval at any step, unless
  that is switched off in Leave rules.

**Email.** HR emails go through the portal's mail queue, so a busy mail server delays them rather
than losing them. Emails go to:

- the employee: when a request is submitted, after each approval step, and when it is approved,
  rejected, cancelled or corrected; also for attendance corrections, balance changes, payroll and
  payslips, and reminders
- each approver, when it is their turn
- the administrator (`ADMIN_INBOX_EMAIL`), when a step falls to them

**Biometric machine.** In Attendance → Import → *Import the biometric machine report*, upload
the machine's **First/Last Access Records Report** exactly as exported. Accepted formats are
`.xls` (read by `lib/hr/xls.php`), `.xlsx` and `.csv`. Columns are found by their headings.

- **Preview first.** Nothing is saved until you confirm the preview, which shows the dates, the
  rows and who matched.
- **Matching.** People are matched by the employee's **Biometric ID** (a field on the employee
  record), then by Employee ID.
- **Unknown Person IDs.** Each one can be added as a new employee (department and position are
  added if missing), linked to an existing employee (same names are suggested), or skipped.
  Linking saves the ID, so they match automatically next time.
- **Punches.** First-In and Last-Out become check-in and check-out, to the second, and are judged
  against the employee's shift.
  - Two taps less than 10 minutes apart count as one check-in.
  - A single punch shows as *Missing check-in / check-out*.
  - A day with no punch is not recorded, so leave, holidays and weekly offs still show and other
    days read as absent.
- **Importing again.** The same report can be imported again later in the day. It updates days
  that came from the machine and leaves days entered or corrected by hand alone, unless you tick
  the box to replace them.
- **No shift with the machine's hours.** The preview offers to create one from the machine's
  timetable: 15 minutes grace, a full day is the shift less one hour, Sundays off. Set Saturdays
  on the shift if the office is closed then.

The **First/Last Access Records Report** under Reports (also *Export (machine format)* on the
daily register and monthly calendar) exports attendance in the machine's own columns: No., Person
ID, Name, Department, Position, Gender, Date, Day Of Week, Timetable, First-In, Last-Out, with "-"
where there was no punch. It comes as Excel, CSV or PDF.

**How the numbers behave.**

- **Leave ledger.** Leave balances are a ledger (`hr_leave_ledger`). Approval posts the days once:
  the database refuses a second posting for the same request. Rejection posts nothing.
  Cancelling or correcting an approved request returns the old days once.
- **Accruals.** Credits are posted per month or per year as they fall due, with the rule in force
  at that time. A policy change therefore affects future credits only.
- **Day counts.** Leave days leave out weekly offs and holidays unless the policy says otherwise.
- **Attendance status.** Status is worked out from check-in/out against the employee's shift.
  This includes overnight shifts, grace periods and half-day thresholds. Days without a record
  are read from approved leave, holidays and weekly offs.
- **Payroll** moves through Draft → Calculated → Reviewed → Approved → Finalized → Payslips.
  - Every line keeps the basis it was calculated on.
  - Finalizing locks the month's attendance. Leave, holidays and attendance in that month then
    refuse to change, and later corrections go into a later month as adjustments.
  - Payment status is recorded for tracking only: no money is sent.
- **Exports.** Payslips are PDFs and reports download as Excel, CSV or PDF. All are built on
  request and served through `hr.php?p=file` after the same checks.
- **Reminders.** `cron.php` sends HR reminders: leave starting tomorrow, holidays in the next three
  days, and on Mondays last week's unmarked attendance. Each is sent once.

### People, growth and workplace modules

- **Recruitment.** Job openings (department, hiring manager, positions, fill-by date), each with a
  board of candidates: Applied → Screening → Interview → Offer → Hired. Interviews are booked with an
  employee as interviewer, who is notified and records feedback from their own page. At the offer
  stage an **offer letter** PDF is generated. **Hire** creates the employee record from the
  candidate and the opening, marks the opening filled when every position is taken, and starts
  onboarding. A Manager role sees the openings they (or their team) are hiring for.
- **Onboarding & Exits.** Checklists of tasks, each owned by HR, IT, admin, finance, the manager or
  the employee and due a number of days from the joining date or last working day (one click adds
  a standard joiner and leaver list). Adding an employee starts the default onboarding checklist.
  An employee resigns from My HR (or HR records a resignation, termination, retirement…); the
  manager or HR accepts it and agrees the last day, which puts the employee *On notice* and starts
  the exit checklist. HR records the exit interview and full & final settlement, downloads the
  **relieving** and **experience** letters, and **completes** the exit — exactly like Deactivate,
  every record kept — which waits until company assets are returned. An exit can be withdrawn.
- **Performance.** HR starts a review cycle for everyone (or a department); the reviewer is each
  person's reporting manager. Employee and manager agree weighted goals; the employee rates
  themselves, then the manager rates; the final rating is the weighted manager rating unless HR
  calibrates it, with a reason. A distribution of ratings shows per cycle.
- **Training.** Sessions with dates, trainer, mode and places; HR enrols people (one by one or a
  department), or employees enrol themselves when the session is open. HR records completion and
  scores; attendees rate the session. Each employee has a training record.
- **Assets.** A register (tag, category, serial, cost, warranty). Issuing to an employee and taking
  back are recorded, so the history of who held what is never lost.
- **Expense claims.** An employee claims with a receipt; category limits, the receipt threshold and
  how old an expense may be are rules in Claims → Categories & rules. The manager (or HR only, as
  set) approves — a lower amount needs a reason — and it is paid directly or **with salary**, which
  adds a reimbursement adjustment to that month's payroll.
- **Helpdesk & announcements.** Notices for everyone or one department, pinned or dated. Queries to
  HR with a conversation, priority, status and internal notes; a query is seen only by the person
  who raised it and by HR — never by their manager.
- **Org chart.** The reporting tree from each employee's manager, with team sizes and a department
  filter.

Each has its reports under Reports (headcount and attrition, recruitment pipeline, exits, claims,
assets, training, performance ratings), and `cron.php` also reminds interviewers and trainees the
day before and the owner a week before a probation ends.

**Not included.** You must verify statutory rates (PF, ESI, Professional Tax, TDS) for your
company. They are ordinary components with the rates you enter; no tax is computed. No biometric
device is connected: device exports can be imported through Attendance → Import. Emails go out
only when the portal's SMTP is configured; in-app notifications always do.

Tests: `tests/hr/hr_cli_test.php` and `tests/hr/hr_modules_test.php` (library) and `tests/hr/hr_http.sh` (HTTP, by role). Run them on
a throwaway copy, as with `tests/security/`.

## Editing dropdowns and column names — inside the sheet

Nothing leaves the grid. As admin, on any sheet:

- **Rename a column** — click its header, type, press Enter. Esc cancels; an empty
  box resets to the shipped default. A dot after the name marks a renamed column.
  The new name flows through the grid, live sync, history panel, intake forms and export.
- **Add an option** — open any dropdown cell and pick **＋ Add option…** at the bottom;
  type the value and it is added to that list, selected on the row and saved, all at once.
- **Remove an option** — open any dropdown cell and pick **－ Remove / edit options…**;
  the side panel opens for that list with a **Remove** button on every option (and
  rename, reorder, hide).
- **Manage options** — the ⚙ on a dropdown column's header opens a side panel where you
  add, rename (edit and press Enter), reorder (↑ ↓), hide/show or remove options. Every
  dropdown of that list on the page repaints immediately.

*Hide* stops offering a value but keeps it on rows that have it; *Remove* deletes it from
the list only. No data row is ever altered. Clients never see these tools.

## The Lists sheet

Every select cell reads the **Lists** sheet (`List Name | Value | Sort Order | Active`).
Adding a value there makes it appear in the matching dropdown with no code change. A
value already stored on a row stays selectable even if it is later removed from the
list, so existing data is never silently altered.

## Export

One .xlsx with a tab per sheet — Dashboard first, then the sheets in the order they
appear in the nav — each carrying exactly its columns, in order, with the same headers.
Numbers stay numeric (with rupee and percent formats), dates are real Excel dates, the
header row is bold, and text beginning `= + - @` is prefixed with an apostrophe.
Tab names are trimmed to 31 characters with `[]:*?/\` stripped and duplicates suffixed.

A client's export covers only their assigned sheets, scoped to their brand and business
unit, and is refused once their link has expired.

## Changing a column

Edit `lib/sheets.php` — it defines every sheet's columns and drives the grids, the
schema, the live-sync payload and the export. Then regenerate the shipped schema:

```sh
php database/gen_schema.php > database/schema.sql
```

Existing tables are not altered by the self-installer, so add the column by hand (or
drop and re-import) when changing an installed database.

## Notes on this build

- Each table also carries an internal `id`, `created_at` and `updated_at`. These never
  appear in a grid, a dashboard table or an export.
- `activity_audit` records one row per cell edit and is what the per-row history panel
  shows.
- The Dashboard's Weekly block runs Monday-to-Sunday weeks, matching the week rows in
  the workbook. The Week Number *column* uses Excel's Sunday-based `WEEKNUM(d, 1)`,
  matching that formula. The workbook is internally inconsistent here; both behaviours
  were kept as the workbook has them.
- Chart colours are a validated categorical palette (blue / orange / aqua, then the
  remaining slots in fixed order). The order is the colourblind-safety mechanism, so
  re-order it only after re-validating.
