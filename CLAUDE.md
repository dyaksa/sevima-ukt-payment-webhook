# Project: UKT Payment Webhook (SEVIMA M2)

## Overview

Multi-institution backend for tuition (UKT) invoices, one per student per semester. It receives payment-gateway notifications by webhook and keeps every invoice status equal to the real payment state, because that status decides whether a student may enrol in courses (KRS).

## Stack and running

- Laravel on PHP 8.5, PostgreSQL, Pest, Pint. Test and format commands are in the Laravel Boost block below.
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

- Follow the Laravel Boost block below and sibling files. Create files with `php artisan make:`.
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

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `vendor/bin/sail composer show --direct` to list direct dependencies with versions, or `vendor/bin/sail composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `vendor/bin/sail artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `vendor/bin/sail artisan test --compact`.

</laravel-boost-guidelines>

# CLAUDE.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.

## Agent skills

### Issue tracker

Issues and specs are tracked as GitHub Issues in this repo, through the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical labels are used as-is: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `CONTEXT.md` plus `docs/adr/` at the repo root. See `docs/agents/domain.md`.
