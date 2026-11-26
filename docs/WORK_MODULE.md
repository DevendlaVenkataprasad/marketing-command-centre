# Work Management

Departments, tasks, and a status history, all inside the admin portal. You give access to team members and clients, and the module produces an Excel report comparing each task's status on two dates.

| Where | Who |
|---|---|
| **Admin → Work Management** (`admin/work.php`) | You, the administrator |
| **Work Management** in the portal sidebar (`work.php`) | Team members and clients you have given access |

There is no separate login. Team members and clients sign in on the portal's usual `login.php`, and the Work menu appears only while their access is active.

## Enabling it

There is nothing to switch on. The `wm_` tables create themselves the first time the module is opened (`wm_install()`, schema version 1). Nothing is seeded, so every list starts empty and shows a "create your first …" prompt.

Tables:
- `wm_departments`
- `wm_members` (who has access, and as which role)
- `wm_user_department_access`
- `wm_client_task_shares`
- `wm_tasks` (soft delete via `deleted_at`)
- `wm_task_status_history`
- `wm_task_comments`
- `wm_work_logs`
- `wm_audit`
- `wm_settings` (holds the schema version only)

## Roles

| Role | Can |
|---|---|
| Admin | Everything: departments, tasks, access, every report, export. |
| Team member | See tasks in their departments. Create and edit tasks, change status and progress, comment, and log time. Cannot manage users, access or departments. |
| Client | Read-only. Sees their departments and the tasks shared with them, and their own report. Optionally "can comment"; their comments are always visible to staff. They never see internal comments, time logs, staff notes on the status history, or anyone else's data. |

The server checks every request against the database (`wm_ctx()` → `wm_task_scope()`):
- A record outside someone's scope answers **404**.
- An action above their role answers **403**.

## Giving access (Admin → Work Management → Access)

1. Click **Invite person** and choose **Team member** or **Client**.
2. Pick an existing portal account, or enter a new person's name and email.
   - A new person gets an ordinary portal account: one of our staff for Team, a client account for Client.
3. Tick their departments. For a client you can also share single tasks and allow comments.
4. They receive an email.
   - **New person**: a one-time link to choose a password. It expires in 7 days and uses the portal's `password_invites`.
   - **Existing account**: a sign-in link.
   - If mail is not configured, or the send fails, the link is shown to you to copy.

On each person's row:
- **Edit access**: change their departments, shared tasks, and whether they can comment.
- **Resend invite** / **Send sign-in link**.
- **Deactivate**: their Work access stops at once. Their departments are kept, so **Activate** brings them back.
- **Revoke**: their Work access stops at once, and their departments and shares are removed.

Access is read fresh from the database on every request, so a change takes effect on the person's next click. Their portal account for other modules is not touched. To shut out the whole account, revoke it on Team Management or Clients.

You can also share a single task from its side panel ("Shared with clients").

## The report: "last week vs today"

Reports → choose a **compare date** (default: the same day last week) and a **current date** (default: today). Optional filters: departments, assignee, client and status.

- **Status at a date** is the newest `wm_task_status_history` row with `changed_at` on or before 23:59:59 that day.
  - A row is written in the same transaction as every status or progress change, and on creation.
  - A task with no row by then shows **Not created**.
- The report lists every task that existed by the current date and is not deleted. Its scope is:
  - the viewer's own scope;
  - narrowed to that client's scope when the client filter is used;
  - always their own for a client.
- **Change** is "Old → New", or "No change".
- **Overdue** means the due date is before that date and the status is not Completed or Cancelled.
- **Hours Logged (in period)** is time logged on dates from the compare date to the current date, inclusive. It is blank (—) on a client's report.
- **Latest Update / Remarks** is the newest comment or status-history note up to the current date. A client's report uses client-visible comments only, and never history notes.

The download is a real `.xlsx` named `Work-Report_<Department or All>_<YYYY-MM-DD>.xlsx`, written by `lib/wm/xlsx.php`, a ZipArchive writer with no Composer needed.
- **Sheet 1, Task Status**: 17 columns, a brand-coloured frozen header, auto-filter, set widths, and wrapped Description and Remarks. Dates are `DD-MMM-YYYY`. Status cells are colour-coded with the word, overdue shows red text, and changed rows are highlighted.
- **Sheet 2, Summary**: the period, when the report was generated and by whom, counts per status for both dates, and totals per department.

Every export is written to the audit log.

## Audit log

The audit log is shown at the bottom of Access. It records:
- user invited
- access changed
- user activated, deactivated or revoked
- department created, updated or deleted
- task created, edited or deleted
- status changed
- report exported

## Environment

There are no new settings. Invitations use the portal's existing mail settings in `config.local.php` (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME`). Without them, the Access screen shows the link to copy instead.

## Files

| File | What it is |
|---|---|
| `lib/wm/core.php` | Schema, access model, tasks, history, comments, time, overview |
| `lib/wm/access.php` | Members, invites, sharing, audit list |
| `lib/wm/report.php` | Status-at-date, the report, the workbook |
| `lib/wm/xlsx.php` | Styled .xlsx writer |
| `lib/wm/nav.php`, `lib/wm/app.php` | Sidebar group and page shell |
| `api/work.php` | JSON API. GETs read; POSTs are forms carrying `csrf` and `payload` (JSON). Rate-limited. |
| `admin/work.php`, `work.php` | Entry points |
| `assets/work.css`, `assets/work.js` | The interface: list, board and table, side panel, charts, light and dark themes, reduced motion |

## Tests (local only, never deploy)

- `php tests/wm_selftest.php`: 115 checks. Roles (a team member managing users gets 403; a client opening another client's task gets 404), history recording, status-at-date for last week vs today, report rows and summary, and that the .xlsx opens with the right columns. It cleans up after itself.
- `node tests/wm_e2e.mjs <base> <admin email> <admin password> <out dir>`: the browser test. It needs Chrome and a portal copy on an **empty** throwaway database.
  1. The admin creates a department and tasks.
  2. The admin drags a task on the board and edits it in the side panel.
  3. The admin invites a client.
  4. The client sets a password, signs in, and sees only what is shared.
  5. The admin downloads the Excel report, which the test opens and checks.

  It also checks 1440, 1366, 1280, 1024 and 390 px for horizontal scrolling.
