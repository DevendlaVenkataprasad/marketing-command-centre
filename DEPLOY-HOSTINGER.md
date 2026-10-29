# Deploying to Hostinger — mark-up.in

Everything is already configured for your account. Four steps, about ten minutes.

---

## 1. Create the database (if you have not already)

hPanel → **Databases → Management**. The screen you were on. Use exactly:

| Field | Value |
|---|---|
| MySQL database name | `marketing_MCC` (hPanel prefixes it to `u128196718_marketing_MCC`) |
| MySQL username | `marketing_MCC` (becomes `u128196718_marketing_MCC`) |
| Password | *(choose a strong one in hPanel; put it only in `config.local.php`)* |

Press **Create**.

> If you choose a different name, user or password, open `config.local.php` and
> change the three lines under `LIVE (Hostinger)` to match. Nothing else moves.

---

## 2. Load the database

hPanel → **Databases → phpMyAdmin** → open `u128196718_marketing_MCC` → the
**SQL** tab → paste the whole of **`database/hostinger_install.sql`** → **Go**.

That one file creates all 22 tables and loads the starting data: the workbook's
sample rows, the 2026 festival calendar, every dropdown list and the checklist
templates. It has been imported into a clean database and verified.

If the file is too large to paste, use phpMyAdmin's **Import** tab and upload it
instead.

> Running it again wipes and rebuilds everything. Only run it on a fresh
> database, or when you deliberately want to start over.

---

## 3. Upload the files

Upload the **contents** of this folder into `public_html/` — so that
`public_html/index.php`, `public_html/admin/`, `public_html/lib/` and so on.

Use hPanel → **Files → File Manager**, or FTP. Upload everything **except**:

```
uploads/          (created automatically; see step 4)
*.log
```

Include these — they matter:

- `.htaccess` — forces HTTPS, hides `lib/`, `database/`, `certs/` and
  `config.local.php`, and turns off directory listings
- `config.local.php` — your database, mail and admin password
- `certs/cacert.pem` — lets the mail client verify Gmail's certificate

---

## 4. Make the uploads folder writable

In File Manager, create a folder `public_html/uploads` and set its permissions
to **755** (or 775 if 755 does not work). This is where content files, design
files and client logos are stored.

The app creates the folder itself on first use, but on some plans PHP is not
allowed to, so making it by hand is safer. It also drops a deny rule inside, and
the `.htaccess` blocks it too — files are only ever served through
`download.php`, which re-checks who is asking.

---

## 5. Open it

| For | URL |
|---|---|
| **You (admin)** | `https://mark-up.in/login.php?admin=1` |
| **Clients and team** | `https://mark-up.in/login.php` |
| Anything else | `https://mark-up.in/` redirects to the right place |

**Admin sign-in:** the admin email and password you set in `config.local.php` (`ADMIN_EMAIL` / `ADMIN_PASS_HASH`). Never write the password itself into any file.

Change that password as soon as you are in — see below.

---

## Afterwards

**Change the admin password.** Run this locally and paste the result over
`ADMIN_PASS_HASH` in `config.local.php`, then re-upload that one file:

```
php -r "echo password_hash('your new password', PASSWORD_DEFAULT);"
```

**Check mail works.** Create a test client with your own email address. They
should receive their sign-in details within a minute. Gmail SMTP on port 587 is
already configured; if Hostinger blocks it on your plan, switch `SMTP_PORT` to
`465` in `config.local.php` — the mail client speaks both.

**Clear the sample data when you are ready for real data.**
Admin → **Start with your own data** → type `DELETE`. That empties every sheet
and keeps your lists, clients, team and files.

---

## How the configuration switches

`config.local.php` holds two profiles and picks one from the host name:

- host contains `mark-up.in` → the Hostinger database
- anything else → your local XAMPP database

So the same folder runs in both places. Copy it up or down without editing.

---

## If something does not work

| Symptom | Cause and fix |
|---|---|
| "Database setup needed" page | The credentials in `config.local.php` do not match hPanel, or the database has not been created. The page shows which host, database and user it tried. |
| Blank page / 500 | Check hPanel → **Website → Error log**. Nothing is ever printed to the screen by design. |
| Emails not arriving | Try `SMTP_PORT=465`. Check the error log for the SMTP reply — it records the exact reason. |
| Uploads fail | `public_html/uploads` is missing or not writable (step 4). |
| Styles missing | `assets/app.css` did not upload, or `.htaccess` was skipped by your FTP client (it hides dotfiles). |

---

## What is in the folder

| Path | Purpose |
|---|---|
| `index.php` | Front door — sends people to the right place |
| `login.php` · `logout.php` | Sign-in for admin, clients and team |
| `admin/` | The admin portal: sheets, dashboard, clients, team, forms |
| `c.php` | A client's or team member's own portal |
| `form.php` | Public intake forms |
| `export.php` · `download.php` | Excel export, and guarded file downloads |
| `api/` | The grid's JSON endpoints |
| `lib/` | All the logic (never served directly) |
| `assets/` | One stylesheet, one script |
| `database/hostinger_install.sql` | **The file to paste into phpMyAdmin** |
| `certs/cacert.pem` | Certificate bundle for outgoing mail |
| `config.php` · `config.local.php` | Settings; secrets live in the `.local` one |
