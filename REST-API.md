# Invoicing REST API

The plugin exposes the same data the WordPress admin screens manage —
invoices, clients, payment methods, and settings — as a JSON REST API under
the `custom-invoices/v1` namespace, so an external client (e.g. the
[Invoices Android app](https://github.com/nmugumisi/invoice-saynotogi-com-app))
can fetch and write the same data.

## Authentication

The plugin generates its own **API key** — no WordPress account, username,
or Application Password needed:

1. In wp-admin, go to **Settings → Invoice Settings**.
2. Scroll to **Mobile App** and copy the **API Key** shown there (one is
   generated automatically the first time you view this page).
3. Send it on every request as a custom header: `X-CI-API-Key: <the key>`.

Click **Generate New Key** to rotate it — the old key stops working
immediately, so any connected app will need the new key entered again. A
logged-in administrator (cookie auth) is also accepted, which is handy for
poking the API from a browser without a key.

### Browser-based clients (CORS)

A native app's HTTP client isn't subject to CORS, so this only matters if
you build a browser-based consumer of this API (the plugin already sends
`Access-Control-Allow-Headers: X-CI-API-Key, Authorization, Content-Type`
for that case). If you use the pretty `/wp-json/...` URL form from a
browser, watch for a redirect on some installs when the path is missing a
trailing slash — a cross-origin redirect drops CORS headers and the request
will be blocked. The `?rest_route=/custom-invoices/v1/...` form (used in the
examples below and by the Android app) never redirects and works
identically regardless of the site's permalink setting.

## Endpoints

Base URL: `https://ngatinyore.co.zw/wp-json/custom-invoices/v1`

| Method | Path | Description |
|---|---|---|
| GET | `/settings` | Business details, invoice numbering defaults, currency, exchange rates |
| POST | `/settings` | Update settings (merges — only sent fields are changed) |
| GET | `/clients` | List all clients |
| POST | `/clients` | Create a client (`name` required) |
| GET | `/clients/{id}` | Get one client |
| POST/PUT | `/clients/{id}` | Update a client |
| DELETE | `/clients/{id}` | Delete a client |
| GET | `/payment-methods` | List all payment methods, in priority order |
| POST | `/payment-methods` | Create a payment method (`title` required) |
| GET | `/payment-methods/{id}` | Get one payment method |
| POST/PUT | `/payment-methods/{id}` | Update a payment method |
| DELETE | `/payment-methods/{id}` | Delete a payment method |
| GET | `/invoices` | List all invoices, newest first |
| POST | `/invoices` | Create an invoice (`client_id` required) |
| GET | `/invoices/{id}` | Get one invoice |
| POST/PUT | `/invoices/{id}` | Update an invoice |
| DELETE | `/invoices/{id}` | Delete an invoice |
| GET | `/invoices/next-number` | The number a new invoice would get (same numbering the admin screen uses) |

An invoice's `PUT`/`POST` body replaces the fields it includes (same
full-form-submit semantics as the wp-admin invoice screen); omitted fields
are left as-is. Creating/updating an invoice always re-snapshots
`bill_to_*` from the current `client_id` record, and only (re)locks
`zar_total`/`bwp_total` when `show_zar`/`show_bwp` is `true` and a rate is
set in Settings — identical to the admin screen's behaviour.

### Invoice JSON shape

```json
{
  "id": "7",
  "number": "INV-0001",
  "date": "2026-09-18",
  "due_date": "2026-09-18",
  "client_id": "5",
  "bill_to_name": "Ngatinyore Traders",
  "bill_to_address": "12 Samora Machel Ave\nHarare, Zimbabwe",
  "bill_to_email": "hello@ngatinyore.co.zw",
  "bill_to_phone": "+263 77 123 4567",
  "items": [
    { "desc": "Website Maintenance", "description": "April - June", "qty": 3, "price": 150 }
  ],
  "notes": "Thanks for your business.",
  "payment_method_ids": ["6"],
  "amount_paid": 0,
  "total": 450,
  "amount_due": 450,
  "status": "unpaid",
  "show_zar": true,
  "show_bwp": false,
  "zar_total": 7320,
  "zar_rate_used": 16.26,
  "bwp_total": null,
  "bwp_rate_used": null,
  "created_at": "2026-09-18T11:13:11",
  "updated_at": "2026-09-18T11:13:11"
}
```

`status` is computed (`paid` / `partial` / `unpaid`) from `total` and
`amount_paid`, matching the same rule used on the printed invoice.

## Not yet supported via the API

- Uploading a new **business logo** — still set via wp-admin's Business
  Details page (`GET /settings` returns the current `logo_url` read-only).

## Example

```bash
curl -H "X-CI-API-Key: xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  "https://ngatinyore.co.zw/index.php?rest_route=/custom-invoices/v1/invoices"
```

## Tested

Verified end-to-end against a scratch WordPress 6.x + PHP 8.3 install:
settings read/update, full client/payment-method/invoice CRUD, API-key and
cookie auth, rejection of missing/invalid keys, and cross-checked that an
invoice created via the API opens and edits cleanly in the wp-admin screen
with no PHP notices/warnings.
