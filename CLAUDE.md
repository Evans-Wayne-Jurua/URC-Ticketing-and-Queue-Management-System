# URC Ticketing and Queue Management System

Final-year Diploma in Software Engineering personal project — **also an explicit
learning exercise**, not just a deliverable. When working on this repo, prefer
explaining the reasoning behind a choice over silently doing the most
sophisticated thing possible, and favor approaches the user can learn from
(plain code over heavy framework magic) unless that tradeoff is called out
and accepted.

## Stack decisions (and why)

- **Frontend:** vanilla HTML/CSS/JS, no framework. A React + Tailwind rewrite
  was explicitly considered and declined — the user chose to restyle/extend
  the existing vanilla prototype instead, after comparing tradeoffs (visual
  quality comes from design decisions, not the stack; migrating would mean
  rebuilding working logic from scratch for no visual upside).
- **Backend:** PHP, plain (no framework like Laravel/Slim) — Composer used
  only for PSR-4 autoloading, not for a framework. A hand-written router and
  PDO are expected, not hidden behind an ORM/framework, so the project
  demonstrates the underlying mechanics for the diploma report.
- **Database:** MySQL. Locally available via **XAMPP** (already installed —
  `C:\xampp\mysql\bin\mysql.exe`, MariaDB 10.4.32, service running).
- **Hosting:** Azure, using free Azure for Students credit (university email).
  Plan: Azure App Service (Linux, PHP 8.2+ runtime) + Azure Database for MySQL
  Flexible Server (Burstable tier). Frontend and API are meant to be served
  from the **same App Service** (one origin) so cookie-based sessions work
  without CORS complications.
- **Auth approach (planned, not yet built):** native PHP sessions (not JWT) —
  `password_hash`/`password_verify`, `session_regenerate_id` on login, RBAC
  guard function per protected endpoint, CSRF token header on state-changing
  requests. Kiosk and platform-display screens stay public/no-login — only
  Clerk and Supervisor need accounts.
- **Web-root boundary (planned):** once the backend exists, only a `public/`
  folder should be web-reachable (frontend files + `public/api/index.php`
  front controller). `composer.json`, `.env`, `src/` must never be served
  directly — this is the top PHP-on-Azure security gotcha.

## Repo structure (current)

```
index.html              markup only
css/styles.css          all styles
js/
  state/store.js        in-memory data layer — the one file that becomes
                         real API calls once the backend exists
  domain/
    queueing.js          pure Erlang-C math, no DOM — unit-testable
    i18n.js               translation content + current language
  ui/
    dom.js                $ / formatting helpers
    chart.js              SVG arrivals chart
    render.js             updates all four screens from state+domain data
  main.js                 event wiring, app bootstrap
```

Git: initialized this session, no remote yet. Commits so far (oldest first):
baseline single-file prototype → layering refactor → counter open/close
domain fix → layout fix found while testing.

## Domain model notes worth remembering

- **Kiosk = passenger self-service**, not staff. No login. Modeled on a
  physical touchscreen at the station (bilingual, no counter selection).
- **Counter "open/closed" is an administrative decision**, separate from a
  clerk being logged in and actively serving. A supervisor opens/closes a
  counter (`js/state/store.js`: `isCounterOpen`/`openCounters`/
  `openCounter`/`closeCounter`); closing is blocked while someone is being
  served there. The clerk's counter dropdown only ever lists currently-open
  counters. This maps directly to a planned `counters(number, is_open)`
  table.

## What happened this session

1. **Restyle** — reworked `css/styles.css` (and a few HTML tweaks) to a bold
   uppercase / navy + "signal" orange / elevated-card look, inspired by a
   reference React component the user liked, but implemented in the existing
   vanilla CSS rather than migrating frameworks.
2. **Separation of concerns refactor** — split the original single HTML file
   into the layered structure above (state/domain/ui/main). Behavior
   unchanged; this was a pure structural refactor, done ahead of adding a
   real backend.
3. **Git set up** — `git init`, `.gitignore` added, baseline commit, then the
   refactor commit.
4. **Backend/hosting direction decided** — PHP + MySQL + Azure (Azure for
   Students credit), discussed in detail: folder layout, schema sketch
   (services/counters/tokens/sales/users/audit_log), auth approach, security
   checklist, Azure App Service + Azure Database for MySQL setup, suggested
   build order.
5. **Fixed a real domain-model gap** — replaced the single `state.openCounters`
   count with a per-counter open/closed flag, added an actual Open/Close
   button per counter on the Supervisor screen (previously the recommendation
   card had no control to act on), and constrained the clerk's counter picker
   to only open counters.
6. **Ran and drove the app end-to-end** (Playwright, headless Chromium,
   against a local static server since ES modules block `file://`): verified
   the kiosk → board → clerk → supervisor flow, confirmed closing counters
   live-updates the clerk's dropdown and correctly disables "Call next" when
   none are open. Found and fixed a real layout bug this way — the
   counter-status rows were too narrow for the pill + new button and the text
   wrapped/collided; fixed by right-aligning pill+button on their own line.
7. **Started backend scaffolding** — confirmed the local toolchain: PHP 8.2.12,
   Composer 2.11.1, `pdo_mysql`/`pdo_sqlite` extensions all available, and
   that XAMPP's MariaDB is installed and running locally. No backend files
   created yet — this is the next thing to pick up.

## Next steps (where to resume)

1. Scaffold the PHP backend: `composer.json` (PSR-4, `App\` → `src/`),
   `public/` web-root (move `index.html`/`css/`/`js/` into it),
   `public/api/index.php` front controller + tiny router,
   `config/database.php` (PDO factory reading env vars), `database/schema.sql`
   mirroring the JS domain model, `.env.example`.
2. One proof-of-life endpoint (`GET /api/health` hitting MySQL) before
   building anything else.
3. Users table + login/logout/session + RBAC guard.
4. Port the queueing math (`js/domain/queueing.js`) to a PHP service class;
   build token endpoints; point `js/state/store.js` at the real API instead
   of in-memory state (that's the one frontend file this should touch).
5. Audit log, security headers, rate limiting on login.
6. Azure resources (App Service + Database for MySQL) + GitHub Actions CI/CD.

## Running the frontend locally

Double-clicking `index.html` no longer works — it loads `js/main.js` as an
ES module, and browsers block module imports over `file://`. Serve it over
HTTP instead, e.g.:

```
python -m http.server 8787
```

then open `http://127.0.0.1:8787/index.html`.
