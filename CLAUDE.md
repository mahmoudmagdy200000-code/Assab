# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Assab is an API-only Laravel 12.x modular monolith for branch/cashier/inventory/supplier management. Built with PHP 8.2+, using nwidart/laravel-modules for module organization. The project targets the Saudi market (Asia/Riyadh timezone, Arabic + English localization).

## Common Commands

```bash
# Full project setup (install deps, env, key, migrations, npm)
composer setup

# Development server (Laravel + queue worker + Vite concurrently)
composer dev

# Run tests (Pest PHP)
composer test

# Run a single test file
./vendor/bin/pest tests/Feature/SomeTest.php

# Run a specific test by name
./vendor/bin/pest --filter="test name here"

# Code formatting (Laravel Pint / PSR-12)
./vendor/bin/pint

# Create a new module
php artisan module:make ModuleName
```

## Architecture

### Module Structure (Modules/)

16 modules under `Modules/`: Admin, Aggregator, Branch, BranchManagers, BrandOwner, Cashier, Custody, Expense, Inventory, Notification, Purchase, RecurringOrder, Settings, Shift, Supplier.

Each module follows:
```
Modules/{Name}/
├── app/Http/Controllers/    # Thin controllers, HTTP concerns only
├── app/Http/Requests/       # Form request validation
├── app/Models/              # Eloquent models (UUIDs, SoftDeletes)
├── app/Services/            # Business logic layer
├── app/Repositories/        # Data access abstraction
├── app/Transformers/        # API resource classes
├── app/Events/              # Domain events
├── app/Listeners/           # Event handlers
├── app/Jobs/                # Async queue jobs
├── app/Policies/            # Authorization
├── routes/api.php           # Module routes (auto-loaded)
├── database/migrations/
└── database/seeders/
```

### Request Flow

```
Request → FormRequest (validation) → Controller → Service → Repository → Model
                                                                    ↓
                                                          Resource/Transformer → JSON
```

Controllers must stay thin. All business logic goes in Services. Repositories abstract data access.

### Base Classes (app/Http/)

All modules must extend these:

- **BaseController** (`app/Http/Controllers/BaseController.php`): Provides unified response methods (`successResponse`, `createdResponse`, `updatedResponse`, `deletedResponse`, `paginatedResponse`, `errorResponse`, etc.)
- **BaseResource** (`app/Http/Resources/BaseResource.php`): Formatting helpers for timestamps, currency, images, booleans, status fields
- **BaseRequest** (`app/Http/Requests/BaseRequest.php`): Common validation rules for pagination, search, date ranges, file uploads

### API Response Format

All endpoints return a unified JSON structure:
```json
{"success": true, "message": "...", "data": {}, "meta": {}}
```
Errors: `{"success": false, "message": "...", "errors": {}}`

See `docs/API_RESPONSE_FORMAT.md` for full specification.

### Routing

`routes/api.php` dynamically loads all `Modules/*/Routes/api.php`. All routes are wrapped in `apilocale` middleware (reads `Accept-Language` header). Protected routes use `auth:sanctum` + role middleware (`branch.manager`, `cashier`, `supplier`, etc.).

### Authentication

Laravel Sanctum token-based auth. Models use `HasApiTokens`. No session/cookie auth.

## Database

- MySQL (default), SQLite in-memory for tests
- Models use UUID primary keys (`HasUuids` trait) and `SoftDeletes`
- Seeders run in dependency order via `DatabaseSeeder` (Branch → BranchManager → Shift → Cashier → Aggregator → Category → Supplier → Expense)

## Scheduled Commands

Defined in `bootstrap/app.php`. Key schedules (Asia/Riyadh timezone):
- Shift reminders (every 15min), auto-end overdue shifts (hourly), daily shift reports (23:55)
- Weekly shift renewal (Sundays 00:05)
- OTP cleanup (daily 02:00), backup (daily 03:00)
- Recurring order processing (scheduled in module provider)

## Testing

Pest PHP 4.x with test suites in `tests/Feature/`, `tests/Unit/`, and `tests/NFR/` (non-functional/performance). Tests use SQLite in-memory DB configured in `phpunit.xml`.

## Key Conventions

- No facades in services; use dependency injection
- Eager load relationships to prevent N+1 queries
- Use `select()` to limit columns in queries
- Events/Listeners for cross-module communication
- Jobs for async operations with retry logic
- Policies for authorization (not just middleware)
- Real-time features use Pusher/Laravel Broadcasting
