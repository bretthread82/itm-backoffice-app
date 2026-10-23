# Invoice API (v1, read-only)

Machine-readable access to invoice data for reporting tools, ERPs and scripts. It cannot approve, change, submit or delete anything.

* Base URL: `https://<your-site>/api/v1`
* Description: `GET /api/v1/openapi.json` (OpenAPI 3.1, no token needed) and a try-it page at `/api/docs` (sign in first).
* Everything is JSON except the PDF download.

## Authentication

An administrator makes the token, for themselves or for another person: **Administration > API tokens > Create token** (choose the user, name it after the system that will use it; choose 30 days, 90 days or 1 year). The token is shown once and only a hash is stored. Send it on every request:

```
Authorization: Bearer <token>
```

* A token acts as its owner and sees **exactly the companies the owner can see** (group-level users see all). Sub-groups never widen access.
* A browser session does **not** work on the API, and a token does not work on the web screens.
* A token stops working when it expires, is revoked (by its owner or an administrator), or its owner is made inactive.
* Up to 10 tokens per person. People can see and revoke their own tokens under Account menu > API tokens. Use https only: a token is a credential.

## Endpoints

| Method and path | Returns |
|---|---|
| `GET /me` | the token owner, whether they are group-level, their companies, the token's name and expiry |
| `GET /companies` | companies you can see |
| `GET /invoices` | a page of invoices (filters below) |
| `GET /invoices/{id}` | one invoice with the approval steps, the current step and PDF details |
| `GET /invoices/{id}/timeline` | actions and notes, oldest first |
| `GET /invoices/{id}/file` | the invoice PDF (`404` once the server copy has been purged) |
| `GET /invoices/{id}/documents/{docId}` | a supporting document attached during approval (listed in `documents` on the invoice; `404` once purged) |
| `GET /approvals?view=waiting\|mine` | what waits on you, or your own unfinished invoices |
| `GET /reports/outstanding` | outstanding invoices with days past due, plus the ageing summary |
| `GET /openapi.json` | this API's OpenAPI description |

### Invoice filters (`GET /invoices`)

`company`, `status` (`open`, `outstanding` = not yet paid, `completed` = processed or paid (the old name for finished), or one status such as `processed` or `paid`), `q` (invoice number, supplier or PO), `age` (`overdue`, `current`, `1-30`, `31-60`, `61-90`, `90+`, `critical`), `date_from`, `date_to` (`YYYY-MM-DD`), `min`, `max` (amount), `sort` (`invoice`, `supplier`, `invoice_date`, `due`, `amount`, `status`, `company`), `dir` (`asc`, `desc`), `page`, `per_page` (default 25, maximum 100).

A bad value is a `422`, not silently ignored. `company` must be one you can see.

## Responses

```json
{ "data": [ { "id": 41, "invoice_number": "INV-7731", "amount": "1250.50", "currency": "USD", "status": "in_approval", "...": "..." } ],
  "meta": { "page": 1, "per_page": 25, "total": 112, "pages": 5 } }
```

* Money is a **string with two decimals** plus `currency`; never a float.
* Dates are `YYYY-MM-DD`; timestamps are server local time (`YYYY-MM-DD HH:MM:SS`).
* Only listed fields are ever returned. Internal data (route snapshots, SharePoint identifiers, stored file names and hashes, sign-in details) is never included.

## Errors

Always `{"error": {"code": "...", "message": "..."}}`:

| Status | `code` | Meaning |
|---|---|---|
| 401 | `unauthorized` | missing, wrong, revoked or expired token |
| 403 | `insufficient_scope`, `account_disabled` | token lacks `api.read`, or the owner is inactive |
| 404 | `not_found` | no such endpoint, or an invoice that does not exist **or is not in a company you can see** (the two look identical) |
| 405 | `method_not_allowed` | anything other than GET |
| 422 | `invalid_filter` | a query value is not valid |
| 429 | `rate_limited` | more than 120 requests a minute for your address and token; wait for `Retry-After` seconds |

## Example

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://invoicing.example.com/api/v1/invoices?status=outstanding&age=overdue&per_page=50"

curl -s -H "Authorization: Bearer $TOKEN" -o invoice-41.pdf \
  "https://invoicing.example.com/api/v1/invoices/41/file"
```

## For maintainers

* The description is hand-written in `app/Docs/openapi.json`. `tests/unit/OpenApiSpecTest.php` fails if a route under `/api/v1` is missing from it (or the reverse), if a `$ref` dangles, or if the invoice fields the API emits differ from the documented ones. Change both together.
* Output is built only in `app/Services/ApiPresenter.php`, from explicit field lists. Add a field there on purpose; a new database column never reaches the API on its own.
* Visibility is `AccessScope`, as everywhere. API code never reads the session or the "active company" choice: narrowing is the explicit `company` parameter.
* Filters (`api-throttle`, `api-auth`) are in `app/Filters`; the CSRF filter skips `/api` only (`AppCsrf`), because a bearer token is added by the client and not sent by the browser on its own.
* Token creation and revocation are written to the audit log (`api_token.created`, `api_token.revoked`). Swagger UI is vendored in `public/assets/vendor/swagger-ui` (5.17.14, Apache-2.0); nothing loads from a CDN.
