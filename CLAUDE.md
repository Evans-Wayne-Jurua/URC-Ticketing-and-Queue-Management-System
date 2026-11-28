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
- **Database:** MySQL. Local dev runs against **MySQL Server 8.0** (the
  `MySQL80` Windows service, port 3308) via **MySQL Workbench** as the admin
  GUI - switched 2026-11-28 after XAMPP's bundled MariaDB became unusable
  (see "XAMPP MariaDB retired" below). A dedicated `urc_app`@`localhost`/
  `127.0.0.1` user exists, scoped only to the `urc_ticketing` database - never
  connect as `root` for this project.
- **Hosting:** Azure, using free Azure for Students credit (university
  email). **Live** at
  `https://urc-ticketing-queue-management-system.azurewebsites.net` -
  Azure App Service (Linux, PHP 8.3) + Azure Database for MySQL Flexible
  Server (Burstable B1ms), region UAE North (see session item 15 below for
  why not South Africa North as first planned). Frontend and API are
  served from the **same App Service** (one origin) so cookie-based
  sessions work without CORS complications.
- **Auth approach:** native PHP sessions (not JWT) — `password_hash`/
  `password_verify`, `session_regenerate_id` on login, RBAC guard function
  per protected endpoint, CSRF token header on state-changing requests.
  Kiosk and platform-display screens stay public/no-login — only Clerk
  and Supervisor need accounts.
- **Web-root boundary:** only `public/` is web-reachable (frontend files +
  `public/api/index.php` front controller). `composer.json`, `.env`,
  `src/` are never servable directly — enforced locally via Apache
  `.htaccess`, and in production via a custom nginx config (see session
  item 15) since Azure's PHP-on-Linux image runs nginx, not Apache.

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

Local dev DB: MySQL Server 8.0 (`MySQL80` service, `127.0.0.1:3308`),
database `urc_ticketing`, applied via `database/schema.sql`, connected as
`urc_app` (not root) - see "XAMPP MariaDB retired" below for why. Run the
backend locally with
`composer run serve` (or `php -S 127.0.0.1:8000 -t public public/router.php`),
then open `http://127.0.0.1:8000/` — this serves the frontend AND the API
from one origin, matching the live Azure setup too.

Git: pushed to GitHub (`Evans-Wayne-Jurua/URC-Ticketing-and-Queue-Management-System`).
`master` is the stable foundation; `development` is the active branch for
ongoing work, merged back to `master` once verified. See the numbered
list below for what's happened session-by-session.

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
12. **Added a crossfade transition between the four main screens** (kiosk/
    board/clerk/dash) - `public/js/main.js` (`showScreen`) + `public/css/
    styles.css`. GPU-cheap (opacity/transform only), ~160ms each way,
    skips entirely for `prefers-reduced-motion`. Scoped to the top-level
    tab navigation only - not the login gates or anything else toggled by
    `render()`'s 2-second poll, to avoid fighting with that. Verified with
    Playwright: sampled opacity mid-transition to confirm it's actually
    animating, confirmed rapid double-clicks between tabs don't get stuck,
    confirmed reduced-motion skips it entirely.
13. **Reverted the app to the hosting-only-remaining checkpoint** per
    request - undid the sales-reference-capture, the general `[hidden]`
    CSS fix, and the per-counter served/no-show counts (all from commits
    after `974b6f0`). Done as a new forward commit
    (`git checkout 974b6f0 -- .`), not a history rewrite - nothing was
    lost; the `pre-payments` git tag and full commit history still have
    everything if any of it's wanted back. Also: removed the "Transport
    for London" benchmark attribution text and the brand mark's
    "Working prototype" subtitle (small copy edits, user request/edit).
    Created a `development` branch off `master` for ongoing work, keeping
    `master` as the stable foundation.

### XAMPP MariaDB retired - switched local dev to MySQL Server 8.0

XAMPP's bundled MariaDB (port 3306) became permanently unable to start,
separately from the earlier `innodb_stats_persistent` hang documented
above - a **real, reproducible crash** this time (confirmed via Windows
Event Viewer: `Application Error`, `mysqld.exe`, exception `0x80000003`),
not a session-level setting. Root cause, found by running
`mysqld.exe --console` directly to watch it live rather than through the
XAMPP Control Panel: the data directory had accumulated corrupted
multi-source-replication state files - `master-*.info`/`relay-log-*.info`
files whose *names* were literally garbled fragments of previous log
messages (e.g. `master-2026@002d11@002d27...@0020succeeded@000d.info`).
MariaDB scans for all `master-*.info` files at startup (multi-source
replication support) regardless of `skip-slave-start`, tries to parse
each one as a real connection, and the resulting error cascade is what
was crashing it - seemingly self-perpetuating, since each failed parse's
error text appears to have become the *next* garbage filename. Neither
`skip-slave-start` nor forcing `master-info-repository=FILE`/
`relay-log-info-repository=FILE` in `C:\xampp\mysql\bin\my.ini` fixed it
(both still in that file, harmless, but not the actual fix), since
the crash was in the unconditional file-scan step itself, before either
setting would matter. The real fix (deleting those 8 garbage files) was
never completed at the file-permission level - Claude's delete attempts
were blocked by the auto-mode safety classifier (deleting files outside
the project directory), and the user hadn't deleted them manually before
this MySQL Server 8.0 switch was decided instead.

**Decision:** rather than keep fighting XAMPP, moved local dev to the
**already-installed, already-healthy MySQL Server 8.0** (`MySQL80`
Windows service, confirmed running fine throughout on port 3308,
completely unrelated install) managed via **MySQL Workbench**. A
dedicated `urc_app` user (not root) was created, scoped only to a fresh
`urc_ticketing` database - `database/schema.sql` applied cleanly, test
accounts reseeded via `database/seed_users.php`, full app verified
end-to-end (kiosk → board → clerk → supervisor) against the new
database. `.env` (gitignored, local-only) now points at
`127.0.0.1:3308` / `urc_app` instead of `127.0.0.1:3306` / `root`.
XAMPP itself was never uninstalled or otherwise touched - it's just no
longer used by this project. If XAMPP's MariaDB is ever wanted again,
the fix is simply deleting those 8 files from `C:\xampp\mysql\data\`
(names start with `master-` or `relay-log-`, easy to spot - nothing
else in that folder looks like that) before starting it.
14. **Added supervisor-facing staff account creation** - closes a gap the
    user noticed themselves (the clerk login page has always said "ask a
    supervisor for an account," but there was no actual feature behind
    it). `GET`/`POST /api/users`, a "Staff accounts" card on the
    Supervisor dashboard. Full detail + the privilege-escalation
    reasoning in `claudeSecure.md` - short version: `admin` is never a
    creatable role through this endpoint, only `clerk`/`supervisor`, kept
    CLI-only on purpose. Verified against the real database including
    the account it created being able to actually log in afterward.

15. **Deployed to Azure** (previously on hold, resumed this session on
    request). Live at
    `https://urc-ticketing-queue-management-system.azurewebsites.net`
    (resource group `rg-urc-ticketing`, region **UAE North** - not South
    Africa North as originally planned; the Azure for Students subscription
    turned out to be policy-restricted to a fixed set of 5 regions
    [uaenorth, switzerlandnorth, indiasouthcentral, polandcentral,
    austriaeast], confirmed via `az policy assignment list`, and UAE North
    is the closest of those to East Africa). App Service plan is **Free
    (F1)** to conserve credit; MySQL Flexible Server is **Burstable
    B1ms** (no free tier exists for the database, so this is the one
    always-billing resource - see claudeSecure.md for exact running cost
    awareness). `HTTPS Only` is enforced at the platform level.

    Two real engineering problems had to be solved, not just "click deploy":
    - **TLS to the database**: Azure Database for MySQL enforces
      `require_secure_transport=ON`, so `Database::connection()` now opts
      into PDO's SSL verification when a `DB_SSL_CA` env var is set
      (`config/azure-mysql-ca.pem` - the standard Mozilla/cURL CA bundle,
      not a single vendor-pinned cert). Local dev is unaffected (no such
      env var there).
    - **Web-root boundary under nginx, not Apache**: Azure's PHP-on-Linux
      blessed image runs **nginx + php-fpm**, not Apache - `public/.htaccess`
      and the API's `.htaccess` rewrite are silently ignored there. The
      actual docroot is `/home/site/wwwroot` (the repo root) with no portal
      option to point it at `public/`, and nginx's default template only
      hands `.php`-suffixed URLs to PHP-FPM, so clean API URLs like
      `/api/health` 404'd outright. Fixed via `startup.sh` (a custom Azure
      "Startup Command" target, committed to the repo) that installs
      `config/azure-nginx-default.conf` - a full replacement nginx config
      with `root` pointed at `public/` and an added `location /api/` block
      doing the same try-real-file-then-fall-back-to-index.php job
      `public/api/.htaccess` does under Apache. Verified afterward:
      `composer.json`/`.env`/`src/` are all unreachable by URL (404) while
      `/api/*` and all four frontend screens work normally.

    Getting there took several live misfires worth remembering for next
    time, not just the end state:
    - A custom Startup Command **replaces** the platform's default
      `php-fpm;` invocation rather than running alongside it - Azure
      silently appends `; php-fpm;` to whatever you set, so a command that
      already ends in `php-fpm;` itself produces a literal `;;` **syntax
      error that kills the whole startup script** (confirmed live via
      `az webapp log tail` - this is what actually caused the first two
      outages, not the specific commands being "wrong").
    - `git archive` was silently converting this repo's shell scripts from
      LF to CRLF on export (driven by this Windows machine's
      `core.autocrlf`), which is a bash syntax error at runtime
      (`unexpected token $'do\r'`) - invisible if you only check the git
      blob itself, only visible by unzipping the actual deploy artifact.
      Fixed permanently via `.gitattributes` (`*.sh text eol=lf`,
      `*.conf text eol=lf`), not a one-off re-save.
    - The container's own nginx default config can genuinely not exist yet
      the moment a custom Startup Command runs on a cold start (confirmed
      via log timestamps, not assumed) - `startup.sh` polls for it for up
      to 15s before editing it.
    - SCM/Kudu basic-auth credentials are disabled by default on this
      subscription's App Services now (`basicPublishingCredentialsPolicies`)
      - re-enabling it to ease debugging was deliberately **not done**
      (flagged as weakening a platform security control); `az webapp log
      tail` (AAD-authenticated, not gated by that policy) was used instead
      for all live diagnosis.

    Still genuinely open: GitHub Actions CI/CD for auto-deploy on push is
    **not set up** - every deploy so far has been a manual
    `az webapp deploy` from this machine. `.env.azure` (gitignored, local
    only) holds the generated MySQL admin/app passwords and seeded
    clerk/supervisor production account passwords - not written to
    CLAUDE.md or claudeSecure.md on purpose.

## Next steps (where to resume)

GitHub Actions CI/CD for auto-deploy on push is the one piece of the
original roadmap not yet done. Everything else - auth, RBAC, audit
logging, rate limiting, sales, offline mode, and now Azure hosting itself
- is done and verified against the live deployment.

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
