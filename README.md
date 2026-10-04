# StockPulse — Smart Warehouse & Stock Movement Tracker

A complete, standalone **Native PHP 8.x** web application for tracking warehouse
stock levels and inter-zone stock movements. Built with clean OOP design
patterns and a lightweight custom MVC structure — **no frameworks** (no Laravel,
no Symfony).

---

## Features

- **Dashboard** — live inventory value, low-stock alerts, per-zone stock
  breakdown and a recent-movements audit trail.
- **Stock transfers** — move units between warehouse zones through a Bootstrap
  modal, submitted via **jQuery Ajax** (no page reload), executed inside a
  **PDO transaction** with row-level locking.
- **Audit log** — every transfer is recorded in `stock_logs` with the
  responsible user, quantity and timestamps.
- **REST API** — `GET /api/v1/valuation.php` returns total inventory value,
  low-stock counts and recent movements as structured JSON.
- **Secure by default** — bcrypt password hashing (`password_hash`),
  prepared statements everywhere, CSRF tokens, `filter_input()` sanitization,
  constant-time token comparison, and JSON responses with proper HTTP status
  codes.

---

## Tech Stack

| Layer      | Technology                                            |
|------------|-------------------------------------------------------|
| Language   | PHP 8.x (strict types, OOP)                           |
| Database   | MySQL 8.x / MariaDB 10.3+ (InnoDB, 3NF schema, FKs)   |
| Data access| PDO with real prepared statements (`ATTR_EMULATE_PREPARES = false`) |
| Frontend   | HTML5, CSS3, Bootstrap 5 (CDN), Bootstrap Icons, jQuery 3.x |
| Async      | jQuery `$.ajax` for all form submissions              |

---

## Requirements

- PHP **8.0+** with `pdo_mysql` and `mbstring` extensions enabled
- MySQL **8.0+** (or MariaDB 10.3+)
- A web server (Apache/Nginx) **or** the PHP built-in server for local dev

---

## Quick Start

### 1. Create the database & import the schema

```bash
mysql -u root -p < schema.sql
```

`schema.sql` creates the `stockpulse` database, all five tables with foreign
keys and CHECK constraints, and seeds demo data (8 products, 4 zones,
3 users, sample movement history).

### 2. Configure the database credentials

Edit `config/Database.php` and adjust the `$settings` array:

```php
private array $settings = [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'dbname'   => 'stockpulse',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
];
```

### 3. Serve the application

**Option A — PHP built-in server, project root as docroot (recommended for dev):**

```bash
cd stockpulse
php -S localhost:8000
```

Open **http://localhost:8000/public/** (i.e. `/public/index.php`). In this mode the
jQuery handlers POST directly to `controllers/StockController.php?action=transfer`,
exactly as documented in the API section below.

**Option B — Apache / Nginx / XAMPP:**

Point the document root at the `public/` folder for production-hardening,
e.g. with Apache:

```
DocumentRoot "C:/path/to/stockpulse/public"
```

Then open **http://localhost/**. In this mode the direct controller URL sits outside
the docroot (good for security); `stock.js` automatically detects the 404 and
retries the same request against the front-controller routes (`POST /transfer`,
`POST /login`, `POST /logout`) defined in `public/index.php` — so the Ajax calls
work identically in both deployment modes.

### 4. Log in

| Email                  | Password     | Role    |
|------------------------|--------------|---------|
| admin@stockpulse.local | `admin1234`  | admin   |
| sara@stockpulse.local  | `manager1234`| manager |
| mike@stockpulse.local  | `staff1234`  | staff   |

> To regenerate a password hash for a new user:
> `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`
> and insert it into the `users` table.

---

## Project Structure

```
stockpulse/
├── config/
│   └── Database.php          # Singleton PDO connection
├── controllers/
│   └── StockController.php   # Ajax JSON endpoints + view rendering
├── models/
│   └── StockModel.php        # PDO queries + transactional transferStock()
├── views/
│   ├── layout/
│   │   ├── header.php        # <head>, Bootstrap CSS, navbar
│   │   └── footer.php        # footer + JS bundles
│   └── dashboard.php         # KPI cards, stock table, transfer modal
├── public/
│   ├── css/style.css         # Custom styles
│   ├── js/stock.js           # jQuery $.ajax handlers
│   └── index.php             # Front controller / custom router
├── api/
│   └── v1/
│       └── valuation.php     # REST API endpoint (JSON metrics)
├── schema.sql                # MySQL schema + demo data
└── README.md                 # This file
```

---

## Architecture

### Request lifecycle (MVC flow)

```
Browser
  │  GET /public/                    POST controllers/StockController.php?action=transfer
  ▼                                  ▼
public/index.php (Front Controller)  StockController.php (JSON mode)
  │  custom router: (method, path) → action
  ▼
StockController
  │  validates input (filter_input), checks CSRF + session
  ▼
StockModel
  │  PDO prepared statements; transferStock() runs in a transaction
  ▼
MySQL (InnoDB)
  │
  ▼
Views (header.php → dashboard.php → footer.php)  ← or JSON response for Ajax
```

### Design patterns used

- **Singleton** — `Database` guarantees one PDO instance per request;
  private constructor, `getInstance()` lazy initialization, cloning and
  unserialization blocked.
- **Front Controller** — `public/index.php` is the single entry point for
  pages; a closure-based router maps `(HTTP method, path)` pairs to
  controller actions and returns a proper 404 for unknown routes.
- **MVC** — `StockController` handles requests and renders views;
  `StockModel` owns all SQL; views contain display logic only.
- **Transaction Script / Unit of Work** — `transferStock()` wraps
  `SELECT … FOR UPDATE` → `UPDATE` → `INSERT … ON DUPLICATE KEY UPDATE` →
  `INSERT` (log) in `beginTransaction()` / `commit()` / `rollBack()`.

### Why the transfer is safe under concurrency

```php
$this->pdo->beginTransaction();

// Row-level lock prevents two concurrent transfers from overdrawing
// the same zone (InnoDB "SELECT ... FOR UPDATE").
SELECT quantity FROM warehouse_stock
 WHERE product_id = ? AND zone_name = ? FOR UPDATE;

// Sufficient-stock check
// UPDATE source zone   (quantity - n)
// UPSERT destination   (quantity + n)  via ON DUPLICATE KEY UPDATE
// INSERT INTO stock_logs (audit trail)

$this->pdo->commit();   // or rollBack() on any Throwable
```

Any exception rolls everything back, so stock can never be deducted in one
zone without being received in the other.

### Security measures

| Concern            | Implementation                                                                 |
|--------------------|--------------------------------------------------------------------------------|
| SQL injection      | PDO prepared statements only; `PDO::ATTR_EMULATE_PREPARES = false`           |
| Password storage   | `password_hash()` (bcrypt) + `password_verify()`                              |
| CSRF               | Per-session `random_bytes(32)` token, hidden form field, `hash_equals()`    |
| XSS                | `htmlspecialchars()` on every echoed value; `FILTER_SANITIZE_SPECIAL_CHARS`  |
| Session fixation   | `session_regenerate_id(true)` after login                                     |
| Input validation   | `FILTER_VALIDATE_INT`, `FILTER_VALIDATE_EMAIL`, guard clauses in the model   |
| Error disclosure   | `display_errors = 0`; details go to `error_log()`, generic messages to client|

---

## API Documentation

### `GET /api/v1/valuation.php`

Returns inventory valuation metrics as JSON.

**Response `200 OK`**

```json
{
    "status": "success",
    "generated_at": "2026-10-04T12:00:00+00:00",
    "data": {
        "total_inventory_value": 19961.09,
        "total_inventory_value_formatted": "19,961.09",
        "currency": "USD",
        "total_skus": 8,
        "low_stock_count": 2,
        "low_stock_products": [
            {
                "product_id": 3,
                "sku": "SKU-ELC-003",
                "product_name": "USB-C Hub 7-in-1",
                "total_quantity": 17,
                "reorder_level": 25
            }
        ],
        "recent_movements": [
            {
                "log_id": 8,
                "movement_type": "outward",
                "sku": "SKU-ELC-001",
                "product_name": "Wireless Mouse 2.4GHz",
                "quantity": 15,
                "notes": "Order #INV-1089 fulfilled",
                "user": "Sara Khan",
                "logged_at": "2026-10-04 10:12:33"
            }
        ]
    }
}
```

**Error responses**

| Status | Body                                              |
|--------|---------------------------------------------------|
| `405`  | `{"status":"error","message":"Method not allowed. Use GET."}` |
| `500`  | `{"status":"error","message":"Unable to compute valuation."}`  |
| `503`  | `{"status":"error","message":"Service temporarily unavailable."}` (DB down) |

### Ajax endpoints (used by `stock.js`)

All POST to `controllers/StockController.php?action=<action>` with
`application/x-www-form-urlencoded` body including `csrf_token`.

| Action     | Fields                                   | Success response                          | Error codes               |
|------------|------------------------------------------|-------------------------------------------|---------------------------|
| `transfer` | `product_id`, `from_zone`, `to_zone`, `quantity`, `csrf_token` | `{"status":"success","message":"Transferred 5 unit(s) ..."}` | `400` validation / insufficient stock, `401` not logged in, `403` bad CSRF, `405` not POST, `422` invalid input |
| `login`    | `email`, `password`, `csrf_token`        | `{"status":"success","message":"Welcome, …"}` | `401` bad credentials, `403` bad CSRF, `422` invalid input |
| `logout`   | `csrf_token`                             | `{"status":"success","message":"You have been logged out."}` | — |

All JSON responses carry `Content-Type: application/json; charset=utf-8` and
`Cache-Control: no-store`.

---

## Using the app

1. Open the dashboard, review the KPI cards and the **Stock by Zone** badges.
2. Click the **transfer icon** (⇄) on any product row — the modal opens with
   that product pre-selected.
3. Choose source/destination zones and a quantity, then submit.
4. The jQuery handler POSTs asynchronously; a green success alert (or a red
   error alert explaining e.g. *Insufficient stock in 'Zone A'*) appears and
   the tables refresh.
5. Every transfer shows up in **Recent Stock Movements** and is queryable via
   the valuation API.

---

## Troubleshooting

| Symptom                                     | Fix                                                              |
|---------------------------------------------|------------------------------------------------------------------|
| "Service temporarily unavailable"           | Check MySQL is running and credentials in `config/Database.php`. |
| "Invalid CSRF token"                        | Session expired — reload the page to get a fresh token.          |
| "Please log in to transfer stock"           | Use the Login modal (demo credentials above).                    |
| 404 when opening the API                    | Access `/api/v1/valuation.php` from the **project root** docroot, not `public/`. |
| Ajax calls return 404                       | Normal under Option B (docroot = `public/`); `stock.js` retries automatically against the front-controller routes. |
| "headers already sent" warnings             | Make sure files start with `<?php` and contain no leading BOM/whitespace. |

---

## License

MIT — free to use for learning and production alike.
