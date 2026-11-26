# Academic and the Campus portal

Institutions, departments, students, faculty, mentors and institution admins for a client organisation, plus the Campus portal those people sign in to. For whoever maintains or deploys the portal.

Phases 1 to 5 of the LMS / student / incubation product are live (Phase 5: data protection and Campus security). Earlier summary:
- Phase 1: institutions, people, roles and the Campus portal.
- Phase 2: courses (the LMS) and mentoring sessions.

Gamification (XP, coins, rankings, store), the institution and government portals, and the student-to-incubation link come in later phases.

## Lesson content blocks

| Part | File |
|---|---|
| Blocks: add, save, place, reorder, delete, convert old lessons, rules, rendering | `lib/edu/blocks.php` |
| Desk: block editor, block actions (`block-add`, `block-save`, `block-delete`, `block-order`) | `lib/edu/app_blocks.php` |
| Behaviour: autosave, add in place, drag order, files into the stack | `assets/academic-builder.js` |

`edu_blocks_install()` (`app_settings.edu_blocks_schema`, 7) creates `edu_blocks`. `edu_lms_install()` calls it.

**What a lesson holds.**
- A lesson is a stack of blocks, in any mix and order: **text**, **video** (YouTube or Vimeo), **document** (an `edu_files` row: PDF, slides, sheet, image, MP4…), **link**, and **callout** (a tip, note or warning).
- A quiz can carry blocks above its questions.
- The lesson "type" now only sets its icon and starting block: Reading starts with a text block, Video with a video block, Link with a link block, and Document waits for files.

**Editing.**
- Each block is its own form. It saves 0.9 s after typing stops ("Saving… / Saved"), and without JavaScript it has a plain Save button.
- Blocks are added from the bar under the content, or from the "+" under any block (added right below it).
- Dropping files on the content area, or on its drop zone, makes one document block per file. Files are sent in pieces, as in the builder.
- Blocks are reordered by dragging the handle (only the handle starts a drag, so text inside stays selectable) or with the up and down buttons.

**Old lessons.** A lesson made before blocks is shown with its old body, url and files until staff open it in the builder. Opening it converts that content to blocks once (video or link, then text, then files); the old columns are left in place.

**Publishing.** `edu_lesson_problem()` is now the single rule used by both the checklist and publishing:
- A quiz needs questions.
- A video or link block needs its address.
- A document block needs its file.
- A lesson with no content at all can't be published.

**Students** see `edu_lesson_render()`: each block in order, with videos embedded, PDFs in the viewer, images and MP4 in the page, Office files as download cards, and links as cards.

**Removing.** Deleting a document block deletes its file. Deleting a lesson or course deletes its blocks.

**Tests.** The self-test has a "content blocks" section. `cp_http7.sh` (scratchpad) runs the block editor over HTTP, both as the page does it (JSON) and with plain forms, then checks the student view and its order.

## Course builder v2 and documents

| Part | File |
|---|---|
| Files, cover, sections, outcomes, checklist, file sending | `lib/edu/files.php` |
| Desk: gallery, builder (Curriculum, Details, Students & results), the `file` route | `lib/edu/app_lms.php` |
| Desk behaviour | `assets/academic-builder.js` (loaded on courses and course pages only) |
| Portal: covers, "What you will learn", sections, the document viewer, the `v=file` route | `lib/edu/portal_lms.php` |

`edu_builder_install()` (`app_settings.edu_builder_schema`, 6) adds:
- `edu_files` (role `lesson` or `cover`)
- `edu_lessons.section`, and `document` as a lesson kind
- `edu_courses.outcomes` and `edu_courses.prerequisites`

`edu_lms_install()` calls it, so every caller gets it.

**Builder.**
- The courses gallery has search, a status filter and a "New course" dialog.
- The builder hero shows the cover or a colour, stats, a readiness ring (`edu_course_checklist()`) and a Publish button that stays disabled until nothing blocks it.
- The Curriculum tab:
  - shows lessons grouped by section; dragging reorders them, including between sections (`lesson-order`, JSON);
  - adds lessons through a dialog with type tiles (Reading, Video, Document, Link, Quiz);
  - opens the lesson editor beside the outline: formatting toolbar with live preview (`preview-md`, rendered by the server's own `edu_render_text()`), live YouTube or Vimeo preview, a drop zone, a card-based quiz builder with add or remove answer, a student view, an unsaved-changes warning and Ctrl+S.
- The Details tab holds the outcomes, prerequisites, cover upload with preview, colour, audience, years and pass mark.
- The page still works without JavaScript, through plain forms.

**Documents.**
- Accepted types: PDF, PowerPoint, Word, Excel, CSV, TXT, ZIP, MP4/MOV and JPG/PNG/GIF/WebP. A lesson takes up to 30 files.
- Files are stored by `fabo_store_upload()` under `uploads/`, with a random name, never executable and never linked directly.
- The browser sends files in pieces of at most 8 MB (`fabo_upload_take_chunk`), so a large deck gets past the host's 32 MB per-request limit.
- **Serving** is through `edu_file_send()` only, with byte ranges.
  - PDF, images and MP4 open inline; everything else downloads.
  - `frame-ancestors 'self'` on the response.
  - The database connection is released before streaming.
- **Access:**
  - Staff with the Academic grant use `academic.php?p=file&id=`.
  - In Campus (`v=file&id=`), a student sees lesson files of courses they are enrolled on, and covers of courses open to them. No other role sees course files.
- A document lesson needs at least one file before the course can be published.
- Removing a lesson or course deletes its files from disk.
- In the lesson, students see PDFs in the browser's PDF viewer, then images and video, then download cards. PowerPoint and Word can't be previewed in the browser without sending them to Microsoft or Google, so they download.

**Testing.**
- `tests/edu_selftest.php` covers this section, using the "assembled" upload route.
- The HTTP suite `cp_http6.sh` (scratchpad) signs in as staff (the seed gives the test client a password), uploads single and chunked files, and checks access as staff, enrolled and non-enrolled students, and with no session.

## Phase 5: data protection and Campus security

| Part | File |
|---|---|
| Consent, guardian consent, rights, logs, two-step sign-in, sessions, password rules | `lib/edu/privacy.php` |
| Desk: Privacy & security (Requests, Privacy notice & consent, Security, Access log, Retention review) | `lib/edu/app_privacy.php` |
| Portal: sign-in code, consent and guardian gates, public notice, "Your data" | `lib/edu/portal_privacy.php` |

`edu_privacy_install()` (`app_settings.edu_priv_schema`, 5) creates `edu_consents`, `edu_requests`, `edu_access_log`, `edu_security_log` and `edu_login_codes`.

This was written with the DPDP Act, 2023 in mind. It is not legal advice: the organisation's counsel should approve the notice.

**Consent.**
- Every Campus user accepts the organisation's notice (`edu_consent_gate()`). A default plain-language template is provided, and staff can edit it along with the grievance contact.
- Changing either one gives the notice a new version, and everyone accepts again at their next visit.
- A student who says they are under 18 names a parent or guardian. The guardian gets an email link (14 days, single use) and agrees or refuses.
- Until the guardian agrees, the portal stays closed to that student. This is switchable under Privacy notice & consent, and on by default.
- A guardian who already agreed is not asked again when only the notice changed.

**Rights.**
- In Campus, the profile's "Your data" section offers "Download my data" (JSON, with no password hashes), a correction request, and "Withdraw consent and erase my account".
- Staff act on requests under Requests and must give a reason to decline. The person is emailed either way.
- Erasing runs `edu_person_delete()`, which removes courses, rewards, sessions, ideas, consents and codes. Only a masked line ("N… <ab••@domain>") stays as the record that it was done.
- An application already sent to Incubation stays with the incubation team, and the email says so.

**Logs.**
- `edu_access_log` records each time staff open a person or export people or a report, and each page an institution admin or council member opens in Campus.
- `edu_security_log` records sign-ins, failures, codes, timeouts, password changes, consent, exports, requests and erasures.
- `edu_privacy_trim()` (run when the Privacy page is opened) keeps the security log for a year and the access log for three years.
- The retention review lists suspended or unused accounts. Nothing is deleted automatically.

**Security.**
- **Two-step sign-in:** a 6-digit code by email, valid 10 minutes, five tries, sends throttled. It applies to institution admins and council by default; the setting can be Everyone or Off.
  - Sample `.example` addresses are never asked for a code, because they can't receive mail.
  - Setting a password from an emailed sign-in link counts as proof of the email, so no code is asked then.
- **Session limits:** for admins and council, 30 minutes idle or 12 hours at most; for everyone else, 8 hours idle or 7 days at most.
- **Passwords:** at least 10 characters, not common, not one repeated character, and not containing the person's first name or the name part of their email.
- **Password changes** are announced by email.

**Testing.** The HTTP suites need the no-mail prepend file (see Phase 4). The shared seed records consent and turns codes off, so the older suites sign straight in. The Phase 5 suite turns both back on and uses non-sample addresses (`*.test`), whose mail the prepend file logs.

## Phase 4: ideas, incubation, reports, council

| Part | File |
|---|---|
| Ideas, incubation bridge, council role | `lib/edu/ideas.php` |
| Per-institution report, periods, CSV | `lib/edu/reports.php` |
| Desk: Ideas & incubation, Reports | `lib/edu/app_p4.php` |
| Portal: student Ideas and Apply; Ideas and Reports for institution admins; council Overview, Reports and Rankings | `lib/edu/portal_p4.php` |

`edu_ideas_install()` (`app_settings.edu_p4_schema`, 4) creates `edu_ideas` and adds `council` to `edu_roles.role`.

**Student to incubation.**
- A student writes an idea (problem, solution, sector, stage, team).
- Staff pick the incubation form students apply with (Ideas & incubation). It must be published and its public link open.
- The Apply page draws that real form with `inc_render_fields()` and fills it in with `edu_inc_prefill()`. Mapped fields come from the profile and the idea. The idea's stage maps to the organisation's development stages. Text questions are matched by label: problem, solution, team, institution, roll number, sector.
- The application is filed with `inc_app_submit()` on the public channel, with a one-time ticket. So it goes into the same pipeline and tables, gets the same validation, duplicate check, staff notice and applicant acknowledgement as the public link.
- The application timeline gets a line saying it came from the Campus portal. The idea stores `inc_app_id` and `inc_ref`, and shows the live pipeline stage from `inc_applications.stage_id`.
- A sent idea can't be edited or removed.
- Rewards: writing an idea pays 25 XP and 5 coins; applying pays 75 XP and 20 coins. The badges are Ideator and Founder in the making.
- The organisation must hold Incubation Management (ticked on Clients). Without it, students can write ideas but not apply.

**Reports.**
- One row per institution for a period: this month, academic year (June–May), last academic year, calendar year, financial year, or custom dates.
- Columns cover people, learning, mentoring, innovation (ideas, applied, past first screening) and recognition, all counted from records.
- "Past first screening" means the application's stage is no longer the first active crm stage.
- Each report has a CSV download (cells that would start an Excel formula are escaped), a total row, column definitions and a print layout.
- The footer says the figures support NAAC Criterion 3, NIRF and IIC returns, and that each return's own metric definition should be checked first.

**Who sees what.**
- Desk: every institution.
- Institution admin: their own institution only (Reports and Ideas tabs).
- Council: the organisation-wide Campus role (institution 0) for a government or council officer. Read-only: Overview, Reports and Rankings, with no store, ideas or learning.
- Mentors see their students' ideas. Faculty see their department's.

**Local HTTP testing.** The local config has real SMTP. Run `php -S` with `-d auto_prepend_file=<file setting $GLOBALS['inc_mail_hook'] and ['edu_mail_hook']>` so nothing is sent.

## Phase 3: rewards

| Part | File |
|---|---|
| Ledger, rules, levels, badges, streaks, kudos, rankings, store, backfill | `lib/edu/game.php` |
| Desk pages: Rewards and Store, plus the rewards card on a student's page | `lib/edu/app_game.php` |
| Portal: Rewards, Rankings and Store tabs, home strip, kudos, top students | `lib/edu/portal_game.php` |

Tables (`edu_game_install()`, version in `app_settings.edu_game_schema`, 3) are `edu_ledger`, `edu_badges`, `edu_store_items` and `edu_orders`, plus `edu_people.board_hidden`.

**Two currencies.** XP is earned and never spent; it decides levels, leagues, badges and rankings. Coins are earned and spent in the store. Spending never lowers XP or rank. Coins can't be bought, sold, moved between students or cashed out, and nothing is random.

**The ledger is append-only.**
- A balance is always `SUM(amount)`. A correction is a new row (`adjust` or `refund`), never an edit.
- Each award has a reference (`lesson:12`, `course:3`, `session:7`, `day:2026-11-18`, `kudos:<giver>:<date>`), and the unique key `(person_id, currency, reason, ref)` means nothing pays twice.
- Only people with a student role earn. Everything stops when Rewards are switched off.

**What pays.**
- Lessons, quizzes (with a bonus for 100%), courses, held sessions and rated sessions pay through hooks in `lms.php` and `mentoring.php`.
- A learning day (any lesson or quiz attempt) counts toward the streak. Every 7th day in a row pays a bonus.
- Coins from automatic awards stop at the daily cap (default 60). XP is never capped.
- Each organisation sets its own amounts under Rewards → Earning rules.

**Levels and leagues.**
- Reaching level L takes `50·L·(L−1)` XP: 0, 100, 300, 600, 1000…
- Leagues by level: Bronze 1–2, Silver 3–4, Gold 5–6, Platinum 7+.
- Badges are checked after each award and kept for good.

**Kudos.** Faculty can give kudos to students in their department or institution, and mentors to their linked students. Each giver has a monthly allowance (default 10), at most once a day per student, and every kudos needs a note.

**Rankings.**
- Student tables cover the department, institution, league or everyone, for this week, this month or all time.
- Names show as "First L." by default, or full names if the organisation chooses. A student can hide themselves (they then appear as "A student").
- Institutions are ranked by average XP per student, which is fair to small colleges.

**Store.**
- Item kinds: digital (given at once), benefit, or physical (staff mark it given or reject it).
- Each item has optional stock, a per-student limit and an institution scope.
- A purchase holds a per-student database lock (`GET_LOCK`), so double clicks can't overspend. Stock is taken with a conditional update.
- A rejected or cancelled order refunds the coins and returns the stock.
- **Add starter items** creates six common rewards, switched off.

**Backfill.** "Pay earlier achievements" pays work done before rewards existed, once, dated when it happened.

## Phase 2: courses and mentoring

| Part | File |
|---|---|
| Courses: tables, rules, lesson text, certificates, sample course | `lib/edu/lms.php` |
| Mentoring sessions | `lib/edu/mentoring.php` |
| Desk pages: Courses, Course, Lesson, Mentoring | `lib/edu/app_lms.php` |
| Portal views: student, mentor, faculty, institution admin | `lib/edu/portal_lms.php` |

Tables (`edu_lms_install()`, version in `app_settings.edu_lms_schema`) are `edu_courses`, `edu_lessons`, `edu_questions`, `edu_enrolments`, `edu_lesson_done`, `edu_attempts` and `edu_slots`.

**Clock:** every Academic time uses PHP's clock (`edu_now()`), never MySQL `NOW()`. On live the database clock is UTC while PHP runs on Asia/Kolkata.

**Courses**
- A course is offered to everyone, to one institution or to one department, and can be narrowed to years of study. Students see only published courses meant for them (`edu_course_for()`).
- A lesson is Reading, Video (YouTube or Vimeo only, embedded in privacy mode), Link (https only) or Quiz (single-answer questions).
- Lesson text is escaped first, then a small markup is applied: `#` headings, `-` lists, `**bold**`, `*italic*` and https links.
- A course can't be published while it has no lessons, a quiz with no questions, or a video or link with no address.

**Progress and quizzes**
- Reading, video and link lessons are marked done by the student. A quiz is done when the student reaches the course's pass mark.
- Every quiz attempt is kept. Answers and explanations are shown after each attempt.
- Finishing every lesson completes the course and issues a 12-character certificate code.
- Anyone can check a certificate at `campus.php?o=<key>&verify=<code>`.
- A lesson added later never takes a finished course away.

**Mentoring**
- Mentors offer slots (one-off, or weekly up to 12 weeks). Overlapping slots are refused.
- Only students linked to that mentor can book, up to 3 upcoming bookings each. Booking is a single conditional update, so two students can't book the same slot.
- A student can cancel up to 2 hours before (the slot opens again). A mentor can cancel any time (the student is emailed).
- After the start time the mentor records the session as held or missed, with private notes. The student can then rate it 1–5.
- Booking and cancellation emails go through `edu_send()`, which never sends to `.example` addresses.

## Where it lives

| Part | File |
|---|---|
| Staff desk | `academic.php` (client and their team), `admin/academic.php` (administrator, picks the organisation) |
| Campus portal | `campus.php` → `lib/edu/portal.php` |
| Tables, rules, import, sample data | `lib/edu/core.php` |
| Desk pages | `lib/edu/app.php` |
| Sidebar | `lib/edu/nav.php` |
| Front end | `assets/academic.css`, `assets/academic.js` |
| Test (local only, never deploy) | `tests/edu_selftest.php` |

Touched outside the module:
- `lib/auth.php`: the `academic` entry in `fabo_modules()`.
- `lib/ui.php`: the sidebar and the `campus` icon.
- `logo.php`: `?edu=<portal key>`.
- `.htaccess`: the no-cache lists.

## Turning it on for a client

1. On Clients, open the organisation and tick **Academic — their own**. To give them Incubation with the same pipeline and tables as everyone else, tick **Incubation Management — their own** too. Its tables `inc_o<id>_*` are made on first open.
2. The head opens Academic, then adds institutions, imports people, or loads the sample data.
3. They share the Campus portal address from Overview or Settings, and send sign-in links from People.

The head can pass Academic to their own team on My Team. A team member never holds more than the head holds now.

## Why Campus people are not `clients` rows

Students, faculty, mentors and institution admins live in `edu_people`, with their own password hash. They are not portal accounts. Thousands of students in `clients` would appear on My Team and in committee digests, IT helpdesk lists and mail sweeps. Campus sessions use `$_SESSION['edu']`, which is separate from `fabo_person`. Being signed in to one never signs you in to the other.

## Database

`edu_install()` creates the tables on first use. The version is recorded in `app_settings.edu_schema`. Every row carries `org_id`.

| Table | What it holds |
|---|---|
| `edu_settings` | Per organisation: `portal_key`, `portal_<role>` switches, `portal_welcome`, `sample_loaded` |
| `edu_institutions` | One row per college, university or polytechnic |
| `edu_departments` | Each department, under an institution |
| `edu_people` | One person, one row. Email is unique per organisation. Holds the password, sign-in link hash and last sign-in |
| `edu_roles` | One row per role: student, faculty, mentor or inst_admin. Tied to an institution (and a department). A mentor's institution may be 0, meaning organisation-wide |
| `edu_mentor_links` | Which mentor has which student |
| `edu_audit` | Changes made on the desk and by institution admins |

## Campus portal

`campus.php?o=<16-hex portal key>`. On an organisation's own verified address, the key is found from the host.

| Feature | Behaviour |
|---|---|
| Sign in | Email and password, per organisation. Throttled by IP (30 in 15 min) and by account (8 in 15 min) |
| Sign-in links | `&invite=<48 hex>`. Only the SHA-256 hash is stored. Lasts 7 days and works once. Setting a password ends it |
| Forgot password | Gives the same answer whether or not the address exists |
| Temporary password | Set from the desk. The person must change it at their next sign-in |
| Password change | Changes the session fingerprint, so other devices are signed out |
| Several roles | A role switcher at the top. A role whose portal is switched off in Settings is hidden |

What each role sees, decided on every request:

| Role | Sees | Can change |
|---|---|---|
| Student | Their own details and mentors | Name and phone |
| Faculty | The students and colleagues in their department, or in the institution when the role has no department | — |
| Mentor | The students linked to them | — |
| Institution admin | Their institution only: numbers, departments, students and faculty | Add students or faculty there, email sign-in links to their own people |

## Import

The CSV header is matched loosely ("Student name", "College", "Branch", "Hall ticket"…). How rows are matched:
- A known email updates that person, and empty cells keep the old values.
- A row with no email matches on roll number.
- Up to 20,000 rows per file.
- The import never sends email.

## Sample data

On Settings, and only for an organisation with no institutions. It loads 3 institutions, 9 departments, 36 students, faculty, admins and 4 mentors.
- Every sample address ends in `.example`. Mail to them is refused in `edu_invite_send()`.
- **Remove sample data** deletes only `.example` people and the three sample institutions.

## Testing

```
php tests/edu_selftest.php          # library, separation, import, access, desk pages (orgs 9911/9912)
php tests/edu_selftest.php seed     # leaves org 9911 with sample data for HTTP tests
php tests/edu_selftest.php clean
```

Local only: the test refuses to run on the live server.
