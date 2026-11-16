# Event Organizers

The event platform from "Event Management System.zip" (Next.js, Postgres, Clerk, Stripe) rebuilt inside the portal, on the marketing database only. It could not be copied in: the portal is PHP and MySQL on shared hosting. None of its data came across; the zip holds no database.

## How it is built

- **One pipeline.** An event's forms and applications are the organisation's main ones (`inc_forms`, `inc_applications`). They sit on the Incubation Management board with their **program name** (`event_name`) and **type of participant** (`applicant_type_id`, filled from the form's participation question).
- **Organizers sees only event applications.** `inc_kind()` 'eo' means a page is served as Event Organizers: its own grant, links and wording, narrowed by `inc_app_where`, `inc_app`, `inc_forms_list` and `eo_scope()`. Stages are changed in Incubation Management only.
- **Event layer.** Everything else is `lib/eo`. Tables are written `inc_ev_*` and land as `eo_ev_*` for us and `eo_o<client id>_ev_*` for a client. Plans are the shared `eo_plans` and `eo_subscriptions`.

| File | Holds |
| --- | --- |
| `lib/eo/app.php` | Sync, events, forms, tickets, check-in |
| `lib/eo/commerce.php` | Registrations, orders, coupons, Stripe, refunds, waiting list, plans |
| `lib/eo/engage.php` | Mail, WhatsApp/SMS, reminders, agenda, Q&A, polls, online attendance, Zoom, mentors, sponsors, leads, food tokens, certificates, staff links, API, webhooks, accounts, AI |
| `lib/eo/schema.php` | Tables, and the move from the first version |
| `lib/eo/public.php` | What the public pages share |

## Where events come from

Nothing is created here. `eo_sync()` reads the Events sheet: the row must have a name, must not be a department draft, and must be approved (`approvals.state`, or a Sign-off of Approved when no committee decided it). Name, type, dates and place follow the sheet. If approval is withdrawn the public page closes, tickets and applications stop, and all data is kept.

Because of this, the original's "create event" and "duplicate event" are deliberately not here.

## Who can do what

| Who | How they get it | What they can do |
| --- | --- | --- |
| Owner | Clients (head), My Team or Team Management | Everything, including forms, Settings, Developer |
| View only | Same pages | Reads and exports |
| Organizers team | Event Organizers → Organizers team | Events, attendees, tickets, check-in, orders, the desk. No forms or Settings |
| Staff link | Event → Staff links | One job on one event, no account: scanner, viewer, finance or sponsor rep |
| Attendee account | Signs up on `event-account.php` | Their own tickets, orders and certificates. Reaches nothing of the portal |

## Public pages

`event.php` (browse, event page, About, Contact, Terms, Privacy, calendar file), `event-apply.php`, `event-pay.php`, `event-ticket.php` (ticket, food token, certificate), `event-live.php`, `event-account.php`, `event-staff.php`, `event-chat.php`, `event-api.php`, `event-hook.php`.

A client's public address needs `?o=<client id>`: `event.php?o=16` for RTIH.

## Services that need keys

Keys go in `config.local.php` as `putenv('NAME=value');`. Settings shows what is connected. Without them everything else keeps working.

| Service | Keys | Without it |
| --- | --- | --- |
| Card payment | `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`. Webhook: `event-hook.php?s=stripe`, event `checkout.session.completed` | Bank transfer and cash still work |
| WhatsApp | `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` | Email only |
| SMS | `MSG91_AUTH_KEY`, `MSG91_TEMPLATE_ID`, or `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` | Email only |
| Zoom | `ZOOM_ACCOUNT_ID`, `ZOOM_CLIENT_ID`, `ZOOM_CLIENT_SECRET`, `ZOOM_WEBHOOK_SECRET_TOKEN`. Webhook: `event-hook.php?s=zoom` | Paste any meeting link |

The AI writer, event report and assistant use the portal's Gemini key instead of Groq.

## Differences from the original

- Registration is the application form on the main pipeline; a group's members are form questions.
- Tickets, order and report PDFs are print-to-PDF pages.
- Offline scanning works on a scanner page already open: it keeps the roster, queues scans and syncs. There is no installable app.
- Co-organizers are Owners in the portal. Staff links cover the other team roles.
- Branding is the organisation's own (logo, name, mail sender).
- An organisation with no plan has no limits. Plans limit seats, mentors, AI reports and Excel; the original's limit on event count is not applied, because events come from the sheet.
- Payment gateways other than Stripe, and automatic plan billing, are not built: the zip only stubs them.

## Notes for changing it

- The QR holds the ticket code alone, not a link. `lib/eo/qr.php` matches the `qrcode` npm package bit for bit in all 8 masks across versions 1 to 10. If a scan test fails, compare with that package before blaming the encoder: the bundled decoder misses single frames.
- `.htaccess` turns the camera on for `organizers.php` and `event-staff.php` only.
- `inc_words()` renames whole phrases only, and only inside `<main>`.
- The first version's `eo_forms`, `eo_applications` and similar tables are left in place, unread. `eo_move_to_main()` copied them once under a lock; `_eo_moved` marks it done.

## Testing

`tests/eo_selftest.php` needs a throwaway database named `eo_test`. Stripe, messages, webhooks and AI are faked. Never deploy it.
