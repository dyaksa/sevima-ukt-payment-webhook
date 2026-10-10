# Project: UKT Payment Webhook (SEVIMA M2)

## Overview

Multi-institution backend for tuition (UKT) invoices, one per student per semester. It receives payment-gateway notifications by webhook and keeps every invoice status equal to the real payment state, because that status decides whether a student may enrol in courses (KRS).

## Stack and running

- Laravel on PHP 8.5, PostgreSQL, Pest, Pint. Test and format commands are in `.claude/rules/laravel-boost.md`.
- Approved dependencies: Laravel Sail (dev), Laravel Sanctum, Scribe (dev). Every other dependency needs the candidate's approval.
- Sail runs the app and PostgreSQL, with a separate test database. Setup steps go in `README.md`.

## Architecture

- Concepts: Institution (own code and HMAC secret), Invoice, Transaction (ledger row per money event), WebhookLog (audit row per received notification).
- Invoice status is a pure function of the invoice amount and its transactions, ordered by `occurred_at` (event time, not arrival). It is recomputed inside the transaction that records each event and stored on the invoice.
- Webhook processing runs synchronously in one database transaction with a row lock on the invoice.

## Business rules

### Multi-institution

- One database serves many institutions. Every domain table carries `institution_id`, and every query is scoped to exactly one institution through a global scope or an institution relation.
- The institution comes from the authenticated user. For webhooks it comes from the institution code header once the signature is valid. A request body's `institution_id` is ignored.
- `invoice_number` is unique per institution (`institution_id` + `invoice_number`) and may repeat across institutions.
- Users see and change only their own institution's data. Cross-institution access returns 404.

### Webhook

- One URL serves all institutions. Headers: `X-Institution-Code` and `X-Signature`.
- `X-Signature` is HMAC-SHA256 over the **raw body**, using the secret of the institution named in the code header. Compare with `hash_equals` before parsing the payload.
- Every received notification is logged for audit, rejected ones included.
- The body follows Xendit's webhook shape (`event`, `business_id`, `created`, `data`). The invoice number is `data.reference_id`; the event time used for ordering is `data.updated`; only `IDR` is accepted. Refunds carry `data.payment_id` instead of an invoice number and find their invoice through the gateway payment id stored on an earlier transaction of the same institution.
- Events (wire name, accepted alias): `payment.authorization` (`payment.pending`) informational; `payment.capture` (`payment.success`) success, paid amount is the sum of `data.captures[].capture_amount`; `payment.failure` recorded only; `payment_request.expiry` (`payment.expired`) expiry; `refund.succeeded` (`payment.refunded`) refund; `refund.failed` recorded only.
- The idempotency key is the event name plus the id of its resource (`data.payment_id`, `data.id` for refunds, `data.payment_request_id` for expiry), unique per institution in the database.
- Responses: duplicate idempotency key returns 200 with no effect; bad signature or unknown code returns an identical 401; invalid payload returns 422; an unknown invoice number or an unknown refund `payment_id` returns 202 and is logged as unmatched.
- Reconciliation runs hourly over a 48-hour lookback. It applies only missing events automatically; amount differences and ledger rows absent from the gateway book are reported, never auto-applied.

### Status and money

- Status precedence: `refunded` > `paid` > `expired` > `unpaid`.
- `paid` needs a success event whose amount equals the invoice amount. A success that arrives after expiry still yields `paid`; an expired event never lowers `paid`.
- A success with a different amount is recorded and flagged, and the status stays. A second exact success is recorded as a duplicate payment, and the status stays.
- A full refund yields `refunded` (final). A partial refund is recorded and flagged `needs_review`, and the status stays.
- `expired` comes only from the gateway's expiry event; no scheduled job sets it by date. Pending (authorization) is informational.
- Amounts are integer rupiah (BIGINT, CHECK > 0). Payload amounts that are fractional or in scientific notation are rejected with 422. No floats anywhere.
- Ambiguous business rules go to the candidate, and the answer goes in `DECISIONS.md`.

## Code conventions

- Follow the Laravel Boost guidelines and sibling files. Create files with `php artisan make:`.
- Core logic has automated tests: HMAC verification, the status function, and institution isolation (institution A cannot read or change institution B's data).
- HMAC secrets live in the database, encrypted, and every runtime reader (webhook verification, the simulator) reads them there. The environment only optionally supplies the seeded institutions' secrets through `config/`; when empty, the seeder generates one and prints it once.
- Documentation and comments are written in English.

### Git workflow

- One branch per ticket, cut from an up-to-date `master`, named by Conventional Branch: `<type>/<description>` in lowercase letters, digits, and hyphens. `feat/` for ticket features, `fix/` for bug fixes, `chore/` for docs, config, and tooling. The ticket number leads the description: `feat/03-student-invoices`.
- One meaningful change per commit, written as a Conventional Commit: `<type>(<optional scope>): <summary>` with `feat`, `fix`, `test`, `refactor`, `docs`, `chore`, `build`, or `ci`; the body explains why. Example: `feat(invoices): list a student's invoices`.
- When the ticket's tests are green, push the branch and open a pull request to `master` with `gh pr create`: the title uses the same Conventional Commit format, and the body starts with `Closes #<issue>` for the ticket's GitHub issue and lists what changed, the decisions to review, and the test evidence.
- Merging is the candidate's step, after review. Use a merge commit rather than a squash, so the granular commits stay in `master`'s history.

### Code architecture (Laravel patterns)

One pattern per task. Before editing, read the matching `rules/*.md` in the `laravel-best-practices` skill.

| Task | Pattern |
|---|---|
| Handling an HTTP request | Thin resource controller (`index`, `show`, `store`). A `FormRequest` validates and authorizes, one Action runs the operation, a `JsonResource` shapes the response. Route model binding, including custom keys (`{invoice:invoice_number}`). |
| Business operation | One Action class per operation in `app/Actions`, with a `handle()` method and constructor-injected dependencies. Controllers and commands call the same Action. |
| Pure logic | The status calculator is a pure Action: it takes an invoice and its transactions and returns a status enum. No queries, no side effects. |
| Expected outcomes | An Action returns an outcome enum (accepted, duplicate, unmatched) and the controller maps it to an HTTP status. Exceptions cover unexpected failures and rejections (401), rendered by the exception's `render()` method. |
| Fixed values | Backed enums from `make:enum`, TitleCase cases, cast through `casts()`. |
| Identifiers | Primary keys are time-ordered UUIDs (UUIDv7) through `HasUuids` and `uuid('id')->primary()`; foreign keys use `foreignUuid`. Exceptions: framework tables (`jobs`, `cache`, `failed_jobs`) and the Sanctum token table's own id, whose `tokenable` morph is still a UUID. |
| Institution isolation | Model trait plus global scope that reads the institution from the `Context` facade. The policy is the second layer. Read routes use scoped binding, so cross-institution access yields 404. |
| Data access | Eloquent for domain tables. Explicit ordering with a tie-breaker (`occurred_at`, then `id`). |
| Data consistency | Unique, foreign key, and CHECK constraints live in the database, because validation cannot close races. Writes run in `DB::transaction` with `lockForUpdate`. |
| Artisan commands | Thin: parse arguments, call an Action, print the result. |
| Abstraction | Interfaces for external boundaries that need faking, or when two implementations exist. One implementation gets no interface. |
| Naming | All code identifiers are English: classes, methods, variables, tables, columns, API fields, token abilities, command names. Tables follow Laravel's default pluralization without `$table`. Brief term to code: institution (institusi) `Institution`/`institution_id`; invoice (tagihan) `Invoice`/`invoice_number`; transaction (transaksi) `Transaction`; webhook log (log notifikasi) `WebhookLog`; student number (NIM) `student_number`; amount (nominal) `amount`; expiry date `expires_at`; status `unpaid`/`paid`/`expired`/`refunded` (belum bayar/lunas/kedaluwarsa/dikembalikan). |
| Configuration | `env()` only in `config/`; application code uses `config()`. |

## Hard limits for AI

Each item pairs the limit with the path to follow.

- **Unscoped queries.** Reach domain tables through the institution scope.
- **Trusting `institution_id` from a request.** Derive the institution from authentication, or from a webhook with a valid signature.
- **HMAC secrets in the repo, logs, fixtures, or commit messages**, real or realistic dummies. Use the environment.
- **Editing or deleting transactions and webhook logs.** Both are append-only; write a correcting row instead.
- **Verifying HMAC over a parsed body.** Use the raw body.
- **Adding dependencies, editing committed migrations, or integrating a real gateway** without the candidate's approval. Schema changes go in a new migration, and the gateway is the mock.
- **Declaring work done before the related tests run green.**

## Rules loaded every session

Claude Code loads these from `.claude/rules/` alongside this file:

- `laravel-boost.md`: Laravel Boost framework guidelines. Boost regenerates it on `boost:update` (path set in `config/boost.php`), so edit Boost's config rather than the file.
- `karpathy-guidelines.md`: general behavior guidelines for coding agents, kept verbatim.

## Agent skills

### Issue tracker

Issues and specs are tracked as GitHub Issues in this repo, through the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical labels are used as-is: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` plus `docs/adr/` at the repo root. See `docs/agents/domain.md`.
