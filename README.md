<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Gateway book and reconciliation

Every `gateway:simulate` scenario records its events before delivery in the private
`storage/app/private/gateway-book.jsonl` file. Each line contains `institution_code`
and the original Xendit-shaped `payload`, never a signature or HMAC secret. Retries
record one event, not one event per delivery attempt. The `lost` scenario records a
capture without sending a webhook:

```bash
vendor/bin/sail artisan gateway:simulate lost UNIV-A INV-2026-0001
vendor/bin/sail artisan reconcile:run
vendor/bin/sail artisan reconcile:run --apply
vendor/bin/sail artisan reconcile:run --hours=72 --apply
```

The default window is the inclusive interval from 48 hours ago to the command's
start time, using `data.updated` / `occurred_at`, not delivery time. Dry runs change
nothing. Reports identify the institution, invoice (when matched), and event key:

- `missing_webhook`: a gateway event absent from the institution's ledger.
- `missing_gateway_event`: a ledger event absent from that institution's book.
- `amount_mismatch`: the same event has different gateway and ledger amounts.

Only missing webhooks are applied. They use the normal processing Action, invoice
row lock, idempotency constraint, audit log, and status calculation, with transaction
source `reconciliation`. Events are applied in event-time order, so a refund delivered
before its payment can be recovered. The other differences are logged as warnings
and require human review; reconciliation never rewrites or deletes ledger rows.
An event key assigned to a different invoice is reported on both invoice sides
and never auto-applied; the conflicting ledger assignment needs human review.
An invalid or unreadable book fails the command instead of silently skipping entries.
The simulator validates its generated payloads before recording them. Reconciliation
streams the full JSONL history to validate it, retaining payloads only for the lookback
window; a rotated or indexed book is the upgrade path when a local mock grows too large.
Keep the book: it is the mock gateway's append-only source of truth, not a delivery log.

The scheduler runs `reconcile:run --apply` hourly with `withoutOverlapping()`. Start
the Sail stack and run the scheduler in the foreground while developing:

```bash
vendor/bin/sail up -d
vendor/bin/sail artisan schedule:list
vendor/bin/sail artisan schedule:work
```

The scheduler uses the application's configured cache store for its overlap lock.
No queue worker is required. Stop `schedule:work` with Ctrl+C. If a crashed process
leaves a stale lock, confirm no reconciliation is running before using
`vendor/bin/sail artisan schedule:clear-cache`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
