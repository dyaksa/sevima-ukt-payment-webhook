# UKT Payment Webhook

A multi-institution tuition invoice service for SEVIMA M2. A student's course-enrolment eligibility depends on an accurate payment status. Invoice status is recomputed from an append-only ledger, not from the last webhook received.

## Reviewer entry points

- [API reference](public/docs/index.html): generated Scribe HTML with bundled assets; open locally without running Laravel.
- [OpenAPI specification](public/docs/openapi.yaml) and [Postman collection](public/docs/collection.json).
- [DECISIONS.md](DECISIONS.md): five primary decisions and smaller implementation rulings.
- [AI_NOTES.md](AI_NOTES.md): collaboration notes with candidate-confirmed personal experience; original-template verification and candidate-run full-suite evidence remain pending.
- [GitHub specification](https://github.com/dyaksa/sevima-ukt-payment-webhook/issues/2).

The generated API examples are synthetic, not database samples. Webhook signature and staff token examples are placeholders. Scribe has no response-call or model-creation strategies enabled, and the browser's Try It Out is disabled.

## Stack and dependencies

PHP 8.5, Laravel 13, PostgreSQL 18, Pest 5 and Pint. `composer.lock` fixes installed versions.

Approved additions: Sail (development containers), Sanctum (staff API tokens), Scribe (development-only API documentation). Laravel's existing development tools, including Boost, remain in place. There is no real payment-gateway integration, third-party mock server or queue dependency.

## Setup with Sail

Requirements: Git, Docker with Compose, and an available application port. No host PHP or Node installation is required. Run commands from the cloned repository root.

```bash
git clone git@github.com:dyaksa/sevima-ukt-payment-webhook.git
cd sevima-ukt-payment-webhook
cp .env.example .env
```

Edit the local `.env` before starting:

- Set `APP_PORT=8000` and `APP_URL=http://localhost:8000` (or choose another matching host port).
- Keep `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, and `DB_PORT=5432`. The host's forwarded database port is separate; change `FORWARD_DB_PORT` if needed.
- Choose local `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` values. The example credentials are development defaults, not production credentials.
- Set `WWWUSER` / `WWWGROUP` to your host user/group IDs if Docker file ownership needs adjustment.
- Optionally set the three HMAC variables described below before the first seed.

For a fresh clone with no `vendor/`, bootstrap Composer once in a temporary container. Scripts and platform validation are deferred until Sail's PHP 8.5 container is available:

```bash
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/var/www/html" -w /var/www/html \
  composer:2 install --ignore-platform-reqs --no-scripts --no-interaction

vendor/bin/sail up -d
vendor/bin/sail composer install --no-interaction
vendor/bin/sail artisan key:generate --no-interaction
vendor/bin/sail artisan migrate --seed --no-interaction
git config core.hooksPath .githooks
```

The temporary Composer container is only the bootstrap exception; run subsequent PHP, Composer, Artisan and Node commands through Sail. Wait for PostgreSQL to be healthy before migrating. Do not use `migrate:fresh` on a database whose history must be preserved. Generate `APP_KEY` only for a new installation: replacing it makes existing encrypted HMAC secrets unreadable.

The API and committed Scribe docs need no Vite build. To work on the default frontend, use `vendor/bin/sail npm ci` and `vendor/bin/sail npm run build`. Stop containers with `vendor/bin/sail stop`; retain the database volume.

### Institutions and secrets

The seeder creates `UNIV-A`, `UNIV-B`, and `UNIV-C`, each with a finance user:

| Code | Staff email | Optional initial HMAC variable |
| --- | --- | --- |
| UNIV-A | finance@univ-a.test | INSTITUTION_SECRET_UNIV_A |
| UNIV-B | finance@univ-b.test | INSTITUTION_SECRET_UNIV_B |
| UNIV-C | finance@univ-c.test | INSTITUTION_SECRET_UNIV_C |

These variables are empty placeholders in `.env.example`. Put separately delivered values in your local `.env`, never in a commit. When empty, the seeder generates a random 64-hex-character secret and prints it once; retain that output securely, not in an application log or shared transcript. Send the three actual values separately by the email channel required by the brief, not in this repository.

After seeding, the encrypted database attribute `institutions.hmac_secret` is the runtime source of truth for both verification and simulation. Changing environment variables or re-running the seeder does not rotate existing secrets. Preserve `APP_KEY` and back up the database securely. No secret-read endpoint is exposed.

Provision another institution without a deploy:

```bash
vendor/bin/sail artisan institution:create UNIV-D "University D" \
  --staff-email=finance@univ-d.test --no-interaction
```

The new secret is shown once. No staff password login or public token endpoint is provided; the operator issues tokens through the CLI.

### Staff tokens

```bash
vendor/bin/sail artisan token:issue finance@univ-a.test --no-interaction
vendor/bin/sail artisan token:issue finance@univ-b.test --ability=invoices:read --no-interaction
```

The first command grants both `invoices:read` and `invoices:write` by default. `--ability` may be repeated to select abilities. Copy only the token line and send it as `Authorization: Bearer <token>`. Tokens and institution ownership come from the staff user, never from a request body's `institution_id`.

## API and status contract

| Method | Path | Authentication | Success |
| --- | --- | --- | --- |
| POST | /api/v1/invoices | invoices:write staff token | 201, newly unpaid invoice |
| GET | /api/v1/invoices?student_number=…&semester=… | invoices:read staff token | 200, newest semester first; semester optional |
| GET | /api/v1/invoices/{invoice_number} | invoices:read staff token | 200, invoice and ordered transaction history |
| POST | /api/v1/webhooks/payments | X-Institution-Code and X-Signature | 200 accepted/duplicate; 202 unmatched |

Invoice creation accepts `invoice_number`, `student_number`, `semester` (`YYYY1` or `YYYY2`), positive integer-rupiah `amount`, and a future `expires_at`. Invoice numbers are unique within an institution; repeating an invoice number returns 422. The business intent is one invoice per student per semester, but the current implementation does not enforce student/semester uniqueness for different invoice numbers. An invoice number may repeat in another institution. Read responses are wrapped in `data`; creation omits unloaded history, while list/show include `transactions`. A list with no matches returns `data: []`.

Missing staff authentication returns 401; a token lacking the required ability returns 403. An unknown or cross-institution invoice returns 404. Validation returns 422 with field errors. Domain IDs are UUIDv7 internally, but the show route binds by invoice number.

History is ordered by `occurred_at`, then `id`, and includes `kind`, nullable `annotation` and `amount`, `gateway_payment_id`, `source`, `idempotency_key`, and `recorded_at`. The source is `webhook` or `reconciliation`. Annotations are derived, not ledger edits: `amount_mismatch`, `duplicate_payment`, or `needs_review`.

Status precedence is `refunded`, `paid`, `expired`, `unpaid`:

- `paid` requires a capture whose summed amount equals the invoice amount exactly. Separate underpayments are not accumulated into a payment.
- A late exact payment still makes an expired invoice paid; an expiry event never lowers paid.
- Wrong amounts are recorded and flagged without displacing the existing valid state. A second exact payment has a different event key, is recorded, and is annotated `duplicate_payment`.
- Successful cumulative refunds reaching the invoice amount produce final `refunded`, provided an exact payment exists. Partial refunds remain recorded and annotated `needs_review`; until the cumulative full amount is reached, the status stays paid.
- Authorization and failed payment/refund attempts are recorded but do not move money or change status. Merely passing `expires_at` never expires an invoice; expiry requires a gateway event.

## Manual walkthrough

Use Bash for the following snippets. They create disposable demo invoices on a running development server; use a fresh run ID rather than reusing an existing student/semester pair. Do not run against production.

### 1. Create, read and list an invoice

After setup and token issuance:

```bash
BASE_URL=http://localhost:8000
printf 'Paste the UNIV-A staff token: '
IFS= read -r -s TOKEN
printf '\n'
RUN=$(date +%Y%m%d%H%M%S)
EXPIRES_AT=$(vendor/bin/sail php -r 'print gmdate("Y-m-d\TH:i:s\Z", time() + 86400);')

create_invoice() {
  curl --fail-with-body -sS -X POST "$BASE_URL/api/v1/invoices" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    --data "{\"invoice_number\":\"INV-$RUN-$1\",\"student_number\":\"DEMO-$RUN-$2\",\"semester\":\"20261\",\"amount\":1500000,\"expires_at\":\"$EXPIRES_AT\"}"
  printf '\n'
}

create_invoice success 1
curl -sS "$BASE_URL/api/v1/invoices/INV-$RUN-success" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
curl -sS --get "$BASE_URL/api/v1/invoices" \
  --data-urlencode "student_number=DEMO-$RUN-1" --data-urlencode 'semester=20261' \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

Expect unpaid and empty history. Calling `create_invoice success 1` again returns 422. Reading the same invoice with a UNIV-B read token returns 404; listing that student with UNIV-B returns an empty list.

### 2. Exercise every simulator scenario

The simulator sends real HTTP requests from inside Sail, signs using the database secret, and records events in the gateway book before delivery. Use the internal service URL so a custom host port cannot send the container to its own wrong port:

```bash
WEBHOOK_URL=http://laravel.test/api/v1/webhooks/payments
vendor/bin/sail artisan gateway:simulate success UNIV-A "INV-$RUN-success" \
  --url="$WEBHOOK_URL" --no-interaction

n=2
for scenario in lost bad-signature expiry retry wrong-amount expiry-vs-success \
  authorization-then-success failure refund refund-failed refund-before-payment; do
  create_invoice "$scenario" "$n"
  vendor/bin/sail artisan gateway:simulate "$scenario" UNIV-A "INV-$RUN-$scenario" \
    --url="$WEBHOOK_URL" --no-interaction
  curl -sS "$BASE_URL/api/v1/invoices/INV-$RUN-$scenario" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
  printf '\n'
  n=$((n + 1))
done
```

| Scenario | Webhook answers | Expected invoice/history before reconciliation |
| --- | --- | --- |
| success | 200 accepted | paid, one payment |
| lost | no delivery | unpaid, no transaction; capture exists in gateway book |
| bad-signature | 401 | unpaid, no transaction; unauthorized audit row |
| expiry | 200 accepted | expired, expiry with null amount |
| retry | first accepted, five duplicate 200s | paid, one payment, six audit rows |
| wrong-amount | 200 accepted | unpaid, payment annotated amount_mismatch |
| expiry-vs-success | two accepted 200s | paid; older expiry delivered after capture, history still event-time ordered |
| authorization-then-success | two accepted 200s | paid, authorization with null amount followed by payment |
| failure | 200 accepted | unpaid, failed_payment with null amount |
| refund | two accepted 200s | refunded, exact payment followed by full refund |
| refund-failed | two accepted 200s | paid, failed refund recorded without reducing balance |
| refund-before-payment | unmatched 202, then accepted 200 | paid initially; unmatched refund has no ledger row yet, recoverable by reconciliation |

Repeating a simulator invocation generates a new resource ID: it is a new payment, not a retry. Use the `retry` scenario to resend identical bytes and exercise idempotency. To choose event time, append `--occurred-at=2026-10-11T12:00:00Z`; the same payload time window as webhooks is enforced.

### 3. Reconcile missing deliveries

```bash
vendor/bin/sail artisan reconcile:run --no-interaction
vendor/bin/sail artisan reconcile:run --apply --no-interaction
vendor/bin/sail artisan reconcile:run --apply --no-interaction
curl -sS "$BASE_URL/api/v1/invoices/INV-$RUN-lost" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
curl -sS "$BASE_URL/api/v1/invoices/INV-$RUN-refund-before-payment" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```

The dry run reports missing events and changes no ledger/status. Apply recovers lost payments and the previously unmatched refund; they become paid and refunded respectively, with `source: reconciliation` on recovered rows. The bad-signature scenario's gateway-side capture is also eligible for recovery, because it was recorded before the intentionally bad delivery. Reapplying writes no duplicate ledger row. It can still report unresolved differences from other earlier development runs.

### 4. Send a hand-signed request

Create a separate invoice, then use the secret retained at first seeding or supplied separately. Do not paste it into source code. HMAC uses the 64-character secret as a **literal string**, not a hex-decoded key. Keep the temporary body unchanged between signing and sending; `--data-binary` preserves the exact bytes, including its trailing newline.

```bash
create_invoice manual 99
printf 'Paste the UNIV-A HMAC secret: '
IFS= read -r -s HMAC_SECRET
printf '\n'
BODY=$(mktemp)
EVENT_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)
cat > "$BODY" <<JSON
{"event":"payment.capture","business_id":"UNIV-A","created":"$EVENT_TIME","data":{"payment_id":"py-$RUN-manual","reference_id":"INV-$RUN-manual","currency":"IDR","status":"SUCCEEDED","updated":"$EVENT_TIME","captures":[{"capture_amount":1500000}]}}
JSON
SIGNATURE=$(openssl dgst -sha256 -hmac "$HMAC_SECRET" "$BODY" | cut -d ' ' -f 2)
curl -i -sS -X POST "$BASE_URL/api/v1/webhooks/payments" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'X-Institution-Code: UNIV-A' -H "X-Signature: $SIGNATURE" \
  --data-binary "@$BODY"
```

Expect `200 {"outcome":"accepted"}`; repeat the exact curl for `200 {"outcome":"duplicate"}`. This hand-created event is absent from the mock gateway book, so reconciliation reports `missing_gateway_event` and never deletes it. A nonempty wrong signature (for example `invalid`) and an unknown institution code both return the identical `401 {"message":"Invalid signature."}`. For payload validation, change `IDR` to `USD`, sign the new bytes again, and expect 422. For unmatched behavior, use a new payment ID and an unknown invoice reference, re-sign, and expect `202 {"outcome":"unmatched"}`.

```bash
rm "$BODY"
unset HMAC_SECRET SIGNATURE TOKEN
```

The local openssl example passes the key as a process argument; run it only on a trusted development machine. Automated consumers should hold secrets in their own protected runtime configuration.

## Mock gateway contract

This is **Xendit-shaped plus HMAC**, not a claim of compatibility with Xendit's production authentication. One webhook URL serves all institutions. Headers: `X-Institution-Code` selects the secret, `X-Signature` is lowercase hex HMAC-SHA256 of the raw body. Compare with `hash_equals` before parsing JSON. `business_id` and body `institution_id` never establish ownership.

Envelope fields: `event`, `business_id`, `created`, `data`. `data.updated` is the event time used for ledger ordering, with a timezone offset, strictly after 2000-01-01 and at most one hour ahead of the server clock. `created` is envelope metadata, not ordering. `data.currency` must be `IDR`. Every monetary value is a JSON integer or canonical positive digit string, from 1 to one trillion rupiah per value; floats, decimal notation and scientific notation are rejected.

| Canonical event | Accepted alias | Required lookup/resource fields | Effect |
| --- | --- | --- | --- |
| payment.authorization | payment.pending | payment_id, reference_id | Informational authorization |
| payment.capture | payment.success | payment_id, reference_id, nonempty captures[].capture_amount | Payment amount is the capture sum |
| payment.failure | — | payment_id, reference_id | Failed attempt only |
| payment_request.expiry | payment.expired | payment_request_id, reference_id | Gateway expiry |
| refund.succeeded | payment.refunded | id, payment_id, amount | Successful refund |
| refund.failed | — | id, payment_id, amount | Failed refund only |

The brief's four families are pending, success, expiry and refund; failed payment/refund events are also supported. Refunds find an invoice through a previous transaction's `gateway_payment_id` within the same institution, not through an invoice number. Missing invoice references or unknown refund payment IDs return 202 and are audited as unmatched. Successful delivery and duplicates return 200; bad signature/unknown code return identical 401; invalid payload returns 422. Unexpected failures return non-2xx and must be retried by a gateway.

The idempotency key is canonical event name plus the resource ID, unique per institution in PostgreSQL. Aliases share the canonical key. A repeated key changes no invoice or transaction but creates its own audit row. The mock `retry` scenario sends six identical deliveries immediately; it does not emulate timed backoff.

### Gateway book and reconciliation

Every simulator scenario records events before delivery in private `storage/app/private/gateway-book.jsonl`. Each line has `institution_code` and the original payload, never an HMAC secret or signature. Retries record one gateway event, not one per delivery. Keep the book: it is the mock gateway's append-only source of truth, not a delivery log.

The default lookback is the inclusive interval from 48 hours ago to command start, using event time. Override with `vendor/bin/sail artisan reconcile:run --hours=72 --apply --no-interaction`. Reports identify institution, invoice when resolved, and event key:

- `missing_webhook`: gateway event absent from that institution's ledger; the only auto-applied difference.
- `missing_gateway_event`: ledger event absent from that institution's book; report only.
- `amount_mismatch`: same event/invoice has different gateway and ledger amounts; report only.

Recovery calls the existing processing Action with invoice row lock, idempotency, audit and status calculation, marking source `reconciliation`. Events are applied in event-time order. Conflicting invoice assignments are reported on both sides and never auto-applied. Unreadable or invalid book content fails the command before any application rather than silently skipping entries. Warnings need human judgement; reconciliation never rewrites or deletes transactions or audit rows. It streams the entire history for validation, retaining only the lookback payloads; rotation or an index is the upgrade path for a growing local mock.

### Scheduler

Run in a separate terminal:

```bash
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan schedule:work
```

`reconcile:run --apply` runs hourly with `withoutOverlapping()`, using the application's cache store for the lock. No queue worker is required. Stop `schedule:work` with Ctrl+C. If a crashed process leaves a stale lock, first confirm no reconciliation is running, then use `vendor/bin/sail artisan schedule:clear-cache`.

## Automated checks and documentation generation

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail bin pint --dirty --format agent
vendor/bin/sail artisan scribe:generate --no-interaction
```

Tests use PostgreSQL database `testing`, not the application database. Sail creates it through PostgreSQL's initialization script on a **new** database volume. For an older volume without it, create that separate database explicitly with your PostgreSQL administrative tooling; never change tests to use the live database. `phpunit.xml` sets the testing connection/database and disables `DB_URL` overrides. Clear cached application configuration with `vendor/bin/sail artisan config:clear --no-interaction` before testing if it was cached.

Coverage includes raw-body HMAC and all outcomes, integer money, event ordering and status calculation, refunds/anomalies, tenant read/write isolation, database constraints, simulator/book behavior and reconciliation. Tests prohibit stray external HTTP requests. The enabled pre-commit hook protects committed migrations and secret-shaped values; the agent Stop hook checks tests after PHP-related changes while Sail is running.

Commit generated `public/docs/` assets together with controller annotations and `config/scribe.php`. Intermediate `.scribe` files are generation caches, not the source of the contract. Documentation generation does not seed data, issue tokens, call endpoints, or query model examples.

## Assumptions and limits

- The brief does not specify the institution-code location; this implementation uses `X-Institution-Code`.
- `refunded` is a fourth status beyond the brief's unpaid/paid/expired states.
- Code identifiers are English; the naming map below connects them to the Indonesian brief.
- No transaction volume or measured throughput target was supplied. The design assumes tens of thousands of invoices, with short synchronous, row-locked webhook processing. This is a capacity assumption, **not a benchmark result**. A queue is the upgrade path if burst load or latency measurements justify it; the persistence/idempotency boundary must remain intact.
- Ledger and audit history are append-only. A correcting event, not a destructive edit, represents a correction.
- The gateway is a local mock. The business contract deliberately adds HMAC; no production gateway credentials or integration are included.
- Scribe, Sanctum and Sail are approved project dependencies. No web-based staff login, invoice editing/deletion, secret rotation endpoint or scheduled date-based expiry is supplied.

### Naming map

| Indonesian brief | English code/API |
| --- | --- |
| institusi | Institution / institution_id |
| tagihan | Invoice / invoice_number |
| transaksi | Transaction |
| log notifikasi | WebhookLog |
| NIM | student_number |
| nominal | amount (integer rupiah) |
| tanggal kedaluwarsa | expires_at |
| belum bayar / lunas / kedaluwarsa / dikembalikan | unpaid / paid / expired / refunded |
