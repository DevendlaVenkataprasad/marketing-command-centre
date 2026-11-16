# Digital Signatures (CDSMS) — technical guide

The centralized Digital Signature Management module of the portal: documents are uploaded,
routed through configurable workflows, approved or digitally signed (Aadhaar eSign / DSC),
verified, tracked and kept — per organisation, inside the existing portal.

- Signed-in users: `sign.php` · Administrator: `admin/sign.php`
- ESP response URL: `sign-callback.php` · Public check by tracking code: `sign-verify.php?c=CODE`
- REST API: `api/sign.php`
- Code: `lib/ds/` (engine) and `lib/ds/pages/` (screens); assets `assets/sign.css`, `assets/sign.js`

## 1. What is legally what

| Method | What it is | Legal standing |
|---|---|---|
| **Aadhaar eSign** | Signature by a CCA-licensed eSign Service Provider (eMudhra, C-DAC, Protean …) under the CCA eSign API. | Digital signature — IT Act 2000, Second Schedule |
| **DSC** | Signer's own Class 3 certificate on a USB token, applied in Acrobat/Reader, uploaded. | Digital signature — IT Act 2000, s.3 |
| **Drawn signature** | Drawn or uploaded signature image stamped on the PDF by the signed-in signatory (no password prompt). | Electronic record only |
| **Electronic approval** | One-click approval by the signed-in signatory, stamped on the PDF. | Electronic record only |

Nothing is ever marked signed by a click. Digital methods count only after the CMS signature
verifies over exactly the bytes prepared, the certificate chains to the trust store, and the
signed file is the prepared file plus nothing else. Electronic records are sealed (HMAC) and
re-checked in every verification report.

## 2. Installation

Nothing to run: the tables are created on first open (`ds_install()`, version in
`app_settings.ds_schema`; future changes go in `ds_migrate()`).

1. Grant **Digital Signatures** to a client organisation on *Clients* (ours always has it). Heads can pass
   it to their team on *My Team*; our staff on *Team Management*.
2. Open *Digital Signatures → Administration* and work down the **Readiness** list:
   - **Trust store** — add the CCA India root certificates (CCA India 2022, 2014, 2015 SPL from
     cca.gov.in → Repository) and any licensed-CA intermediates. Until then every digital signature shows
     *Verification incomplete*.
   - **Signing providers** — configure Aadhaar eSign (see §4). DSC works with no configuration.
   - **Settings → Departments & units** — departments are HR's master (`hr_departments`); units are the
     organisation's *Hub / Spoke* list.
   - **Signatory directory** — authority (e.g. *Department Head*, *CEO*), limit, department, preferred
     method; register DSC public certificates.
   - **Workflows**, **Categories & retention**, **Roles & permissions**.
3. Schedule `cron.php` every 5 minutes (hPanel → Advanced → Cron Jobs). Without it, reminders,
   escalation and expiry run at most hourly when someone opens the module.

## 3. Environment and secrets

| Name | Where | Purpose |
|---|---|---|
| `DS_STORAGE_KEY` | env / `config.local.php` (`define`) | Optional. 32-byte key, base64. Overrides the key file. |
| `DS_KEY_FILE` | env | Optional. Path of the storage key file. |
| *(default key file)* | live: `/home/<acct>/domains/<domain>/.mcc-esign/storage.key` (outside `public_html`); local: `uploads/ds/.storage.key` | Made on first use. AES-256-GCM key for every stored file, and the HMAC key of the audit chain and seals. |
| ASP private key | a file you place outside the web root; its **path** goes in the provider form | Signs eSign requests. Never stored in the database. |
| ASP key passphrase | an environment variable you name in the provider form | Only the variable's name is stored. |

**Back up together**: the database (`ds_*` tables), `uploads/ds/`, and the key file. If the key file is
missing once documents exist, the module refuses to run rather than silently making a new key.

## 4. Aadhaar eSign set-up (provider adapter `esign_cca`)

From the ESP's ASP onboarding kit: ASP ID, sandbox and production gateway URLs, the ESP's
response-signing certificate. Then:

1. Generate the ASP key pair; register the certificate with the ESP.
2. Put the key at e.g. `/home/<acct>/domains/<domain>/.mcc-esign/asp.key` (`chmod 600`).
3. *Signing providers → Add provider*: ESP name, gateway URL, ASP ID, key path, (certificate path),
   ESP certificate (PEM), API version 2.1, auth mode 1 (OTP), request field `msg`. Test, then activate.
4. Give the ESP the response URL `https://<portal>/sign-callback.php`.

Flow: the PDF is prepared with an empty signature field (incremental update, 16 KB reserved); the
SHA-256 of its byte range goes in an XML-DSig-signed `<Esign>` request; the browser posts it to the
ESP; the signer authenticates there (OTP never touches the portal); the ESP posts `<EsignResp>` back.
The callback verifies the XML signature against the ESP certificate, the transaction, its expiry and
status, verifies the PKCS #7 over the prepared bytes, embeds it, re-verifies the whole PDF, and only
then records the signature. Repeated callbacks are idempotent. Switching ESP is configuration only.

## 5. Workflow engine

- Stages run in order; signatories inside a stage act in parallel. A stage completes when every
  *mandatory* signatory has acted (or *any one*, if so set). Optional signatories never hold a stage.
- Signatory types: named person, document owner, owner's manager (HR), **signing authority**
  (resolved from the directory, preferring the document's department and respecting the authority
  limit against the document value), chosen when sending.
- Conditions (workflow and per stage): categories, departments, units, confidentiality, value range.
  The first active workflow by priority (a category's default first) is suggested.
- Rules: deadline days, reminder interval and count, escalation (after N hours → manager / named person
  / administrators; notify or reassign), rejection final or re-initiable, re-send restarts or resumes.
- Sending locks the version (SHA-256 recorded). A new version mid-signing cancels the cycle.
- Delegation: only when the signatory profile allows; the delegate signs in their own name, "on behalf of".
- A digital signing in progress holds the document (eSign 15 min, DSC configurable) so nobody's
  signature lands on bytes that changed.

### Signature studio (sender places the fields)

After choosing the route (New document → step 3, or *Send for signature* on a draft), the sender
lands in the studio (`sign.php?p=studio&id=…`). Each signatory has a colour; the sender drags
**Signature, Initials, Full name, Date signed, Designation** onto any page (or clicks a field, then
the page), moves and resizes them, and sends. Fields save as they change (kept in
`ds_documents.draft_plan_json` until sent, then on each `ds_signature_requests.placement_json`).
"Signature for everyone on the last page" lays out a signature, name and date block per person.

When signing, the placed fields are filled in: the drawn signature or approval in each signature
field, name / date / designation as text, initials (drawn when the signer drew). For Aadhaar eSign the
first signature field becomes the digital signature itself and the other fields are written into the
same revision, so they are covered by it. For DSC, the downloaded copy already carries the other
fields and an empty signature field at the placed spot, so Acrobat signs into it. Without placed
fields, signers choose a place themselves (or the foot of the last page is used).

### Fast viewing

The viewer shows **server-rendered page pictures**, not the PDF. Ghostscript (`/usr/bin/gs`, or `DS_GS`) renders each page
once (about 0.1 s a page, a ~100 KB JPEG), stored encrypted under `uploads/ds/cache/<sha256>/` and keyed by the file's hash,
so a new signature (a new file) gets new pictures and nothing goes stale. The first pages are rendered right after a
document page is sent (`ds_preview_warm`). Without Ghostscript the viewer falls back to pdf.js with byte-range loading.
Downloads, signing and verification always use the PDF itself.

## 6. Roles

Super Administrator, Organisation Administrator, Department Administrator (scoped to departments),
Document Creator, Document Reviewer, Authorized Signatory, Document Viewer, Auditor — each a set of
permissions editable in *Roles & permissions*. Everyone in the organisation can sign what is addressed
to them and verify PDFs; *Settings* decides whether everyone may create documents. Confidential
documents are not visible department-wide; Restricted only to the people on them.

## 7. Database (all InnoDB, utf8mb4, `org_id` on every root table)

| Table | Holds |
|---|---|
| `ds_documents` | document, status, plan (JSON), cycle, current version, working/signed file, retention, legal hold, optimistic `version` |
| `ds_document_versions` | each uploaded PDF version: SHA-256, pages, locked/superseded |
| `ds_files` | encrypted blobs (original, working, prepared, attachment) with SHA-256 |
| `ds_document_attachments`, `ds_comments`, `ds_permissions` | supporting files, discussion, sharing |
| `ds_categories`, `ds_retention_policies` | categories (default workflow), retention years and action |
| `ds_workflows`, `ds_workflow_stages`, `ds_workflow_signatories` | templates, conditions, rules |
| `ds_signature_requests` | per person per stage per cycle: status, due, reminders, escalation, evidence JSON + HMAC seal |
| `ds_signature_transactions` | eSign/DSC sessions: txn ref, byte range, document hash, request/response digests, callbacks |
| `ds_signatories`, `ds_certificates` | signatory directory; registered and observed certificates |
| `ds_trust_anchors`, `ds_crl_cache` | trust store; cached CRLs |
| `ds_signature_verifications` | every verification report (JSON) with its reference |
| `ds_audit` | hash-chained, HMAC-sealed audit trail (`seq`, `prev_hash`, `hash`) |
| `ds_notifications` | in-app and e-mail notification log |
| `ds_delegations`, `ds_role_members`, `ds_providers`, `ds_api_tokens`, `ds_settings`, `ds_seq` | configuration |

Users = `clients`; departments = `hr_departments`; designations = signatory profile or HR.
No private key, OTP or password is ever stored.

## 8. REST API

Auth: `Authorization: Bearer dsk_…` (Settings → API access; acts as its creator within their
permissions; scopes `read`, `write`, `verify`; only a hash is stored) or the session with
`X-CSRF-Token`. 300 requests / 5 min per IP. Responses `{"ok":true,"data":…}` or
`{"ok":false,"error":"…"}` with the HTTP status.

| Method | `?r=` | Parameters |
|---|---|---|
| GET | `documents` | `status`, `q`, `page` |
| GET | `document` | `id` — with versions, requests, current PDF hash |
| GET | `requests` | the caller's pending requests, with `sign_url` |
| GET | `workflows`, `signatories`, `notifications` | |
| GET | `audit` | `id` (document) |
| GET | `reports` | `key` (register, pending, completed, department, signatory, rejected, expired, turnaround, workflow, verification, audit), `from`, `to`, `dept`, `cat`, `status`, `person` |
| GET | `verification` | `id` |
| POST | `documents` | multipart `pdf` + fields (`title`, `category_id`, `department_id`, …) |
| POST | `submit` | `id`, `workflow_id` (+ `chosen[stage:slot]`) or `signers[i][person_id|order|action|method|mandatory]` |
| POST | `decide` | `request_id`, `decision` = `review`/`reject`/`changes`, `remarks` |
| POST | `verify` | multipart `pdf`, or `id` |

Signing and approving are deliberately not in the API: they need the signatory in person.

## 9. Security summary

RBAC on every page and action; CSRF on every form; per-person and per-IP rate limits (posting,
re-authentication 5/15 min, verification, callbacks, public look-ups, API); uploads scanned for
JavaScript, launch actions, embedded files, XFA and rich media (inside object streams too) and by
ClamAV when configured; encryption at rest (AES-256-GCM); files served with `sandbox` CSP; XML
parsed with no DTD/entities/network; revocation fetches through the portal's SSRF guard;
tamper-evident audit (any edit, deletion or re-ordering detected); soft delete, legal hold, retention.

## 10. Tests

- `php tests/ds_selftest.php [host] [port] [db]` — end-to-end on a throwaway database (default
  127.0.0.1:3399 `dstest`). Builds a test CA with CRLs, a DSC, a test ESP; walks every scenario.
- `php tests/ds_render.php <person id|admin> "<query>" [out.html]` — renders a page as a person and
  reports any PHP problem.

Last run (2026-11-03): **147 passed, 0 failed** (incl. the field studio); all 47 page renders clean for head, signatory,
outsider and administrator.

| # | Scenario | Result |
|---|---|---|
| 1 | Upload (reference, tracking code, encryption at rest, JavaScript PDF and non-PDF refused) | Pass |
| 2 | Sequential workflow with authority resolution and value condition | Pass |
| 3 | Multi-level: electronic approval → DSC → Aadhaar eSign; someone else's DSC, self-signed DSC, other file all refused | Pass |
| 4 | Parallel signing; optional signer skipped; stale view refused | Pass |
| 5 | Reject (reason required; later requests withdrawn) | Pass |
| 6 | Request changes | Pass |
| 7 | Reinitiate, new version, re-send (cycle 2/3); new version mid-signing stops the cycle | Pass |
| 8 | Verify valid signed PDF (2 signatures, CRL checked, mandatory signatures present); report and certificate PDFs | Pass |
| 9 | Modified after signing (page content replaced) → Modified; byte flipped → Invalid | Pass |
| 10 | Expired (judged later, no timestamp) → Expired; on CRL → Revoked; CRL unreachable / untrusted root → Incomplete | Pass |
| 11 | ESP error, forged response, unknown transaction, XXE, signature over other bytes | Pass |
| 12 | Duplicate callback ignored, document unchanged | Pass |
| 13 | Other organisation, restricted documents, viewer rights | Pass |
| 14 | Department Administrator scoped to departments; department report | Pass |
| 15 | DSC hold blocks a concurrent stamp; both complete in turn; signature stays valid; unique references | Pass |
| 16 | Expiry by the scheduler | Pass |
| 17 | Audit: 15 required events present; edited entry, deleted entry, edited approval seal all detected | Pass |
| 18 | Phone width (390 px) — screens checked; tables become cards, panels stack | Pass |

Not covered by automated tests: a live ESP sandbox round trip (needs the organisation's ASP
onboarding), OCSP responders (CRL path is tested; OCSP failure falls back to CRL and never passes
silently), and RFC 3161 timestamp tokens from a real TSA.
