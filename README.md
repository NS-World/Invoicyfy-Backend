# Invoicyfy Backend (PHP API)

REST-style JSON API for **Invoicyfy** — orders, GST invoices (retail bill + B2B tax invoice), payments,
inventory, expenses and reports for small businesses. Plain PHP 8.1+ with PDO/MySQL and JWT auth;
runs on XAMPP, shared hosting (Apache) or nginx + PHP-FPM.

## Setup

```bash
composer install
cp .env.example .env          # fill DB credentials, CORS origins and two long random JWT secrets
php database/migrate.php --admin-email=you@shop.com --admin-password='ChangeMe123'
```

* `migrate.php` is **CLI only**, safe to run any time and never drops data. It creates missing tables,
  adds new columns to an existing database (including one created by the old `setup-db.php`) and seeds
  roles (Admin / Manager / Staff), a default store and the first admin user.
* No SSH? After uploading a new version, sign in as admin: a banner offers **Update database**, which runs
  the same migration from the browser (`master/settings/update-database.php`, admin only).
* Offers keep sending in the background when a cron job runs every minute:
  `php /path/to/api/cron/process-campaigns.php` (CLI only). Offer pictures are saved in `uploads/offers/`.
* Generate JWT secrets with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
* XAMPP: put this folder at `htdocs/invoice-api` → API base URL `http://localhost/invoice-api/`.
* Production: set `APP_DEBUG=false`, serve over HTTPS, and set `CORS_ALLOWED_ORIGINS` to your
  front-end domain. `.htaccess` blocks `lib/`, `database/`, `vendor/`, `.env` and `_*.php` on Apache;
  on nginx add equivalent `deny` rules.

## Deploy on Hostinger

See [DEPLOY-HOSTINGER.md](DEPLOY-HOSTINGER.md) — app and API on one subdomain, `.env` secrets, migration over SSH.

## Demo data (for testing)

Fill a **test** database with ~3 months of realistic data for an ice-cream parlour — 20 products with
HSN/GST, consumer and business (B2B) customers, ~900 retail bills, B2B orders with GST tax invoices
(paid, part-paid and overdue), payments by Cash/UPI/Card/Bank, expenses, purchases, low-stock items
and a couple of cancelled bills:

```bash
DB_NAME=invoicyfy_demo php database/migrate.php --admin-email=admin@demo.com --admin-password=Admin@1234
DB_NAME=invoicyfy_demo php database/seed-demo.php            # --days=120 for longer history
```

Logins: your admin account, plus `biller@demo.com` (Staff) and `manager@demo.com` (Manager), password `Demo@1234`.
The script refuses to run when the store already has invoices (use `--force` only on a test database).
Never run it on your live database.

## Structure

| Path | Purpose |
| --- | --- |
| `lib/bootstrap.php` | Include first in every endpoint: CORS, JSON errors, `$pdo`, helpers |
| `lib/auth.php` | JWT issue/verify, `require_auth()`, roles (`require_admin`) and store scoping |
| `lib/gst.php` | GST maths (CGST+SGST / IGST, tax-inclusive prices), GSTIN validation, state codes |
| `lib/documents.php` | Shared order / invoice / payment logic, invoice numbering, stock movements |
| `database/schema.php` | Single source of truth for the DB schema |
| `master/*` | Endpoints per module · `reports/*` dashboard & reports · `auth/*` login |
| `tests/api_smoke_test.php` | End-to-end test of the whole flow (64 checks) |

## Business rules

* **Totals are always calculated on the server** from the items; client totals are ignored.
* Invoice numbers are per store and per financial year (April–March):
  tax invoice `INV/26-27/0001` (prefix in store settings), retail bill `55` (optional bill prefix).
  Numbers are allocated with a row lock, so two counters billing at once never clash.
* Inter-state supply (buyer state ≠ store state, from GSTIN or chosen state) → IGST, else CGST + SGST.
* Creating an invoice deducts stock; cancelling it restores stock and keeps the number in the register
  (required for GST). Invoices with an IRN must be cancelled on the IRP first.
* Payments update `amount_paid`, `balance_due` and status (Unpaid / Partial / Paid).
* Roles: **Admin** – everything, all stores · **Manager** – own store incl. settings and deleting payments ·
  **Staff** – billing for own store.
* E-invoicing: the IRN / Ack No / signed QR from the government IRP portal can be saved on a tax invoice
  (`master/invoice/update-einvoice.php`) and is printed with its QR. Direct IRP API integration needs
  GSP credentials and can be added later.

## Backup & restore

* **Backup & Restore** screen (permission `backup`, Admin by default) downloads the whole database as one
  Excel file — one sheet per table, in restore order. The WhatsApp access token is never exported;
  user password hashes are, so restored users can log in. Long values (logo, invoice design) go to a hidden
  `_assets` sheet.
* Import never overwrites: a row whose id or natural key (phone, GSTIN, SKU, invoice number…) already exists
  is reported as *Already exists*; invalid rows are reported with the reason (wrong number, missing name,
  linked record not found…). The screen offers these rows as a downloadable error file. New rows (also rows
  typed into the sheet without an id) are added. Bill-number series keep the higher counter.
* Crash recovery: install a fresh server, run `php database/migrate.php`, sign in with the new admin and
  import the latest backup file. The default "My Business" store is replaced by your backed-up store.
* Endpoints: `master/backup/tables.php`, `export.php?table=&page=&limit=`, `import.php` (`{table, rows}`),
  `add-log.php`. Stores, users and roles can only be exported/imported by an Admin.

## Offers & messages (WhatsApp)

* Customers have `marketing_opt_in` (on by default; switch off on the customer form). Opted-out customers and
  customers without a valid mobile number are never messaged. Indian 10-digit numbers get `91` added.
* **Automatic**: WhatsApp Business Cloud API. In *Offers & Messages → WhatsApp setup* enter the business
  number, Phone number ID, a permanent access token (stored AES-256-GCM encrypted with `APP_KEY`, never
  returned) and an approved *Marketing* template whose body has `{{1}}` = customer name and `{{2}}` = offer
  text. Messages go out 20 per request while the screen is open (`send-batch.php`); stop / resume / retry
  failed are supported.
* **Manual**: no setup — the screen opens WhatsApp (wa.me) with the personalised text for each customer
  from the device where the business number is logged in, and marks it sent.
* Placeholders in the offer text: `{name}` (customer), `{shop}` (store name).
* Endpoints: `master/campaign/whatsapp-settings.php`, `update-whatsapp-settings.php`, `test-whatsapp.php`,
  `audience.php`, `campaigns.php`, `get-campaign.php`, `create-campaign.php`, `send-batch.php`,
  `mark-sent.php`, `update-campaign.php`. Permission module `marketing`.

## Version 3 rules

* `create-invoice.php` accepts `payments: [{payment_method, amount, reference_no}]` (split payment, max 6,
  sum ≤ total; the old single `payment` still works), `table_no`, `coupon_code`, `redeem_points`,
  `approval_pin`, `client_uuid` and `offline_created_at`.
* `client_uuid` makes creation idempotent: the same bill sent twice (offline sync, double click) is saved
  once and the second call returns it with `duplicate: true`.
* Coupon and points discounts are worked out on the server (`quote-bill.php` for the preview, again with row
  locks when saving) and spread over the lines so GST stays correct. Cancelling a bill returns them.
* Discount limit: a role's `max_discount_percent`; above it the request needs a manager's `approval_pin`
  (403 `approval_required` / `approval_wrong_pin`, 5 wrong tries in 15 min → 429).
* `require_day_open` (counter setting) → 409 `day_not_open` until the day is opened (offline bills skip it).
* Automatic messages run once a day per store from `cron/process-campaigns.php` (or `jobs/tick.php`, called
  by the open app): night summary, low stock, birthday/anniversary wish with a one-time coupon, udhaar reminders.
* Everything important is written to `activity_log` (bills, cancels, deletes, price changes, approvals, logins).

## Speed on large data (lakhs of bills)

Tested with 6 lakh bills, 12 lakh bill items, 5.7 lakh payments, 1.5 lakh customers (about 35 lakh rows):
bill creation ~15 ms, list screens 5–90 ms, dashboard ~100 ms, GST summary ~5 ms, annual report ~0.4 s.

* **Indexes** for every list filter and sort (date, store + date, status, customer, phone, bill number, GSTIN…).
* **Search** (`lib/query.php`): when the other filters leave a few thousand rows the search is a plain
  "contains"; on very large sets it uses indexes instead — bill number / mobile "starts with", exact GSTIN and
  FULLTEXT word search on names (`list_search()`).
* **Pagination** reads the ids of one page from an index first ("deferred join"), so page 5,000 is as quick as page 1.
* **Report summaries** (`lib/stats.php`): `stat_*` tables hold day-wise totals (sales, items, GST, payments) and
  month-wise items / customers. Every bill, payment, cancel or import marks its day in `stat_dirty`; the next
  report (or the cron job, every minute) recomputes only those days from the real tables, so figures are always
  exact. `php tests/stats_check.php` proves the summaries equal a full rebuild. "Update database" (or
  `php database/migrate.php`) builds them; with many lakh bills run the CLI command (about 50 s per 6 lakh bills).
* Responses over 1.4 KB are gzip-compressed; every response has `Server-Timing: app;dur=…, db;dur=…`
  (browser DevTools → Network → Timing) to see whether time goes in PHP/MySQL or on the network.
* `GET /api/health.php` — 200 when PHP and MySQL answer (for uptime monitors or a load balancer).
* The API is stateless (JWT, no PHP sessions), so it can run on several servers behind a load balancer with
  one MySQL database without code changes.

## Main endpoints

All requests need `Authorization: Bearer <access_token>` except sign-in/refresh. Lists accept
`page`, `limit`, `search` and module filters (`status`, `from`, `to`, …).

| Module | Endpoints |
| --- | --- |
| Auth | `auth/signin.php`, `auth/refresh.php`, `auth/me.php`, `auth/change-password.php` |
| Orders | `master/order/orders.php`, `create-order.php` (with `items`), `update-order.php`, `get-order-by-id.php`, `update-order-status.php`, `delete-order.php` |
| Invoices | `master/invoice/invoices.php`, `create-invoice.php` (`order_id` **or** `items`, `invoice_type` = `retail`/`tax`, optional `payment`), `get-invoice-by-id.php`, `cancel-invoice.php`, `update-einvoice.php` |
| Payments | `master/payment/payments.php`, `create-payment.php`, `delete-payment.php`, `get-payment-details.php` |
| Inventory | `master/inventory/stock.php`, `adjust-stock.php`, `stock-movements.php` |
| Expenses | `master/expense/expenses.php`, `add-expense.php`, `update-expense.php`, `delete-expense.php`, `categories.php` |
| Masters | `master/customer/*`, `master/product/*`, `master/store/*`, `master/user/*`, `master/role/*`, `master/settings/meta.php` |
| Reports | `reports/dashboard.php`, `reports/sales.php?group=day\|month\|product\|customer`, `reports/gst-summary.php`, `reports/profit-loss.php`, `reports/annual.php?fy=2026` (financial year April 2026 – March 2027) |
| Backup | `master/backup/*` (see above) |
| Offers | `master/campaign/*` (see above) |
| Day close (V3) | `master/cash/current.php`, `create-session.php`, `add-movement.php`, `close-session.php`, `sessions.php`, `get-session.php` |
| Billing (V3) | `master/invoice/quote-bill.php` (server totals with coupon/points), `master/held/*` (hold/park bills), `master/pos/pos-settings.php` |
| Coupons & loyalty (V3) | `master/coupon/*` (`check-coupon.php`), `master/loyalty/*` |
| Udhaar (V3) | `master/credit/dues.php`, `customer-statement.php`, `add-payment.php` (oldest bills first), `send-reminders.php` |
| Activity & automation (V3) | `master/activity/activity-log.php`, `master/automation/*`, `jobs/tick.php`, `auth/set-approval-pin.php` |

## Testing

```bash
DB_NAME=invoicyfy_test php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
DB_NAME=invoicyfy_test php -S 127.0.0.1:8080 -t .
php tests/api_smoke_test.php http://127.0.0.1:8080 admin@test.com Admin@1234
php tests/api_v3_test.php http://127.0.0.1:8080 admin@test.com Admin@1234   # V3 features, fresh DB
DB_NAME=invoicyfy_test php tests/stats_check.php                            # report summaries == full rebuild
```
Use a throwaway database — the test creates data.
