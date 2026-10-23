#!/usr/bin/env bash
# End-to-end smoke test over real HTTP (sessions, CSRF, multipart upload, PDF endpoint, bell).
# CodeIgniter's feature-test harness cannot do multipart uploads, so this fills that gap.
#
# Run it against a THROWAWAY database, never a real one:
#   1. point database.default.database in .env at a throwaway MySQL database (it gets wiped in step 2)
#   2. php spark migrate:refresh --all -f
#   3. php spark db:seed "Tests\Support\Database\Seeds\E2eSeeder"
#   4. php spark serve --port 8092        (leave running)
#   5. bash tests/e2e/smoke.sh http://localhost:8092
#   6. stop the server, restore .env, delete writable/uploads/invoices/*
#
# Exits non-zero on the first failed check.
set -u
B="${1:-http://localhost:8092}"
TMPD="$(mktemp -d)"; trap 'cd / && rm -rf "$TMPD"' EXIT
# Work from the temp directory and use relative file names: on Windows (Git Bash) curl does not
# translate an absolute POSIX path inside -F name=@/path.
cd "$TMPD" || exit 1
T="."
fail() { echo "FAIL: $1"; exit 1; }
ok()   { echo "ok:   $1"; }

printf '%%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%% smoke\ntrailer\n<< /Root 1 0 R >>\n%%%%EOF\n' > "$T/good.pdf"
printf 'this is not a pdf' > "$T/bad.pdf"

tok() { grep -o 'name="csrf_test_name" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//'; }
login() {
  local jar="$1" email="$2" t code
  t=$(curl -s -c "$jar" -b "$jar" "$B/login" | tok)
  code=$(curl -s -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" -d "csrf_test_name=$t&email=$email&password=E2e-Test-Password-1" "$B/login")
  [ "$code" = "303" ] || [ "$code" = "302" ] || fail "login $email returned $code"
}

login "$T/sue" sue@e2e.test; ok "submitter signs in"
login "$T/alan" alan@e2e.test; ok "approver signs in"

types=$(curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/types?company=1" | tr -d ' \n')
echo "$types" | grep -q '"name":"Utilities"' || fail "types endpoint did not list the invoice type"
ok "types endpoint returns the invoice type and route preview"

# A non-PDF must be refused, with nothing created (no orphan draft) and nothing stored.
t=$(curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/new" | tok)
[ -n "$t" ] || fail "no CSRF token on the new-invoice form"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$T/sue" -c "$T/sue" -F "csrf_test_name=$t" -F company_id=1 -F invoice_type_id=1 -F supplier=Bad -F invoice_number=BAD-1 \
  -F invoice_date=2026-09-01 -F due_date=2026-10-01 -F amount=5 -F currency=USD -F action=submit -F "pdf=@$T/bad.pdf;type=application/pdf;filename=bad.pdf" "$B/invoices")
[ "$code" = "303" ] || [ "$code" = "302" ] || fail "bad upload: expected a redirect back to the form, got HTTP $code"
# The message is a one-time flash shown on the first page after the redirect, so read it first.
curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/new" | grep -q 'not a valid PDF' || fail "no 'not a valid PDF' message was shown"
curl -s -b "$T/sue" -c "$T/sue" "$B/invoices" | grep -q 'BAD-1' && fail "an invoice (or orphan draft) was created from a non-PDF upload"
ok "a non-PDF upload is refused with a message, and nothing is created"

# A real PDF is accepted and submitted.
t=$(curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/new" | tok)
# Due long ago on purpose, so the invoice is Critical (61+ days past due) whenever this runs.
out=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -b "$T/sue" -c "$T/sue" -F "csrf_test_name=$t" -F company_id=1 -F invoice_type_id=1 -F supplier="Power Co" -F invoice_number=E2E-1 \
  -F invoice_date=2025-12-01 -F due_date=2026-01-15 -F amount=250.00 -F currency=USD -F action=submit -F "pdf=@$T/good.pdf;type=application/pdf;filename=good.pdf" "$B/invoices")
id=$(echo "$out" | grep -o '[0-9]*$')
[ -n "$id" ] || fail "no invoice id after create (response: $out)"
page=$(curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/$id")
echo "$page" | grep -q 'In approval' || fail "invoice is not In approval after submit"
echo "$page" | grep -q 'pdf-viewer'  || fail "detail page has no PDF viewer"
ok "a real PDF is uploaded and submitted (invoice $id)"

# The PDF endpoint: inline, safe headers, real bytes.
hdr=$(curl -s -D - -o "$T/out.pdf" -b "$T/alan" -c "$T/alan" "$B/invoices/$id/file")
echo "$hdr" | grep -qi '^content-type: application/pdf'      || fail "PDF content type"
echo "$hdr" | grep -qi '^content-disposition: inline'        || fail "PDF is not inline"
echo "$hdr" | grep -qi '^x-content-type-options: nosniff'    || fail "missing nosniff"
echo "$hdr" | grep -qi '^cache-control:.*no-store'           || fail "missing no-store"
head -c 5 "$T/out.pdf" | grep -q '%PDF-'                     || fail "response is not the PDF bytes"
ok "PDF served inline with safe headers"

code=$(curl -s -o /dev/null -w '%{http_code}' "$B/invoices/$id/file")
{ [ "$code" = "302" ] || [ "$code" = "303" ]; } || fail "signed-out PDF request returned $code (expected a redirect to login)"
ok "signed-out users cannot fetch the PDF"

bell=$(curl -s -b "$T/alan" -c "$T/alan" "$B/notifications/unread" | tr -d ' \n')
echo "$bell" | grep -q '"count":1' || fail "approver's bell does not show 1 unread"
ok "approver's bell shows the new invoice"

# Dashboard and reports reflect the outstanding, critical invoice.
dash=$(curl -s -b "$T/sue" -c "$T/sue" "$B/" | tr -d '\n')
echo "$dash" | grep -q 'USD 250.00'                    || fail "dashboard does not show the outstanding amount"
echo "$dash" | grep -q 'critical (61+ days)'  || fail "dashboard does not flag the critical invoice"
echo "$dash" | grep -q 'table-danger'              || fail "dashboard does not highlight the critical row"
echo "$dash" | grep -q 'E2E-1'                         || fail "dashboard's most-overdue list is missing the invoice"
ok "dashboard shows the outstanding amount and flags the critical invoice"

rep=$(curl -s -b "$T/sue" -c "$T/sue" "$B/reports" | tr -d '\n')
echo "$rep" | grep -q 'Outstanding by age'     || fail "reports page has no aging section"
echo "$rep" | grep -q 'Outstanding by company' || fail "reports page has no by-company section"
ok "reports page renders"

csv=$(curl -s -D hdr.txt -b "$T/sue" -c "$T/sue" "$B/reports/outstanding.csv")
grep -qi '^content-type: text/csv' hdr.txt        || fail "CSV content type"
grep -qi '^content-disposition: attachment' hdr.txt || fail "CSV is not an attachment"
echo "$csv" | grep -q 'E2E-1'                     || fail "CSV is missing the invoice"
echo "$csv" | grep -q 'Days past due'             || fail "CSV has no header row"
echo "$csv" | grep -q ',Yes,'                     || fail "CSV does not mark the invoice Critical"
ok "outstanding invoices CSV downloads with the critical invoice marked"

curl -s -b "$T/sue" -c "$T/sue" "$B/invoices?status=outstanding&age=critical" | grep -q 'E2E-1' || fail "critical age filter missed the invoice"
curl -s -b "$T/sue" -c "$T/sue" "$B/invoices?status=outstanding&age=current"  | grep -q 'E2E-1' && fail "'not yet due' filter wrongly included the invoice"
ok "invoice list age filters work"

# Approve and confirm completion.
t=$(curl -s -b "$T/alan" -c "$T/alan" "$B/invoices/$id" | tok)
curl -s -o /dev/null -b "$T/alan" -c "$T/alan" -d "csrf_test_name=$t&decision=approve&note=ok" "$B/invoices/$id/act"
curl -s -b "$T/sue" -c "$T/sue" "$B/invoices/$id" | grep -q 'Processed' || fail "invoice was not processed after approval"
ok "approval finishes the route (processed)"

# Once completed it is no longer outstanding anywhere.
curl -s -b "$T/sue" -c "$T/sue" "$B/reports/outstanding.csv" | grep -q 'E2E-1' && fail "completed invoice is still in the outstanding CSV"
curl -s -b "$T/sue" -c "$T/sue" "$B/" | tr -d '\n' | grep -q 'No outstanding invoice is past its due date' || fail "dashboard still shows overdue invoices after completion"
ok "completed invoice drops out of outstanding totals"

# Read-only API: bearer tokens only, scoped to what the owner can see (the token is Sue's).
# Only administrators make tokens: Sue cannot, the admin makes one for her.
t=$(curl -s -b "$T/sue" -c "$T/sue" "$B/account/api-tokens" | tok)
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$T/sue" -c "$T/sue" -d "csrf_test_name=$t&user_id=1&name=smoke&days=30" "$B/admin/api-tokens"); [ "$code" = "302" ] || [ "$code" = "303" ] || fail "a non-admin creating a token gave $code, not a redirect"
login "$T/admin" admin@e2e.test
sue_id=$(curl -s -b "$T/admin" -c "$T/admin" "$B/admin/api-tokens" | grep -o '<option value="[0-9]*"[^>]*>Sue Submitter' | grep -o 'value="[0-9]*"' | grep -o '[0-9]*')
t=$(curl -s -b "$T/admin" -c "$T/admin" "$B/admin/api-tokens" | tok)
page=$(curl -s -b "$T/admin" -c "$T/admin" -d "csrf_test_name=$t&user_id=$sue_id&name=smoke&days=30" "$B/admin/api-tokens")
apitok=$(echo "$page" | grep -o 'id="new-token">[A-Za-z0-9]*' | sed 's/.*>//')
[ ${#apitok} -eq 64 ]                                                    || fail "no API token was shown after creating one"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/api/v1/me");                                      [ "$code" = "401" ] || fail "API without a token gave $code, not 401"
code=$(curl -s -o /dev/null -w '%{http_code}' -b "$T/sue" "$B/api/v1/me");                          [ "$code" = "401" ] || fail "a browser session authenticated the API ($code)"
curl -s -H "Authorization: Bearer $apitok" "$B/api/v1/me" | grep -q '"is_group_level"'               || fail "API /me did not answer with the token"
curl -s -H "Authorization: Bearer $apitok" "$B/api/v1/invoices" | grep -q 'E2E-1'                    || fail "API invoice list is missing the invoice"
curl -s -H "Authorization: Bearer $apitok" "$B/api/v1/invoices" | grep -q 'route_snapshot' && fail "API leaked the route snapshot"
code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Authorization: Bearer $apitok" "$B/api/v1/invoices"); [ "$code" = "405" ] || fail "API POST gave $code, not 405"
curl -s "$B/api/v1/openapi.json" | grep -q '"openapi": "3.1.0"'                                      || fail "OpenAPI description is not served"
ok "API: token auth, session refused, scoped data, read-only, spec served"

echo "ALL CHECKS PASSED"
