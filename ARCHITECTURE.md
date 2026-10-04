# StockPulse — Architecture Documentation

> **Source-grounded analysis of the actual workspace** (traced from the source code,
> not from the README or GitHub repository).
>
> **Archify status:** Archify is NOT installed or configured in this OpenCode
> environment — it is not present in the available skills list, and no Archify
> tool, configuration, or files exist under `~/.config/opencode` or inside the
> project (only OpenCode's own `node_modules` internals exist there). Per the
> constraint against installing unrelated dependencies, the equivalent
> architecture analysis was performed directly against the source code.
>
> **Files created by this analysis:** `ARCHITECTURE.md` (this file).
> **Files modified:** none. **Files not touched:** all 13 existing project files
> (`.gitignore`, `README.md`, `schema.sql`, `api/v1/valuation.php`,
> `config/Database.php`, `controllers/StockController.php`, `models/StockModel.php`,
> `public/index.php`, `public/css/style.css`, `public/js/stock.js`,
> `views/dashboard.php`, `views/layout/footer.php`, `views/layout/header.php`).

---

## 1. Complete Architecture Overview

StockPulse is a **custom lightweight MVC** application (no framework, no
composer dependencies, no services layer, no middleware, no ORM). The actual
layering found in the code:

```
User
  ↓
Frontend / Browser
  (views/layout/header.php + views/dashboard.php + views/layout/footer.php,
   public/css/style.css, Bootstrap 5.3.3 + Bootstrap Icons via CDN,
   public/js/stock.js — jQuery Ajax handlers)
  ↓  HTTP (HTML pages + JSON Ajax)
Application entry point
  public/index.php          ← front controller (page mode)
  controllers/StockController.php  ← direct JSON endpoint (Ajax mode)
  ↓
Custom Router (closure table in public/index.php: [HTTP method][path] → action)
  ↓
Controllers / handlers
  StockController (renderDashboard(), handleAjax('transfer'|'login'|'logout'))
  ↓  (no service/middleware layer — controller talks to the model directly)
Models / data access
  StockModel (all SQL, PDO prepared statements, transactional transferStock())
  ↓
Database (config/Database.php — Singleton PDO)
  MySQL 8.x / MariaDB, InnoDB, utf8mb4
  ↓
Response
  HTML (page mode)  |  JSON {status, message|data} (Ajax/API mode)
```

Additional entry point: `api/v1/valuation.php` (standalone REST endpoint,
bypasses the router, instantiates `StockModel` directly).

**Not present in the codebase (and therefore not part of the architecture):**
service layer, middleware, dependency injection container, ORM, migrations
framework, queue/worker/background jobs, JWT, caching layer, tests, Docker,
CI/CD, file uploads, external API integrations (only CDN assets at runtime).

---

## 2. Directory / Component Responsibility Map

| Path | Responsibility |
|---|---|
| `public/index.php` | Front controller: session bootstrap, `STOCKPULSE_FRONT_CONTROLLER` flag, `BASE_URL`, class autoloader (explicit class map), closure-based router, 404 fallback |
| `controllers/StockController.php` | Dual-purpose: (a) renders dashboard (page mode when included by front controller), (b) direct JSON endpoint `?action=transfer\|login\|logout` (API mode). Input validation, CSRF check, session auth check, JSON headers, delegates to `StockModel` |
| `models/StockModel.php` | Data access layer: every SQL statement, PDO prepared statements, aggregate queries for dashboard/API, and the transactional `transferStock()` with row locking |
| `config/Database.php` | Singleton PDO connection: lazy `getInstance()`, private constructor, real prepared statements (`ATTR_EMULATE_PREPARES=false`), exceptions, credentials in a `$settings` array |
| `views/layout/header.php` | `<head>`, Bootstrap 5 + Icons CDN CSS, custom CSS, CSRF meta tag, `window.STOCKPULSE_API_URL` / `STOCKPULSE_BASE_URL` JS globals, navbar (login/logout UI) |
| `views/dashboard.php` | KPI cards, warehouse stock overview table (per-zone badges, status badges), recent-movements table, transfer modal (`#transferForm`), login modal (`#loginForm`) |
| `views/layout/footer.php` | Footer, render-time display, Bootstrap JS bundle, `stock.js` |
| `public/css/style.css` | Custom styles (KPI icon tiles, badges, alerts, modal polish) |
| `public/js/stock.js` | jQuery IIFE: Ajax POST for transfer/login/logout, CSRF injection, Bootstrap alerts, 404 fallback from direct endpoint to front-controller routes, button busy states |
| `api/v1/valuation.php` | GET-only REST endpoint: total inventory value, low-stock count/products, recent movements as JSON |
| `schema.sql` | DDL + demo seed data (5 tables, FKs, CHECKs, indexes, 3 users, 4 categories, 8 products, 4 zones, 8 log rows) |
| `README.md` | Setup guide, architecture summary, API docs |
| `.gitignore` | Logs, local config, editor/OS files |

**Runtime environment used to run the app (external to the repo, set up in this
workspace):** PHP 8.3.35 NTS (portable, `C:\Users\M Dinesh\tools\php` with a
custom `php.ini` enabling `pdo_mysql`), MySQL 8.0.46 noinstall ZIP
(`C:\Users\M Dinesh\tools\mysql` with `my.ini`), PHP built-in server on
`127.0.0.1:8000` with the project root as docroot. These are environment
artifacts, not repository content.

---

## 3. Main Request Lifecycle (user request flow)

```
Browser request
  ↓
public/index.php
  ├─ session_start()
  ├─ define STOCKPULSE_FRONT_CONTROLLER, APP_ROOT, BASE_URL
  ├─ spl_autoload_register (class map: Database, StockModel, StockController)
  └─ Router: normalize path relative to script dir
        GET  ''          → StockController::renderDashboard()
        GET  'dashboard' → StockController::renderDashboard()
        POST 'transfer'  → StockController::handleAjax('transfer')
        POST 'login'     → StockController::handleAjax('login')
        POST 'logout'    → StockController::handleAjax('logout')
        anything else    → 404 HTML
  ↓
renderDashboard()
  ├─ 8 model queries (see §5/§6)
  └─ require views/layout/header.php → views/dashboard.php → views/layout/footer.php
  ↓
HTML response (text/html)
```

Parallel entry path (used by `stock.js`): `POST controllers/StockController.php?action=<a>`
skips the router — the bottom of `StockController.php` checks
`!defined('STOCKPULSE_FRONT_CONTROLLER')` and dispatches to `handleAjax()`,
which forces `Content-Type: application/json; charset=utf-8` + `Cache-Control: no-store`.

---

## 4. Authentication Flow

Sessions only (PHP native file sessions, no JWT, no remember-me, no RBAC enforcement).

```
Login modal (#loginForm)
  → stock.js: e.preventDefault(), serialize + csrf_token
  → POST .../StockController.php?action=login   (front-controller /login on 404)
  → StockController::login()
      1. method !== POST            → 405 JSON
      2. csrfValid() via hash_equals → 403 JSON
      3. FILTER_VALIDATE_EMAIL + non-empty password → 422 JSON
      4. StockModel::getUserByEmail() — prepared statement
      5. password_verify($password, bcrypt_hash)     → 401 JSON
      6. session_regenerate_id(true); set
         $_SESSION['user_id' | 'full_name' | 'role']
  → {"status":"success","message":"Welcome, <name>!"}
  → stock.js: green alert, location.reload() after 900 ms
```

Logout: `POST ?action=logout` → clears `$_SESSION`, expires the session cookie
(`setcookie` with past timestamp), `session_destroy()` → JSON → page reload.

**Authorization: none.** `$_SESSION['role']` is stored and displayed, but never
checked — any authenticated user (staff/manager/admin) can perform transfers.
The dashboard (GET routes) is viewable without login; only `transfer` requires
`$_SESSION['user_id']` (401 otherwise).

---

## 5. Main Business Workflow — Zone-to-Zone Stock Transfer

```
Transfer modal (#transferForm: product_id, from_zone, to_zone, quantity, csrf_token)
  → stock.js submit handler (Ajax, button busy state)
  → StockController::transfer()
      1. POST only                        → 405
      2. CSRF token (hash_equals)         → 403
      3. isset($_SESSION['user_id'])      → 401
      4. filter_input: product_id/quantity (VALIDATE_INT),
         from_zone/to_zone (SANITIZE_SPECIAL_CHARS) → 422
  → StockModel::transferStock($productId, $fromZone, $toZone, $quantity, $userId)
      guard clauses: quantity > 0, zones non-empty, zones differ (case-insensitive)
      beginTransaction()
        a. SELECT quantity ... WHERE product_id=? AND zone_name=? FOR UPDATE
             └─ InnoDB row lock: prevents concurrent overdraw (race protection)
        b. source row missing        → rollBack + error
        c. available < quantity      → rollBack + error (with available count)
        d. UPDATE warehouse_stock SET quantity = quantity - :quantity  (source)
        e. INSERT INTO warehouse_stock ... ON DUPLICATE KEY UPDATE
             quantity = quantity + VALUES(quantity)                    (destination upsert
             — relies on UNIQUE(product_id, zone_name))
        f. INSERT INTO stock_logs (product_id, user_id, 'transfer', quantity, notes)
      commit()  |  catch (Throwable) → rollBack + error_log + generic 400 JSON
  → {"status":"success","message":"Transferred N unit(s) ..."}
  → stock.js: success alert, modal hide, location.reload() after 900 ms
```

Audit trail: every successful transfer appends a `stock_logs` row with
`movement_type='transfer'` and a server-generated `notes` string
(`"Transferred N unit(s) from X to Y"`).

---

## 6. Database Architecture

**Technology:** MySQL 8.x / MariaDB (verified running: MySQL 8.0.46),
InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, accessed exclusively via PDO.

**ER model (source: `schema.sql`):**

```
┌──────────────────┐      ┌───────────────────────┐
│ users            │      │ categories            │
│ user_id PK       │      │ category_id PK        │
│ full_name        │      │ category_name UNIQUE  │
│ email UNIQUE     │      └───────────┬───────────┘
│ password_hash    │                  │ 1:n (FK RESTRICT)
│ role ENUM        │                  ↓
│ created_at       │      ┌───────────────────────┐
└────────┬─────────┘      │ products              │
         │                │ product_id PK         │
         │ 1:n (FK        │ sku UNIQUE            │
         │  RESTRICT)     │ product_name          │
         │                │ category_id FK → categories
         │                │ unit_price DECIMAL(10,2)
         │                │ reorder_level INT     │
         │                │ created_at            │
         │                └────┬──────────┬───────┘
         │                     │          │
         │                     │ 1:n      │ 1:n
         │                     │          │
         ↓                     ↓          ↓
┌──────────────────────┐  ┌────────────────────────────┐
│ stock_logs           │  │ warehouse_stock            │
│ log_id PK BIGINT     │  │ stock_id PK              │
│ product_id FK        │  │ product_id FK (CASCADE)    │
│ user_id FK           │  │ zone_name VARCHAR(60)      │
│ movement_type ENUM   │  │ quantity INT CHECK(>=0)    │
│ quantity CHECK(>0)   │  │ updated_at (auto ON UPDATE)│
│ notes VARCHAR(255)   │  │ UNIQUE(product_id,zone)    │
│ logged_at TIMESTAMP  │  └────────────────────────────┘
│ INDEX(product_id)    │
│ INDEX(logged_at)     │
└──────────────────────┘
```

**Relationships:** `categories 1—n products`; `products 1—n warehouse_stock`
(one row per product+zone — the "zone" dimension is stored as data, not a
table); `products 1—n stock_logs`; `users 1—n stock_logs`.

**Normalization:** 3NF — no repeating groups, all non-key attributes depend on
the PK, transitive dependencies removed (category name, user name live in their
own tables).

**Key queries (all prepared statements):**
- Dashboard summary: `products LEFT JOIN categories LEFT JOIN warehouse_stock`
  with `GROUP BY` + `COALESCE(SUM(ws.quantity),0)`
- Zone grouping: single `SELECT product_id, zone_name, quantity` indexed in PHP
  (avoids N+1)
- Low stock: `GROUP BY ... HAVING total_quantity <= reorder_level`
- Valuation: `SUM(p.unit_price * ws.quantity)`
- Transfer: `SELECT ... FOR UPDATE` → `UPDATE` → `INSERT ... ON DUPLICATE KEY
  UPDATE` → `INSERT` log

**Transactions & concurrency:** `transferStock()` is the only transactional
write path — `beginTransaction()` / `commit()` / `rollBack()` with `SELECT …
FOR UPDATE` row-level locking on the source zone row. Reads are autocommitted.

**Data flows:** dashboard renders aggregates; transfers mutate
`warehouse_stock` and append `stock_logs`; the API recomputes aggregates on
every call (no caching, no materialized totals).

---

## 7. API Architecture

All endpoints actually implemented (no others exist):

| # | Method | Route | Purpose | Auth | Handler |
|---|--------|-------|---------|------|---------|
| 1 | GET | `/public/` or `/public/dashboard` | HTML dashboard | None (viewable without login) | `renderDashboard()` |
| 2 | POST | `/public/transfer` **and** `/controllers/StockController.php?action=transfer` | Transfer stock between zones | Session + CSRF | `transfer()` → `transferStock()` |
| 3 | POST | `/public/login` **and** `...?action=login` | Authenticate staff | CSRF | `login()` → `password_verify` |
| 4 | POST | `/public/logout` **and** `...?action=logout` | Destroy session | Session + CSRF | `logout()` |
| 5 | GET | `/api/v1/valuation.php` | Inventory metrics JSON | **None — public** | standalone script |

**Endpoint 5 detail (valuation.php):**
- Request: GET only (else `405` + `Allow: GET`)
- Logic: `getTotalInventoryValue()`, `getLowStockProducts()`,
  `getRecentMovements(5)`, `getTotalSkuCount()`
- Response 200: `{status, generated_at, data:{total_inventory_value,
  total_inventory_value_formatted, currency, total_skus, low_stock_count,
  low_stock_products[], recent_movements[]}}`
- Errors: `405` wrong method, `500` compute failure, `503` DB down

**Endpoint 2 detail (transfer):** request fields `product_id`, `from_zone`,
`to_zone`, `quantity`, `csrf_token` (form-urlencoded); validation chain
405→403→401→422→business rules; responses 200 success / 400 business error
(insufficient stock, unknown zone) / 401 / 403 / 405 / 422.

**API request/response flow:**

```
Client (stock.js $.ajax or curl)
  → POST ?action=transfer (+csrf_token)
  → StockController::handleAjax() [JSON headers]
  → validation chain
  → StockModel::transferStock() [transaction]
  → JSON response (success or error)
  → stock.js renders Bootstrap alert in #alertContainer
```

---

## 8. Security Architecture

**Mechanisms that actually exist:**

| Concern | Implementation (source) |
|---|---|
| SQL injection | PDO prepared statements everywhere; `ATTR_EMULATE_PREPARES=false`; zero string concatenation into SQL |
| Password storage | `password_hash()` bcrypt (`$2b$`, cost 12) in `users.password_hash`; `password_verify()` at login (timing-safe) |
| CSRF | Per-session `random_bytes(32)` token; hidden field in both forms; `hash_equals()` comparison; 403 on mismatch |
| XSS | `htmlspecialchars(..., ENT_QUOTES)` on all echoed values; `FILTER_SANITIZE_SPECIAL_CHARS` on zone inputs |
| Session security | `session_regenerate_id(true)` on login; cookie expiry + `session_destroy()` on logout |
| Input validation | `FILTER_VALIDATE_INT`, `FILTER_VALIDATE_EMAIL`, guard clauses (quantity>0, zones differ, non-empty) |
| Error disclosure | `display_errors=0` in both entry points; `error_log()` server-side; generic client messages |
| HTTP semantics | Explicit status codes (400/401/403/404/405/422/500/503) and JSON `Content-Type` headers |

**Weaknesses (real, in current code):**
- **No authorization** — role is stored but never enforced; any logged-in user can transfer.
- **No rate limiting / brute-force protection** on login.
- **No password policy** (any password accepted at seeding time).
- **DB credentials hardcoded** in `config/Database.php` (defaults: `root` / empty password) and committed to a public repo.
- **Valuation API is unauthenticated** — exposes inventory value, low-stock list, movement history incl. staff full names.
- **No security headers** (no CSP, no `X-Frame-Options`, no HSTS); session cookie `secure`/`samesite` flags are not set by app code (depend on `php.ini`).
- **Error messages leak internals** — zone names and available quantities are returned to the client.
- CSRF token is per-session, not per-request (weaker than per-request tokens).
- Login credentials travel in plaintext unless TLS is terminated in front.

---

## 9. Error-Handling Architecture

```
Layer                     Behavior
─────────────────────────────────────────────────────────────────────
Database (Singleton)      PDOException in constructor → error_log() + HTTP 503
                          + generic JSON "Service temporarily unavailable."
Model (transferStock)     Throwable → inTransaction()? rollBack() : —
                          → error_log() + generic 400 JSON (no internals)
Controller (Ajax)         405 wrong method · 403 bad CSRF · 401 not logged
                          in / bad credentials · 422 invalid input ·
                          400 business rule (insufficient stock, unknown
                          zone, same zone) · 400 unknown action
Router (index.php)        unmatched (method, path) → 404 HTML
API (valuation.php)       405 non-GET · 500 compute failure · 503 DB down
Frontend (stock.js)       JSON error message → red Bootstrap alert;
                          network failure → fallback message;
                          404 from direct endpoint → automatic retry via
                          front-controller route
Logging                   error_log() only (PHP error log); no log file in
                          repo, no log framework, no structured logging
```

---

## 10. Deployment / Runtime Architecture

**The repository contains NO deployment infrastructure** — no Dockerfile, no
docker-compose, no CI/CD pipelines, no `.github/`, no deploy scripts, no
environment-variable config. Deployment is manual (see README "Quick Start").

**Observed runtime in this workspace (environment setup, not repo content):**

```
Workstation (Windows, no admin rights)
  ├─ PHP 8.3.35 NTS (portable ZIP, custom php.ini: pdo_mysql,
  │   session.save_path, date.timezone, display_errors=Off)
  │    └─ built-in server: php -S 127.0.0.1:8000 -t <project root>
  ├─ MySQL 8.0.46 (noinstall ZIP, my.ini: datadir, port 3306,
  │   utf8mb4_unicode_ci, innodb_buffer_pool_size=64M)
  │    └─ mysqld --console (no Windows service)
  └─ Browser → http://localhost:8000/public/
```

**Background/asynchronous processes: none.** No cron, no queues, no
workers. The only asynchronous behavior is client-side jQuery Ajax.

**External integrations: none** — only Bootstrap 5.3.3, Bootstrap Icons
1.11.3, and (as intended) jQuery from jsDelivr CDN at runtime.

---

## 11. Architecture Strengths

1. **Clean separation** — SQL lives only in `StockModel`, presentation only
   in views, flow only in the controller; front controller keeps URL logic
   out of everything else.
2. **Correct Singleton PDO** — lazy init, private constructor, clone/wakeup
   blocked, real prepared statements.
3. **Transactionally correct transfer** — `FOR UPDATE` row lock + atomic
   deduct/upsert/log with rollback on any `Throwable`; stock can never be
   created or destroyed by a partial transfer.
4. **No N+1 query** — zone stock is fetched in one query and grouped in PHP.
5. **Solid security basics** — bcrypt, CSRF with `hash_equals`,
   `filter_input`, `htmlspecialchars`, `display_errors=0`, session
   regeneration.
6. **Dual-entry design** — the same controller serves pages (via front
   controller) and JSON (direct endpoint), with graceful fallback between
   the two in `stock.js`.
7. **Proper HTTP semantics** — meaningful status codes and JSON headers
   everywhere.
8. **Schema quality** — 3NF, FK constraints with intentional
   CASCADE/RESTRICT semantics, CHECK constraints, unique keys, indexes on
   the audit table.

---

## 12. Architecture Weaknesses

1. **Dual-purpose `StockController.php`** — mode switching via a constant
   (`STOCKPULSE_FRONT_CONTROLLER`) is fragile and unusual; a class autoloaded
   twice through different paths can surprise maintainers.
2. **No service layer** — business rules (transfer validation) live inside
   the model; the controller is a thin pass-through, so "controller →
   service → model" cannot be drawn honestly.
3. **No tests** — zero test files, no testing framework, no CI.
4. **No dependency injection / interfaces** — hard-coded singleton and
   superglobals (`$_POST`, `$_SESSION`, `$_SERVER`) make unit testing
   impractical.
5. **8 separate queries per dashboard load** (summary, zones, grouped stock,
   low stock, recent movements, total value, SKU count, movement count) —
   could be consolidated.
6. **Full page reload after a "no-reload" Ajax transfer**
   (`location.reload()` after 900 ms) — re-fetches and re-renders
   everything.
7. **Duplicated view markup** — the zone `<select>` is rendered twice
   (from/to) in `dashboard.php`.
8. **Router is minimal** — no parameter binding, no route names, no HTTP
   verb flexibility beyond the 6 hard-coded routes.
9. **jQuery is referenced but never loaded** — see §15 (critical functional
   defect found during inspection).

---

## 13. Scalability Bottlenecks

| Users | Expected behavior (based on actual code) |
|---|---|
| **100** | No issues. Aggregates over tiny tables are trivial; PHP built-in server copes. |
| **1,000** | PHP built-in server is the bottleneck (single process, sequential request handling — it is a dev server, not for production). Session file locking serializes concurrent Ajax from the same user. Dashboard aggregate queries begin to cost more as `stock_logs` grows. |
| **10,000** | `stock_logs` grows unbounded (one row per movement, no purge/partitioning) — dashboard "recent movements" and `COUNT(*)` scans slow down; `GROUP BY` summaries scan full tables; single MySQL instance with no pooling, no read replica, no caching; every valuation API call recomputes `SUM(unit_price*quantity)` over all stock. |
| **100,000** | Requires re-architecture: php-fpm + load balancer, connection pooling, read replicas, cached/materialized valuation totals, log table partitioning or archival, pagination everywhere, and likely per-zone stock counters. The current "recompute everything per request" model does not scale. |

---

## 14. Security Issues (ranked by severity)

1. **Missing jQuery library (functional, critical)** — `stock.js` is wrapped
   in `(function ($) { … })(jQuery)` but no jQuery `<script>` tag exists in
   `header.php`/`footer.php` (only `bootstrap.bundle.min.js`, which does not
   include jQuery). Result: `ReferenceError: jQuery is not defined` in every
   browser → **transfer, login, and logout handlers never bind**; the app's
   interactive features are non-functional until jQuery is added. (Verified by
   grepping the whole project: jQuery appears only in comments.)
2. **Hardcoded DB credentials in a public repo** — `config/Database.php`
   ships `root` / empty password.
3. **No authorization checks** — role-based access is collected but never
   enforced (any user can transfer; API is fully public).
4. **Unauthenticated valuation API** — leaks inventory value, low-stock
   status, and staff names to anyone.
5. **No brute-force protection** on the login endpoint.
6. **No transport security enforcement** — no HTTPS/HSTS, session cookie
   without `Secure`/`SameSite` flags from app code.
7. **Internal detail leakage** — error JSON includes zone names and available
   quantities.
8. **No security headers** (CSP, X-Frame-Options, etc.).

---

## 15. Performance Issues

1. **Missing jQuery CDN tag** (see §14.1) — the Ajax layer never executes,
   so the intended "no full page reload" behavior is currently unreachable;
   when it works, successful transfers still trigger `location.reload()`.
2. **8 queries per dashboard render** — several could be merged (e.g. counts
   and totals into single aggregate queries).
3. **Unbounded `stock_logs` growth** — no archival/purge strategy; `COUNT(*)`
   and `ORDER BY log_id DESC LIMIT n` get slower over time (index helps the
   latter, not the former).
4. **Aggregate scans** — `SUM(unit_price*quantity)` and the `GROUP BY …
   HAVING` low-stock query scan all stock rows on every dashboard/API call;
   no caching layer exists.
5. **CDN runtime dependency** — Bootstrap/Icons (and jQuery, once added) load
   from jsDelivr on every page; no local fallback or SRI for icons/style.css.
6. **No pagination** — recent movements are capped at 8/5 rows, but there is
   no way to page through history.
7. **Session file storage** — default PHP file sessions serialize concurrent
   requests per user.

---

## 16. Recommended Improvements (prioritized, no framework changes required)

1. **Add the jQuery CDN `<script>` before `stock.js` in `footer.php`** —
   restores all Ajax functionality (one-line fix, critical).
2. **Enforce authorization** — check `$_SESSION['role']` for transfer
   actions; require authentication for the valuation API (or scope it to
   internal network).
3. **Move credentials to environment variables** (`getenv()` / `.env` outside
   docroot) and rotate the committed defaults.
4. **Add login rate limiting** (e.g. per-IP attempt counter in DB/cache) and
   a password policy.
5. **Set security headers and cookie flags** — `session.cookie_secure`,
   `session.cookie_samesite`, CSP, `X-Frame-Options`; serve over HTTPS.
6. **Introduce a thin service layer** between controller and model; split the
   dual-purpose controller into page + API classes.
7. **Cache/materialize valuation totals** and consolidate the 8 dashboard
   queries.
8. **Replace `location.reload()`** with targeted DOM updates from the JSON
   response (true Ajax UX).
9. **Add pagination + archival** for `stock_logs`.
10. **Add tests** (PHPUnit) and a CI pipeline; containerize with Docker for
    reproducible deployment.
11. **Move to php-fpm** (or equivalent) for any production load above
    demo scale.

---

*Generated by direct source inspection of the StockPulse workspace. No
application code was modified during this analysis.*
