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
* Generate JWT secrets with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
* XAMPP: put this folder at `htdocs/invoice-api` → API base URL `http://localhost/invoice-api/`.
* Production: set `APP_DEBUG=false`, serve over HTTPS, and set `CORS_ALLOWED_ORIGINS` to your
  front-end domain. `.htaccess` blocks `lib/`, `database/`, `vendor/`, `.env` and `_*.php` on Apache;
  on nginx add equivalent `deny` rules.

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
| Reports | `reports/dashboard.php`, `reports/sales.php?group=day\|month\|product\|customer`, `reports/gst-summary.php`, `reports/profit-loss.php` |

## Testing

```bash
DB_NAME=invoicyfy_test php database/migrate.php --admin-email=admin@test.com --admin-password=Admin@1234
DB_NAME=invoicyfy_test php -S 127.0.0.1:8080 -t .
php tests/api_smoke_test.php http://127.0.0.1:8080 admin@test.com Admin@1234
```
Use a throwaway database — the test creates data.
