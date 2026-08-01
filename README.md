# GymFlow AI

Smart gym and sports club management, built as a multi-tenant SaaS. Every
club — gym, pool, martial arts hall, yoga or pilates studio, football club —
signs up, gets its own isolated space, and its manager app, member app and
admin panel all connect to that space and nothing else.

```
backend/   Laravel 12 API — the platform core, shared by everything below
admin/     Vue 3 + Vite + Tailwind 4 management panel
mobile/    Flutter workspace
  gymflow_core/   shared API client, models, session, design system
  manager_app/    the club manager app
  member_app/     the member app
```

---

## What is here

### Multi-tenancy

Every club-owned row carries a `tenant_id`. A global Eloquent scope narrows
reads to the active club and stamps writes with it, so a controller cannot
leak across clubs even by accident. The tenant is resolved from the signed-in
user, an `X-Tenant` header, or a subdomain, and a suspended club stops serving
its apps while keeping its data.

Tests in `backend/tests/Feature/TenantIsolationTest.php` cover the promise
directly: queries, writes, list endpoints and id lookups.

### The membership and quota engine

A pass can be sold by duration (1/3/6/12 months), by sessions (10/20/50), or
unlimited — and both limits apply at once. A 30-session pass that also runs
for 30 days expires on whichever runs out first. Every entry burns one
session from a session pass and none from a duration pass; the last session
retires the membership on the spot.

Freezing pushes the end date out by the frozen days, so a break never costs
the member anything.

### The gate

The reception desk scans a QR badge or taps an NFC card. The API answers with
an allow or a refusal that carries a machine-readable reason
(`no_membership`, `expired`, `no_sessions_left`, `already_inside`, …) plus the
message already translated into the requested language, so the scanner screen
can show a green or red card and react to the cause.

There is a preview endpoint that runs the same checks without writing
anything, for the screen the receptionist reads before confirming.

### The rest of the club

Classes and pool sessions with capacity-safe booking under a row lock,
coaches and their contracts, workout and nutrition programs, periodic body
measurements with charts, invoices and payments feeding a daily cash
register, a member wallet, a shop with stock movements, and member ↔ coach
chat — scoped so a member only ever sees their own threads.

Seven system roles — owner, manager, reception, coach, cashier, accountant,
member — over a wildcard permission catalogue, per club and editable.

### Reports

150 reports across eleven groups: overview, members, memberships, attendance,
classes, coaches, finance, shop, training, engagement and administration. A
registry maps each key to its group, the period controls the screen should
offer and the query that runs it, so the panel knows to ask a rolling-window
report for days rather than a date range. Every one is labelled in all three
languages and exports to CSV.

Two tests run all 150 — once against an empty club, once against one with
real rows — so a broken query fails the build rather than a manager's screen.

### Money in and out

Members renew and top up from the app. A payment opens as pending, the member
is sent to the club's gateway in their own browser, and the money is only
recognised once the gateway itself confirms it — the browser coming back with
`status=OK` is never enough. Zarinpal (Toman converted to Rial both ways),
Stripe Checkout, and a sandbox that answers until a club connects a real one,
so the whole flow can be walked through without a bank. Settling is
idempotent, because payers refresh receipts and gateways retry callbacks.

### Reaching members

Campaigns go out over SMS (a generic HTTP gateway, so an Iranian or Turkish
provider is a settings change rather than a new SDK), WhatsApp via Meta's
Cloud API, Telegram, club-branded email, and push over FCM. Anything a club
has not configured falls back to a log driver, so a campaign always completes
and nothing leaves the building by accident. Bodies support `{name}`,
`{code}`, `{club}`, `{expires}` and `{sessions}`, with a preview endpoint that
renders one against a real member first. Above 25 recipients the send moves to
a queued job that restores the campaign's club before it starts.

### Printing in Persian

DomPDF draws the code points it is handed, in the order it is handed them —
no letter joining, no right-to-left reordering. A Persian invoice therefore
came out as isolated letters running backwards.

`app/Support/PersianShaper.php` fixes it: every letter is swapped for its
contextual presentation form, lam-alef becomes its ligature, and the
right-to-left runs are reversed while Latin text, prices and phone numbers
keep their own order. The general-purpose Arabic libraries were tried first
and left the six Persian-only letters — پ چ ژ ک گ ی — untouched, which is
most of what an Iranian club's invoice is made of.

DejaVu Sans, which DomPDF already ships, carries every presentation form
needed. A club that wants its own typeface drops a TTF into `storage/fonts`
and registers it in `config/dompdf.php`; the shaping still applies.

### Security

- **Provider sign-in** with Google, Apple or GitHub. A provider account binds
  to one user in one club and never crosses between them, and an identity on
  its own never creates a staff account — it attaches to someone the club
  already invited, or creates a member only where the club turned that on.
- **Two factor** over TOTP, written against RFC 6238 and checked against the
  spec's own test vector. Enabling is two steps on purpose — a mis-scan must
  not lock an owner out of their own club. Recovery codes are shown once,
  stored hashed and spent on use; the secret is encrypted at rest.
- **An audit trail** that actually records: create, update and delete on the
  twelve models a manager would later ask "who changed this?" about, plus
  sign-ins, refusals, failed attempts and password changes. Updates keep only
  the columns that moved and what they were before. Passwords, tokens and QR
  secrets are masked on the way in.
- **Nightly backups** of the database and everything uploaded, pruned to the
  number of archives a club wants to keep. MySQL is dumped with the password
  in a 0600 credentials file rather than on a command line every user on the
  box can read.
- **Health notes and passport numbers encrypted at rest**, so a stolen dump
  does not read as a medical record. The national id stays in the clear on
  purpose: reception searches by it, and an encrypted column cannot be.

### The AI module

Two halves, and the first works with no API key at all:

- **The statistical engine** scores churn risk per member from absence,
  visit-rate decay and how close the pass is to running out; forecasts the
  month from the pace so far; analyses attendance by hour and trend; and
  proposes campaigns worth sending today.
- **Claude** writes workout and meal programs tailored to the member, and
  answers management questions grounded in a live snapshot of that club's own
  figures. Both fall back to templates or a plain "not configured" answer
  when `ANTHROPIC_API_KEY` is unset, so the feature never leaves a coach
  empty handed.

### Languages

Persian (RTL), Turkish and English. Every user-facing string is served from
the `translations` table with a per-club override layer on top of the shipped
files, so wording changes ship without an app release. The apps download the
whole table for a language in one request.

---

## Running it

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite        # or point .env at MySQL 8
php artisan migrate --seed            # plans, exercise library, super admin
php artisan db:seed --class=DemoClubSeeder   # optional: a populated club
php artisan serve
```

The demo club signs in as `owner@arman.club` / `password` with club slug
`bashgah-arman`; the platform admin is `admin@gymflow.ai` / `password`.

MySQL 8 and Redis are what production expects — set `DB_CONNECTION=mysql`,
`QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` in `.env`. SQLite is only
for a quick local run and the test suite.

Two background pieces:

```bash
php artisan queue:work                # campaigns, notifications
php artisan schedule:work             # runs gymflow:maintenance nightly
```

`gymflow:maintenance` expires what ran out, closes check-ins left open,
sends renewal reminders, wishes anyone whose birthday it is, and refreshes
the AI insights — once per active club.

### Admin panel

```bash
cd admin
npm install
npm run dev          # proxies /api to http://127.0.0.1:8000
```

Set `VITE_API_URL` for a deployed API, or `VITE_API_PROXY` to point the dev
proxy somewhere else.

### Mobile apps

```bash
cd mobile/manager_app
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api/v1

cd ../member_app
flutter pub get
flutter run --dart-define=API_URL=http://10.0.2.2:8000/api/v1 \
            --dart-define=CLUB_SLUG=bashgah-arman
```

`CLUB_SLUG` bakes the club into a member build, so the member only types
their phone number and password. Leave it out for a build that asks.

---

## Tests

```bash
cd backend && php artisan test
```

213 feature tests covering tenant isolation, the check-in and quota engine,
booking capacity, membership lifecycle, invoicing and the cash box, the
wallet, stock, permissions per role, the member app's endpoints, the AI
engine, localisation, the audit trail, all 150 reports, campaign delivery
across every channel, online payment including a gateway that says no, two
factor sign-in, provider sign-in, backups, coach rosters, the platform
wording screen, the nightly sweep, encryption at rest, and Persian shaping
down to the individual presentation forms.

---

## Verification status

- **Backend** — migrations, seeders and the full test suite run clean here;
  the maintenance command and the demo seeder were run against a live
  database.
- **Admin panel** — builds, and was driven end to end in a headless browser
  against the running API: sign in, then every screen rendered against the
  demo club with no console errors. Doing that turned up two real bugs, both
  fixed: picking "to = today" in a report returned nothing, because a date
  picker sends a bare date that parses to midnight; and sign-ins recorded no
  actor in the audit trail, because there is no session yet when a login
  happens.
- **Mobile apps** — **not compiled.** No Flutter or Dart toolchain was
  available in this environment, so the Dart source has been written against
  the documented APIs of Flutter 3.24, `mobile_scanner`, `qr_flutter`,
  `fl_chart`, `nfc_manager` and `url_launcher` but never run through
  `flutter analyze` or a build. What was checked by hand: every relative
  import resolves, every shared model and widget referenced exists and is
  exported, every endpoint the screens call is a registered route, and all
  145 translation keys the apps ask for exist in all three languages. Expect
  to fix small things on the first `flutter pub get && flutter analyze`.

### Known limits

- **Provider sign-in is wired but unconfigured.** Google, Apple and GitHub go
  through Socialite; a provider only appears once its credentials are in
  `config/services.php`. Self signup stays off by default, so a provider
  account alone cannot walk into a club that never invited it.
- **Persian in PDFs is handled here, not by the font** — see below.
- **Mobile builds** still need a first `flutter analyze`, as above.

---

## Configuration worth knowing

`backend/config/gymflow.php` holds the platform's own settings: the supported
locales and their direction, the brand palette, the check-in duplicate window
and auto-checkout delay, when renewal reminders fire, the AI module's model
and effort, the messaging channels, the payment gateways and the backup
schedule.

Messaging and payment credentials are layered: `config/gymflow.php` is the
platform floor, and a club's own `messaging.<channel>` / `payments.<gateway>`
settings sit on top — so one gym brings its own SMS gateway without touching
anyone else's.

The AI module reads `ANTHROPIC_API_KEY`; with it unset, `GYMFLOW_AI_ENABLED`
effectively falls back to the statistical engine and the built-in program
templates.

Persian invoices work out of the box — see "Printing in Persian" above. A
club that wants its own typeface drops a TTF into `backend/storage/fonts` and
registers it in DomPDF's config.
