# URC Ticketing and Queue Management System

Final-year Diploma in Software Engineering personal project — **also an explicit
learning exercise**, not just a deliverable. When working on this repo, prefer
explaining the reasoning behind a choice over silently doing the most
sophisticated thing possible, and favor approaches the user can learn from
(plain code over heavy framework magic) unless that tradeoff is called out
and accepted.

**Security-specific documentation lives in `claudeSecure.md`** (threat model,
every control implemented and why, how each was verified, and known gaps) -
keep that file updated alongside this one whenever auth/CSRF/RBAC/rate
limiting/audit logging/headers change, rather than duplicating the detail
here.

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
public/                  the ONLY web-reachable folder (Apache/Azure doc root)
  index.html              markup only
  css/styles.css          all styles
  js/
    state/store.js         in-memory data layer — the one file that becomes
                            real API calls once the frontend is wired to the API
    domain/
      queueing.js           pure Erlang-C math, no DOM — unit-testable
      i18n.js                translation content + current language
    ui/
      dom.js                 $ / formatting helpers
      chart.js               SVG arrivals chart
      render.js              updates all four screens from state+domain data
    main.js                  event wiring, app bootstrap
  api/
    index.php               front controller (dispatches via src/Http/Router)
    .htaccess                routes unmatched requests to index.php (Apache)
  router.php               equivalent routing for `php -S` (local dev only;
                            the built-in server ignores .htaccess)
src/                      PHP backend, PSR-4 autoloaded as App\
  Support/Env.php          minimal .env loader (local dev only)
  Config/Database.php      PDO factory, reads DB_* env vars
  Http/Router.php           small method+path-pattern router
  Controllers/
    HealthController.php    GET /api/health — proves router→DB pipe works
  routes.php                route table
database/schema.sql       services/counters/tokens/sales/users/audit_log +
                          seed data matching the frontend's current defaults
composer.json             PSR-4 autoload only, no framework/dependencies yet
.env.example              template; real .env is local-only (gitignored)
```

Local dev DB: XAMPP's MariaDB, database `urc_ticketing`, applied via
`database/schema.sql`. Run the backend locally with
`composer run serve` (or `php -S 127.0.0.1:8000 -t public public/router.php`),
then open `http://127.0.0.1:8000/` — this serves the frontend AND the API
from one origin, matching the planned Azure setup.

Git: initialized this session, no remote yet. Commits so far (oldest first):
baseline single-file prototype → layering refactor → counter open/close
domain fix → layout fix found while testing → CLAUDE.md added → PHP backend
scaffold (frontend moved into public/, composer/router/schema/health-check).

## Why move state to the API (not just keep it in-memory)

Discussed and decided 2026-11-27, before wiring the frontend to the backend:

- **Security is the real reason, not memory.** With state held in the
  browser's JS (the original prototype), there is no enforcement boundary -
  anyone can open devtools and call `mkToken()` directly, edit `state` to
  fake a "served" count, or bypass the "supervisor-only" counter toggle,
  because client and server are the same trust domain. The restriction is
  a UI affordance, not a real one. With the API, the server enforces it:
  a tampered browser still can't call-next or complete a sale without a
  session cookie the server validates and a role it checks
  (`Guard::requireRole`), and every timestamp (`t1`/`t2`/`t3`) is set by
  the database's `NOW()`, never trusted from the client. That's the core
  client/server security principle - never trust the client - and it's
  categorically different from what existed before, not incremental.
- **Memory efficiency is a wash at this app's scale** (a handful of tokens/
  counters) - not a real argument either way. Where state location *does*
  matter: kiosk, platform display, clerk, and supervisor are meant to be
  separate physical devices, not browser tabs sharing one in-memory object.
  The in-memory version only "worked" because everything happened to run
  in one tab. Moving state into the database means all four screens see
  one real, shared, authoritative state instead of each independently
  simulating its own, and each client's own memory footprint shrinks too,
  since it fetches only the slice it needs instead of holding the whole
  simulated history.
- **Open design question, not yet solved:** the clerk screen already has an
  "offline - sales saved locally, will sync" banner baked into its copy,
  foreshadowing exactly this problem. Wiring to the API does not by itself
  give offline support - that needs its own design pass (a local queue of
  pending actions + sync-on-reconnect) if it's wanted later. Don't assume
  it's handled just because the API exists.

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
7. **Scaffolded the PHP backend and verified it end-to-end**: moved the
   frontend into `public/` (web-root boundary), added `composer.json`
   (PSR-4 only, no framework), a minimal `.env` loader, a PDO `Database`
   factory, a small `Router`, `database/schema.sql` (mirrors the JS domain
   model exactly, including `counters.is_open`), and a first
   `GET /api/health` endpoint. Confirmed locally: schema applied to XAMPP's
   real MariaDB, PHP's built-in server smoke-tested (health check hits the
   live DB, unknown routes 404), and the full kiosk → board → clerk →
   supervisor flow re-driven with Playwright through the PHP server with
   zero console errors — same result as the earlier pure-frontend test, so
   moving everything into `public/` didn't break anything.

8. **Added session-based auth**: `src/Auth/Session.php` (secure/httponly/
   samesite cookies, regenerate-id on login), `Csrf.php` + `Guard.php`
   (`requireLogin`/`requireRole`/`requireCsrf`, called explicitly at the top
   of a protected controller method — no hidden middleware chain),
   `UserRepository` + `AuthService` (`password_verify`, no custom hashing),
   `AuthController` wired to `POST /auth/login`, `POST /auth/logout`
   (CSRF-protected), `GET /auth/me`. `database/seed_users.php` creates the
   first clerk/supervisor test accounts (no admin UI yet). Verified against
   real XAMPP MySQL: wrong password → 401, correct login → 200 + user +
   csrfToken, `/auth/me` reflects the session, logout without CSRF → 403,
   with CSRF → 200, `/auth/me` → 401 after.

### Debugging note worth keeping: "it just hangs" on a parameterized query

While testing login, every query against `users` with a bound parameter
hung indefinitely (`SELECT ... WHERE email = :email`), while unparameterized
queries (`SELECT 1`) worked fine. This looked exactly like a known
XAMPP/Windows PDO-MySQL native-prepared-statement bug, and `EMULATE_PREPARES
=> true` was tried as a fix — **but that was a red herring.** The real cause,
confirmed via `SHOW PROCESSLIST` / `information_schema.innodb_trx`: a
transaction from the very first hung request that session had been sitting
open the whole time, holding a lock on `users`, so every subsequent query
was queuing behind it in "Opening tables" state — regardless of what PDO
setting was tried. `KILL <id>` on the connections didn't clear it (the
thread was wedged at the engine level); only a full MySQL restart
(XAMPP Control Panel → Stop, then Start) did.

**If a query against a specific table seems to hang for no code reason:**
check `SHOW PROCESSLIST` and `SELECT * FROM information_schema.innodb_trx`
first, before suspecting PDO settings, PHP code, or the built-in dev
server. A long-running "Opening tables"/"Statistics" state across several
connections, plus one old `RUNNING` transaction, means restart MySQL rather
than debugging the query.

**Root cause, found the second time it happened:** it's InnoDB's persistent
statistics recalculation (`innodb_stats_persistent`/`innodb_stats_auto_recalc`,
both ON by default) hanging on this XAMPP/MariaDB install the first time a
query touches a given table in a session. Fixed for the current server
session with:

```sql
SET GLOBAL innodb_stats_persistent = 0;
SET GLOBAL innodb_stats_auto_recalc = 0;
```

**This does not persist across a MySQL restart** (it's a session-lifetime
`SET GLOBAL`, not a config file change). If the hang reappears after
restarting MySQL, re-run those two statements before doing anything else -
don't re-debug the PHP/PDO code, it was never the cause. For a permanent
fix, add `innodb_stats_persistent=0` and `innodb_stats_auto_recalc=0` under
`[mysqld]` in XAMPP's `my.ini` (not done yet - wasn't asked for, and it's a
system config file outside the project).

Also noticed: this MariaDB instance has several idle `Slave_SQL` replication
threads ("waiting for the slave I/O thread to update it"). Unrelated to the
hang (confirmed - they're idle, not holding locks) but unusual for a local
XAMPP install; worth the user knowing in case it's leftover config from
something else, not something this project set up.

9. **Wired the frontend to the real API.** `js/state/store.js` is no longer
   an in-memory "database" - it's a small cache (`auth`/`services`/
   `counters`/`board`/`queue`/`dashboard`) refreshed by polling the API
   every 2s via the new `js/state/api.js` fetch wrapper (credentials + CSRF
   header on writes). `js/domain/queueing.js` is deleted - that math now
   lives only in `src/Services/QueueingService.php`; the dashboard renders
   server-computed numbers directly. Clerk and Supervisor screens are now
   gated behind real login forms (`#clerkLogin`/`#dashLogin` in
   `public/index.html`); Kiosk and Platform display stay public. Added
   URL-hash deep-linking (`#kiosk`/`#board`/`#clerk`/`#dash`) so a
   presentation can point separate devices/windows at one screen each.
   "Reset demo" was removed (no backend endpoint for it); "Simulate
   arrivals" now drives the real API instead of faking local data.

   Verified end-to-end with Playwright against the real backend: the full
   kiosk → board → clerk-login → call-next/complete → supervisor-login →
   dashboard → counter open/close → deep-link-reload flow. Caught a real
   CSS bug this way (not a JS bug): `.kiosk-wrap{display:flex}` was beating
   the `[hidden]` attribute at equal specificity, so both login forms
   stayed visible after a successful login. Fixed with a targeted
   `.kiosk-wrap[hidden]{display:none}` rule in `public/css/styles.css`. If
   a `hidden` element ever "doesn't hide" again, suspect exactly this -
   check whether its class sets `display` anywhere before assuming a JS bug.
10. **Audit logging, login rate limiting, security headers** - see
    `claudeSecure.md` for the full detail (this file no longer duplicates
    it). Short version: every state-changing action writes to `audit_log`;
    failed logins are throttled per-email (5/15min) via a new
    `login_attempts` table; `X-Content-Type-Options`/`X-Frame-Options`/
    `Referrer-Policy`/`Strict-Transport-Security` are set both in PHP
    (`public/api/index.php`, covers any runtime) and `public/.htaccess`
    (covers static files under Apache - XAMPP/Azure).
11. **Sales endpoint + offline mode for kiosk/clerk** - also fully detailed
    in `claudeSecure.md`. The key design decision, worth remembering: the
    offline handling is deliberately **asymmetric**, not "queue everything
    and sync later":
    - Kiosk retries automatically and transparently on a real network
      failure rather than issuing a fake provisional ticket number -
      doing the latter would misorder the real fairness queue once synced.
    - Clerk: "Call next"/"No-show" are blocked offline (they need live
      shared queue state); "Complete sale" keeps working and queues to
      `localStorage` (`public/js/state/pendingSales.js`), because it only
      finalizes a sale that already physically happened at a counter this
      clerk already owns. Auto-flushes on the next successful poll.
    - Verified with a real Playwright `context.setOffline(true)` run, not
      just unit-level reasoning: buttons disable/stay-enabled correctly,
      sale queues and later appears correctly in the `sales` table, kiosk
      recovers and gets a real ticket once reconnected.

## Next steps (where to resume)

Azure resources (App Service + Database for MySQL) + GitHub Actions CI/CD -
**on hold, explicitly deferred by the user for now.** Don't start this
without being asked again. Everything else from the original roadmap
(auth, RBAC, audit logging, rate limiting, sales, offline mode) is done.

### Current API surface (`src/routes.php`)

```
GET  /api/health                        public - DB connectivity check
POST /api/auth/login                    public
POST /api/auth/logout                   requires session + CSRF header
GET  /api/auth/me                       requires session
GET  /api/services                      public - kiosk service list
GET  /api/board                         public - platform display
GET  /api/queue                         clerk/supervisor/admin
GET  /api/dashboard                     supervisor/admin
GET  /api/counters                      public
POST /api/counters/{n}/open             supervisor/admin, CSRF
POST /api/counters/{n}/close            supervisor/admin, CSRF (409 if serving)
POST /api/tokens            {service}   public - kiosk takes a number
POST /api/counters/{n}/call-next        clerk/supervisor/admin, CSRF
POST /api/counters/{n}/complete  [sale] clerk/supervisor/admin, CSRF - optional
                                         {trip,classFee,qty,paymentMethod};
                                         required+validated for a "ticket"
                                         service token, 422 for any other
POST /api/counters/{n}/no-show          clerk/supervisor/admin, CSRF
```

All verified against real MySQL this session (full kiosk → clerk →
supervisor flow, RBAC 403s, 409/422 on invalid state transitions, sale
validation, offline queuing/sync).

## Running the frontend locally

Double-clicking `index.html` no longer works — it loads `js/main.js` as an
ES module, and browsers block module imports over `file://`. Serve it over
HTTP instead, e.g.:

```
python -m http.server 8787
```

then open `http://127.0.0.1:8787/index.html`.
