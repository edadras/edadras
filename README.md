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
register, a member wallet, a shop with stock movements, 18 reports with CSV
export, CRM campaigns, and member ↔ coach chat.

Seven system roles — owner, manager, reception, coach, cashier, accountant,
member — over a wildcard permission catalogue, per club and editable.

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
sends renewal reminders and refreshes the AI insights, once per active club.

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

106 feature tests covering tenant isolation, the check-in and quota engine,
booking capacity, membership lifecycle, invoicing and the cash box, the
wallet, stock, permissions per role, the member app's endpoints, the AI
engine, and localisation.

---

## Verification status

- **Backend** — migrations, seeders and the full test suite run clean here;
  the maintenance command and the demo seeder were run against a live
  database.
- **Admin panel** — builds, and was driven end to end in a headless browser
  against the running API: sign in, then every screen rendered against the
  demo club with no console errors.
- **Mobile apps** — **not compiled.** No Flutter or Dart toolchain was
  available in this environment, so the Dart source has been written against
  the documented APIs of Flutter 3.24, `mobile_scanner`, `qr_flutter` and
  `fl_chart` but never run through `flutter analyze` or a build. Expect to
  fix small things on the first `flutter pub get && flutter analyze`.

---

## Configuration worth knowing

`backend/config/gymflow.php` holds the platform's own settings: the supported
locales and their direction, the brand palette, the check-in duplicate window
and auto-checkout delay, when renewal reminders fire, and the AI module's
model and effort.

The AI module reads `ANTHROPIC_API_KEY`; with it unset, `GYMFLOW_AI_ENABLED`
effectively falls back to the statistical engine and the built-in program
templates.

Persian invoices need a font with Persian glyphs: drop a Vazirmatn TTF into
`backend/storage/fonts` and register it in DomPDF's config. Without it the
PDF renders, but Persian text comes out as boxes.
