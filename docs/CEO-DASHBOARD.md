# CEO Dashboard

Live executive overview across the CRM's modules. For whoever maintains or deploys the portal.

## Where it lives

| Part | File |
|---|---|
| Entry pages | `ceo.php` (signed-in people), `admin/ceo.php` (administrator) |
| API | `api/ceo.php` |
| Tables, access | `lib/ceo/schema.php`, `lib/ceo/core.php` |
| KPI registry, module sources, cache, snapshots | `lib/ceo/kpis.php` |
| Action queue, feed, trend, spokes, departments, targets, coverage | `lib/ceo/data.php` |
| Reports, Excel, PDF, schedules, background tick | `lib/ceo/reports.php` |
| Page frame, sidebar | `lib/ceo/app.php`, `lib/ceo/nav.php` |
| Front end | `assets/ceo.js`, `assets/ceo.css` |
| Test (local only, never deploy) | `tests/ceo_selftest.php`, needs a database named `ceo_test` |

Touched outside the module: `lib/auth.php` (the `ceo` module entry), `lib/ui.php` (sidebar, `pulse` icon), `cron.php`, `.htaccess` (no-cache list).

## Database

No manual migration. `ceo_install()` creates the tables on first use and records the version in `app_settings.ceo_schema`. Every row carries `org_id`.

`ceo_settings`, `ceo_profiles`, `ceo_access`, `ceo_targets`, `ceo_schedules`, `ceo_reports`, `ceo_snapshots`, `ceo_cache`, `ceo_prefs`, `ceo_audit`.

Nothing operational is copied. `ceo_snapshots` holds one reading per KPI per day, because stock figures such as open tickets have no past of their own. `ceo_cache` is short-lived.

## Turning it on

1. Administrator: Clients, open the organisation, tick **CEO Dashboard**. Only its head gets it.
2. The head: CEO Dashboard, Access management, then grant to people or positions.
3. To see financial figures, salary, personal data or confidential titles, the head chooses a profile that includes them under **Your own access**. Being the owner does not open them.
4. Optional, recommended: add `php .../cron.php` every 5 minutes in hPanel. Without cron, daily readings and scheduled reports run only while someone has the dashboard open.

## Access model

Decided in one place, `ceo_access()`, on every request, with nothing remembered:

- **Owner**: the client's head, or the administrator. Runs Access management, sees every module, but sensitive categories only through a profile.
- **Person**: an active `ceo_access` row. Beats a position.
- **Position**: an active row naming an HR designation. Applies to whoever holds it now. The grant must carry a hash of the exact people previewed, otherwise it is refused with 409.
- A profile holds modules, spokes, KPIs, sensitive categories and actions (view, filter, export, report, targets, configure).
- Someone limited to spokes never sees an organisation-wide figure.
- A drill-down link is drawn only for someone who can open that module, and the module still judges the click. A record's own wording is shown only to someone who can open the module and holds any sensitive category it touches. Everyone else sees a neutral label.

## Live updates

Polling, not WebSockets or SSE: the host limits connections, and a held-open stream costs a PHP worker and a database connection for each open dashboard. The page polls every 30 seconds, pauses in a hidden tab and backs off on failure. Figures are cached for 60 seconds to 15 minutes by KPI (see the registry page). A failed source is shown as failed, never as an old figure. Refresh forces a recompute, limited to 8 a minute.

## KPI definitions

`ceo_kpis()` is the one definition. The KPI registry page shows each KPI's calculation, source tables, spoke support, sensitivity, caching and target periods.

Counting once:

- A startup is its registry row, however many applications it has.
- Co-working memberships are their own figure and are not added to startups.
- Marketing work counts from the Master Activity Log, never from the sheets that feed it as well.
- An HR claim mirrored into Finance counts as one approval.

Spoke names are reconciled by `ceo_spoke_key()` (Vishakapatnam and Visakhapatnam, Ananthapuram and Anantapur, Rajamahendravaram and Rajahmundry). Add new spellings there.

## Adding a module

Add it to `ceo_modules()`, write `ceo_src_<module>()`, add its KPIs to `ceo_kpis()`, and add its items to `ceo_actions_raw()` and `ceo_feed_raw()`. Nothing in the front end changes.

## What the data cannot show yet

| Asked for | Why not | What would fix it |
|---|---|---|
| Daily and weekly reporting compliance by spoke | The CRM has no such submission. Event results are the only reporting measured | A spoke report submission record |
| Event registrations from the event itself | `events` has expected and actual attendees only. Registrations are counted from event and programme forms in Incubation | A registrations column or RSVP link |
| IT, Facilities and Finance by spoke | No spoke on tickets, assets or finance documents | A spoke field, or mapping cost centres to spokes |
| HR by spoke | Works only if each employee's Location is a hub or spoke name | Set Location on employees |
| Signatures by spoke | Needs the document's Unit filled in | Fill it in |
| Trend on stock KPIs | No history before the first daily reading | Fills in from the first day |
| Position access for people without an HR record | Positions come from HR designations linked to a login | Link employees to their login |
| Drill-down keeping the spoke filter | Modules take their own filters | Per-module filter parameters |
| Charts inside the Excel export | The workbook writer has no charts. The PDF has them | A chart-capable writer |

## Retention

Audit log 3 years, report log 2 years, snapshots 3 years, expired cache removed on the hourly tick.

## Testing

```
php tests/ceo_selftest.php     # on a copy whose DB_NAME is ceo_test
```

It seeds two organisations and checks the figures, access rules, organisation separation, reports and schedules. It refuses to run on any other database.
