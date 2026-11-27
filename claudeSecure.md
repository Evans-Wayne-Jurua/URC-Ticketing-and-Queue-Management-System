# Security — URC Ticketing and Queue Management System

This is the dedicated security reference for this project: what's
implemented, where, why, and how each control was actually verified (not
just written and assumed to work). `CLAUDE.md` has the general project
history; this file is the security detail that belongs with it, kept
separate so it doesn't get lost in day-to-day session notes.

Written 2026-11-27, after the backend (auth, token/counter endpoints,
audit logging, rate limiting) was built and tested against real MySQL.

## Threat model in one paragraph

Four screens, two trust levels. **Kiosk** and **Platform display** are
genuinely public — anyone standing at the station uses them, no account
involved, by design (see `CLAUDE.md`'s domain-model notes on why). **Clerk**
and **Supervisor** are staff-only and handle state changes (taking money
isn't wired up yet, but calling/completing/no-showing passengers and
opening/closing counters are real operational actions). The core
assumption that shapes every control below: **the browser is never
trusted.** Anyone can open devtools on any of the four screens and the
server must behave identically either way.

## Authentication

- **Native PHP sessions, not JWT** (`src/Auth/Session.php`). Chosen because
  frontend and API share one origin (same Azure App Service), which makes
  cookie sessions simpler than JWT + refresh-token rotation for no benefit
  here.
- Session cookie flags: `HttpOnly` (JS can't read it — defeats token theft
  via XSS even if one existed), `Secure` when served over HTTPS
  (automatic — checks `$_SERVER['HTTPS']`, so it's off for local HTTP dev
  and on once deployed), `SameSite=Lax`.
- `session_regenerate_id(true)` on every successful login — prevents
  session fixation (an attacker pre-setting a session ID before the victim
  logs in).
- Passwords: `password_hash()` / `password_verify()` only — PHP's own
  bcrypt, never hand-rolled, never a custom hash.
- `database/seed_users.php` creates the first clerk/supervisor accounts
  (CLI-only, refuses to run under a web server) since there's no admin UI
  yet to do it through.

## Authorization (RBAC)

- `src/Auth/Guard.php`: `requireLogin()` / `requireRole($roles)` /
  `requireCsrf()`, called **explicitly as the first line** of every
  protected controller method — no hidden middleware chain, so it's
  obvious from reading a controller what it requires.
- Role hierarchy, not silos: clerk-level endpoints accept
  `['clerk', 'supervisor', 'admin']`; supervisor-level endpoints accept
  only `['supervisor', 'admin']`. A supervisor can do everything a clerk
  can (intentional — a supervisor can step in at a counter); a clerk
  **cannot** reach supervisor endpoints. This was a direct question from
  the user during testing and was verified, not just asserted:

  ```
  clerk -> GET  /api/dashboard           -> 403
  clerk -> POST /api/counters/4/open     -> 403
  ```

  Enforced server-side in `DashboardController`/`CounterController`, so
  the result is identical whether the request comes from the real UI, a
  modified UI, or a bare `fetch()` call from devtools — the client-side
  login gate (`public/js/ui/render.js`, showing a login form instead of
  the screen) is a convenience on top, not the actual boundary.

## CSRF

- `src/Auth/Csrf.php`: a random token (`random_bytes(32)`, `hash_equals`
  for comparison — timing-safe) bound to the session, issued on
  login/`/auth/me` as `csrfToken`.
- Every state-changing endpoint (`logout`, `call-next`, `complete`,
  `no-show`, `open`, `close`) calls `Guard::requireCsrf()`, which checks
  the `X-CSRF-Token` header against the session's token.
- Why it's needed at all: auth is cookie-based, and cookies are sent
  automatically by the browser on any request to the origin — without a
  CSRF token, a malicious page could trigger a logged-in clerk's browser
  into completing/no-showing a sale just by having them visit it. The
  token has to come from JS that read it from a same-origin response,
  which a cross-site page can't do.
- Verified: a logout attempt without the header returns 403; with the
  correct header, 200.

## SQL injection

- Every single query in `src/Repositories/*.php` uses PDO bound
  parameters (`:name` placeholders + `execute([...])`). No string
  concatenation into SQL anywhere in the codebase — grep for `"' . $`
  pattern near a query to confirm if ever in doubt.
- `PDO::ATTR_EMULATE_PREPARES` is left at its default (`true`) in
  `src/Config/Database.php`. This is **not a security tradeoff** —
  emulated prepares still fully escape bound values client-side before
  sending the query; the security guarantee of parameter binding comes
  from never concatenating untrusted input into the SQL string, which
  holds in both emulated and native prepare modes. (The only reason this
  setting was ever touched was chasing an unrelated MariaDB hang — see
  `CLAUDE.md`'s debugging notes. It was not, and never was, an injection
  concern.)

## Race conditions / transaction integrity

Several actions have a real concurrency hazard if done as separate
check-then-act steps:

- **Taking a ticket number** (`TokenRepository::takeToken`): two kiosk taps
  for the same service must never get the same code. Fixed with
  `SELECT next_seq ... FOR UPDATE` inside a transaction — the row lock
  serializes concurrent increments.
- **Calling the next passenger** (`TokenRepository::callNext`): two clerks
  must never be assigned the same waiting token. `SELECT ... FOR UPDATE`
  on the candidate row before updating it.
- **Completing/no-showing** (`TokenRepository::finish`): same pattern —
  look up the serving token `FOR UPDATE`, then update it, in one
  transaction.
- **Closing a counter** (`CounterRepository::close`): must not race with a
  clerk calling next on that counter between the "is anyone being served"
  check and the update. Both happen inside one transaction with
  `FOR UPDATE` on the check.

Every transaction in the codebase follows the same shape: `beginTransaction()`
→ try → `commit()`, `catch (Throwable) → rollBack(); rethrow`. This was a
hard-won rule, not a stylistic preference — an early version of this code
(during testing, not committed) left a transaction without a guaranteed
commit/rollback path and produced a connection stuck indefinitely holding
a lock. See `CLAUDE.md` for the full incident; the practical takeaway
documented here is: **every `beginTransaction()` in this codebase must be
immediately followed by a try/catch that commits on success and rolls back
on any exception, with nothing in between that can `exit`/`die` or return
early outside that structure.**

## Rate limiting (brute force)

- `src/Auth/RateLimiter.php` + `login_attempts` table: 5 attempts per
  15 minutes, keyed on the **submitted email**, not IP.
- Why email and not IP: `REMOTE_ADDR` is unreliable behind a load balancer
  / reverse proxy (Azure App Service included) unless `X-Forwarded-For` is
  parsed and the trusted proxy chain configured — easy to get wrong and
  trivially spoofable if done wrong. Rate-limiting by the identifier the
  attacker must already guess correctly (the victim's email) is simpler
  and doesn't depend on trusting a header.
- Once tripped, the identifier is blocked entirely — **including a
  correct password** — until the window passes. Verified directly: 5 wrong
  passwords, then a 6th attempt with the *correct* password still got
  `429`. This is intentional: if attempts weren't blocked uniformly, an
  attacker could just keep trying until the one correct guess slips
  through unthrottled.

## Audit logging

- `src/Repositories/AuditLogRepository.php` writes to `audit_log` on:
  `login`, `login_failed`, `logout`, `take_token`, `call_next`,
  `complete_sale`, `no_show`, `open_counter`, `close_counter`.
- Each row: who (nullable — the kiosk's `take_token` has no user), what,
  which token/counter, when. Enough to answer "who called this number"
  or "who closed counter 2 at 14:03" after the fact, which matters for a
  shared-terminal, real-money-adjacent system even at this small scale.
- Verified with a full real sequence (login → take-token → call-next →
  complete → open-counter → logout) and confirmed every row attributed
  correctly to the right user/token/counter.

## Security headers

- Set in two places, deliberately overlapping rather than relying on one:
  `public/api/index.php` (PHP, covers API responses on any runtime
  including the `php -S` dev server) and `public/.htaccess` (Apache —
  XAMPP locally, Azure App Service in production — covers the static
  frontend files, which the PHP headers don't touch).
- `X-Content-Type-Options: nosniff` — stops the browser from MIME-sniffing
  a response into executing as something other than its declared type.
- `X-Frame-Options: DENY` — the login forms can't be framed by another
  site (clickjacking defense).
- `Referrer-Policy: no-referrer` — doesn't leak this app's URLs (which
  could include session-adjacent paths) to external sites via the
  `Referer` header.
- `Strict-Transport-Security` — tells the browser to only ever use HTTPS
  for this origin once it's seen one HTTPS response. Harmless over local
  HTTP (browsers ignore HSTS on a non-HTTPS response origin in practice
  for the initial visit); becomes meaningful the moment this is on Azure
  with TLS.
- Verified present via `curl -I` against the running API.

## Secrets and the web-root boundary

- `.env` (real DB credentials, session secret) is gitignored; `.env.example`
  (template, no real values) is tracked. In production, these come from
  **Azure App Service → Configuration → Application settings**, injected
  as real environment variables — never a committed file.
- Only `public/` is ever the web root (Apache `DocumentRoot` locally and on
  Azure). `composer.json`, `vendor/`, `src/`, `.env`, and `database/` all
  sit *outside* `public/`, so none of them are web-reachable even if
  someone guesses a path — this is the single most important PHP
  deployment detail, called out explicitly in `CLAUDE.md` too.

## Error handling that doesn't leak internals

- `HealthController` catches `PDOException`, logs the real message
  server-side via `error_log()`, and returns only a generic
  `{"status":"error"}` to the client — a failed DB connection never
  surfaces a connection string, credentials, or driver details to
  whoever's looking at the response.

## Sales input validation (added after this file was first written)

`src/Controllers/TokenController::complete` optionally accepts sale details
(`trip`, `classFee`, `qty`, `paymentMethod`) alongside completing a token.
Every field is checked against an **allow-list** matching the fixed
`<option>` values in the Quick sale form (`TokenController::ALLOWED_TRIPS`/
`ALLOWED_CLASS_FEES`/`ALLOWED_PAYMENT_METHODS`) - nothing free-text reaches
the `sales` table. Two things worth calling out specifically:

- **The total is always server-computed** (`classFee * qty` in
  `SaleRepository::record`), never taken from the client, even though a
  client could send a `total` field. Verified directly: sent `"total":
  999999` alongside valid `classFee`/`qty` and the stored row shows the
  correctly recomputed total, the tampered value silently ignored. This is
  the general rule for anything money-shaped: a number the client could
  have reason to lie about is always recomputed server-side from inputs
  that are independently constrained (here, an allow-listed fare), never
  trusted as a passed-through total.
- **Service-sale coupling is enforced server-side, not just hidden in the
  UI.** If sale data is sent for a token whose service isn't `ticket`
  (e.g. `collect`, which represents an already-paid fare), the request is
  rejected with 422 rather than silently accepted or silently ignored -
  verified directly via curl, independent of what the frontend sends.

## Offline mode: what is and isn't trusted while disconnected

Added alongside the sales endpoint. The design is **asymmetric by
intention** - not "queue every action and reconcile later," because not
every action is safe to guess at without live server state:

- **Kiosk (`public/js/main.js: takeToken`)**: never issues a fake/local
  ticket number while offline. A ticket number *is* the fairness
  mechanism (arrival order), so a provisional one that doesn't reflect
  real order relative to everyone else taking a number in the meantime
  would misrepresent fairness once synced. Instead it retries the real
  request automatically and transparently (`public/js/state/offline.js`
  distinguishes a genuine network failure from an HTTP error response)
  until it actually succeeds.
- **Clerk - "Call next" / "No-show" are blocked offline entirely**
  (`render.js` disables both buttons via `isOffline()`). Both depend on
  accurate live knowledge of the shared waiting/serving state across all
  counters; guessing from stale local data risks two counters serving the
  same token, or skipping someone. Refusing outright is safer than acting
  on data that might already be wrong.
- **Clerk - "Complete sale" is the one action allowed to queue offline**
  (`public/js/state/pendingSales.js`, localStorage). The reasoning: by the
  time a clerk clicks it, the real-world transaction (passenger served,
  money collected if any) has already happened - refusing to record it
  because of a network blip would be worse than queuing it, and it's
  scoped to a token this clerk's own counter already owned before going
  offline, so there's no cross-counter race to get wrong. The local UI
  optimistically marks that counter's "now serving" as cleared
  (`store.js: markLocallyCompleted`) so the clerk isn't blocked from the
  (still-offline-disabled) next step, without pretending the server knows
  yet.
- **Sync-failure handling is explicit, not silent.** When connectivity
  returns, queued sales replay in order on the next successful poll. If a
  replay fails with a genuine network error, it stays queued and retries
  next time. If it fails with a real HTTP error (meaning server-side state
  diverged from what the clerk's screen assumed - e.g., something else
  already changed that token/counter), the item is dropped **and an alert
  is shown** naming the counter and asking to check with a supervisor,
  rather than silently losing the record. A diploma-report-worthy
  principle generally: an offline queue that can fail to replay must
  surface that failure somewhere a human will see it, not just retry
  forever or drop quietly.

Verified with Playwright's real network simulation
(`browserContext.setOffline(true)`), not just code review: confirmed the
button states, the localStorage queue contents mid-outage, the queued sale
correctly appearing in the `sales` table within one poll cycle of
reconnecting, and the kiosk recovering automatically to a real issued
ticket once back online.

## What's deliberately NOT done yet (known gaps, not oversights)

- **No admin UI/endpoints** — the `admin` role exists in the schema and
  every `requireRole` check that includes `supervisor` also includes
  `admin`, but nothing creates or manages admin accounts yet.
- **No password complexity/rotation policy** — `password_hash` is used
  correctly, but nothing stops a weak password being set. Low priority at
  this scale (a handful of staff accounts), worth a line in the diploma
  report as a known limitation rather than silently ignored.
- **No dependency scanning configured** (`composer audit` exists as a
  manual command; nothing runs it in CI yet — there is no CI yet).
- **Offline mode and the sales endpoint are now implemented** (see the
  dedicated sections above) — this bullet previously said they weren't;
  left here struck-through in spirit so the history is clear. One real
  remaining gap from that work: a queued "Complete sale" is replayed using
  whatever CSRF token/session is current *at sync time*, not a snapshot
  from when it was queued. If the device is offline long enough for the
  PHP session to actually expire before reconnecting, the replay fails
  with a real auth error (not a network error), and per the current logic
  that's treated as "state diverged" — the item is dropped and the clerk
  is alerted, not silently retried forever. That's the right failure mode
  (visible, not silent) but it does mean a long enough outage requires the
  clerk to log back in and manually redo that specific sale. Acceptable
  for the realistic short outages this was designed around; worth a line
  in the diploma report if asked about edge cases.
- **Replication threads** noticed on the local MariaDB instance
  (`Slave_SQL`, idle) were not part of anything this project configured —
  flagged for the user to check, not assessed further here since they
  appear unrelated and idle.

## How to re-verify any of this later

Every claim above was checked with real requests against the real backend
(MySQL via XAMPP), not inferred from reading the code. The exact commands
used are in this session's history; the shape to repeat for any of them:
log in via `POST /api/auth/login`, capture the cookie jar + `csrfToken`,
then hit the endpoint in question with/without the right role, CSRF
header, or request volume, and check the status code and `audit_log`/
`login_attempts` tables directly.
