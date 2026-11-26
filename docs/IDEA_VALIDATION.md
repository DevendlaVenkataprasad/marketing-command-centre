# Startup Discovery

Heads of Department (HoDs) validate their students' ideas with the Gem the owner assigns, and send their top picks into one pipeline. There the owner forms a team for each project.

| Where | Who |
|---|---|
| **Admin → Startup Discovery** (`admin/idea-validation.php`) | You, for our own organisation or any client holding the module (switcher at the top) |
| **Startup Discovery** in the portal sidebar (`idea-validation.php`) | A client granted it on Clients ("Startup Discovery — their own"), and the team members they pass it to. Our own staff get it on Team Management. |
| **HoD dashboard** (`hod.php?o=<key>`) | Each Head of Department, with their own email and password. This is not a staff login. |
| **Idea form** (`idea-form.php?f=<token>`) | Students. No sign-in. One link per department per round. |

## Two roles

**Owner** (the client, or you):
- **HoD dashboards.** Create them one at a time, or upload a sheet. Each row of the sheet holds college code, college, district, department, branch code, HoD name, HoD email and HoD phone. Then send the Heads their sign-in links.
- **Students.** Import them from Incubation Management, upload a .csv or .xlsx, or add them one by one.
- **Rounds.** Set when submission opens and closes, the HoD deadline, the Gem, the AI checks per idea (**2** by default) and the ideas each department sends (**3**). A single department can be given a later deadline from its dashboard page.
- **Per-HoD AI limit.** A HoD can be given their own number of AI checks; 0 switches AI off for them.
- **Pipeline & teams.** This is one board for all departments: Forwarded by HoD → Owner review → Team assigned → In development → Selected / Not taken forward. Open an idea, find students, and add them to its team.
- **Return picks.** The owner can hand a department's picks back to its HoD.

**Head of Department** (sees only their own department):
- **Share the form.** Copy the link, or share it on WhatsApp or by email.
- **Edit the questions.** The owner's (Gem) questions are locked, marked "Set by the owner": they are the only questions on a form. A HoD cannot reword, remove, make optional or add a question, because they are what the AI scores. `iv_form_save()` always rebuilds them from the round's Gem (`iv_form_locked_questions()`) and ignores anything posted for them. Posted question rows are ignored altogether; a HoD changes only the title, the note to students and pausing.
- **Check an idea with AI**, in either of two ways. Both count towards the limit:
  - **In the portal** ("Check this idea now"). Gemini is called with our key. A busy or unreadable reply is not counted.
  - **Your own AI tool** (only when the round allows it). "Copy & open Gemini / ChatGPT" puts the prompt on the clipboard and opens the tool. The HoD pastes the whole reply back; the page reads the marks as they paste and shows the total before they save. Marks cannot be typed in.
- **Decide.** Mark each idea Validated, Not taken or Undecided, with a note. They can do this on the idea page, on the Students page (a Validate button on each row, or tick several and decide them together), or from the student panel.
- **Send the top picks.** Rank up to N validated ideas ("Rank by AI score" fills the ranks in score order) and send them forward. After that, decisions are final.

## Keeping HoDs honest

| Risk | What stops it |
|---|---|
| Typing in high marks | Own-tool checks need the AI's whole reply; marks cannot be typed. The reply is kept, and the owner reads it under the check ("The reply as pasted"). |
| Pasting a made-up or recycled reply | The prompt carries a one-time **check code** (`iv_check_code()`, from the idea and its check count). The reply must return it, so a reply written for another idea, or one saved from an earlier check, is refused. |
| Re-checking until the score suits | The idea's score is the **average** of all its checks, not the latest one. |
| Any own-tool influence at all | Rounds → untick "HoDs may also use their own AI tool". Every score then comes from the portal (`iv_rounds.own_ai`). |
| Sending favourites without evaluation | Every top pick needs at least one AI check (when the round has a Gem and the HoD has checks). |
| Ranking a favourite above stronger ideas | If a pick is ranked above a validated idea that scored higher, the HoD must write why. The reason goes to the owner with each pick (`iv_ideas.pick_note`). |
| Anything else | The owner's idea page has an **Integrity check** card: no AI check, own-tool score only, own-tool and portal scores far apart, validated under 40%, ranked against the scores (with the reason), higher-scoring ideas marked Not taken, and a student who was not on the list but came in through the form. Pipeline cards show "⚑ n to check". **Verify with AI** runs the owner's own portal check. It sits outside the HoD's limit and joins the average. |

## The data, kept unique

| Table | One row per | Unique on |
|---|---|---|
| `iv_colleges` | college or school | (org, name), (org, code) |
| `iv_departments` | department of a college = one HoD dashboard | (college, name), (college, branch) |
| `iv_hods` | Head of Department | department; (org, email) |
| `iv_students` | student | (org, `ukey`): PIN / roll number, else email, else phone |
| `iv_rounds` | idea round | (org, name) |
| `iv_forms` | department form in a round | (round, department), token |
| `iv_ideas` | a student's idea in a round | (round, student) |
| `iv_ai_runs` | AI check | counted against the limit, under a row lock; `reply_text` keeps an own-tool reply; the owner's verify checks are not counted |
| `iv_team` | team member | (round, student): one team per round |
| `iv_dept_windows`, `iv_log`, `iv_audit`, `iv_settings` | | |

How students are matched:
- **By PIN first.** The PIN is normalised, so `24155-cm-001`, `24155CM001` and `24155-CM-001` are one student.
- **By email or phone only as a fallback.** These match only a record with no PIN of its own, because siblings often share a parent's email or phone.
- **Placing them.** A diploma PIN (`YYCCC-BR-NNN`) puts the student in the department whose branch code is `BR` (see `iv_branch_names()`, from the AP SBTET codes). An answer like "155 GOVT POLYTECHNIC, PILLARIPATTU" is read as college code 155 plus its name.
- **Re-running is safe.** Imports and uploads update the existing rows and never add second ones.

The Gem is the organisation's Incubation Gem, edited in place on **Startup Discovery → Gems** (`lib/iv/gempage.php`) or in Incubation Management → Gems; it is never copied. The Gems page edits instructions, rubric and questions, copies a Gem (switched off), switches it on or off, pushes new questions to the department forms (`iv_gem_sync_forms()`), and runs a trial score on a sample idea (`iv_gem_try()`, nothing saved). An organisation without Incubation gets its tables and Gem 1 from "Start with Gem 1". A plain-language guide for owners and HoDs is `docs/Startup-Discovery-Guide.pdf` (source: the `.html` beside it).

## Files

| File | What |
|---|---|
| `lib/iv/core.php` | Schema (`iv_install()`, schema 4), access, colleges, departments, HoDs, students, imports, rounds, forms, ideas, ranking, forwarding, pipeline, teams |
| `lib/iv/gem.php` | Reading the Gem, the prompt, the scoring tally, the portal and own-tool checks, the limit |
| `lib/iv/import.php` | Students from Incubation applications (field mapping) |
| `lib/iv/app.php`, `lib/iv/nav.php` | Owner desk |
| `lib/iv/portal.php` | HoD portal |
| `lib/iv/form.php` | Student idea form |
| `assets/ideas.css`, `assets/ideas.js` | Look and behaviour |
| `tests/iv_selftest.php` | 215 checks, local only, in throwaway orgs 9921 and 9922 (`seed` and `clean` modes for HTTP tests) |

## Granting it

- **To a client:** go to Clients → their row and tick **Startup Discovery — their own** (Can edit / View only). The head can pass it to their team on My Team.
- **To our staff:** go to Team Management → Startup Discovery.
