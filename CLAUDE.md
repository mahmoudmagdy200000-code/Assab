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
{ "success": true, "message": "...", "data": {}, "meta": {} }
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

## Development Guardrails, SOLID Principles & Anti-Patterns

To maintain enterprise-grade security, consistency, and performance, Claude Code MUST adhere to the following strict enforcement rules. Do not bypass them for speed or convenience.

### 1. SOLID Principles Applied to Laravel Monolith

- **Single Responsibility (SRP):** Controllers handle HTTP concerns ONLY. Services handle Business Logic ONLY. Repositories handle Data Access/Queries ONLY. Never mix database queries, validation, or response formatting inside a Service or Controller.
- **Open/Closed (OCP):** Extend functionality via Interfaces, Events, or Inheritance. For example, instead of hardcoding SMS logic, inject an interface (e.g., `SmsProviderInterface`) via Dependency Injection so new providers can be added without modifying existing services.
- **Liskov Substitution (LSP):** Any class implementing an interface or extending a base class must fulfill the contract completely without breaking behavior. Concrete implementations must not throw unhandled unexpected exceptions or return unexpected types.
- **Interface Segregation (ISP):** Keep interfaces lean and specialized. Do not force a class to implement methods it doesn't use.
- **Dependency Inversion (DIP):** Depend on abstractions, not concretions. Always type-hint Interfaces/Abstract classes in constructors rather than concrete class names. NEVER use Laravel Facades inside Domain Services; use Constructor Dependency Injection.

### 2. Zero-Trust Security & Access Control

- **No Blanket Authorization:** NEVER return `true` inside FormRequest `authorize()` methods or controller permission checks. Every endpoint must check explicit Roles/Permissions or enforce a Laravel Policy.
- **Multi-Tenancy Isolation:** Always enforce database isolation at the query layer using global/local scopes or explicit where-clauses (e.g., matching the user's `branch_id` or `company_id`). Never rely on user-supplied IDs from the request body or query parameters without validating tenant ownership.
- **Sensitive Data Handling:** Plain-text logging or database storage of Passwords, OTPs, or Reset Tokens is STRICTLY PROHIBITED. Passwords/tokens must use `Hash::make()` and `Hash::check()`. OTP delivery must leverage the `Notification` module; do not use `Log::info()` as a fallback delivery mechanism.

### 3. Clean Code & Laravel Best Practices

- **Skinny Controllers, Fat Services, Lean Models:** Controllers must only handle validation triggers, calling the service, and returning the resource response. Keep models clean from heavy business logic; utilize Query Scopes for data filtering.
- **Database Transactions:** Any multi-step database write operation (inserts/updates/deletes touching multiple rows or tables) MUST be wrapped in a database transaction (`DB::transaction(function () { ... })`) to ensure atomic integrity and zero partial data corruption.
- **Don't Repeat Yourself (DRY):** Reuse code through Traits (like `HasUuids`), Helper functions, or Base Classes. If the same validation or query logic spans multiple endpoints, abstract it.
- **Mass Assignment Protection:** Strictly define `$fillable` or use safe data mapping from validated FormRequests (`$request->validated()`). NEVER use `$request->all()` directly in `create()` or `update()` methods.

### 4. Extreme Performance Optimization

- **N+1 Query Prevention:** Active detection of N+1 queries is mandatory. Check all controller loops and eager-load relations using `with()`. Use `without()` locally if a model has heavy auto-eager-loaded relations (`$with`) that aren't needed in that context.
- **Memory Caps & Scale:** Never return unbounded collections. All list endpoints must either implement `paginatedResponse()` or use strict caps via `take()` or `limit()`.
- **Compound Database Indexes:** When querying database columns that are frequently used in `where`, `orderBy`, or compound foreign keys, evaluate if a Composite Index is needed and include it via a migration. Always optimize for large datasets.

### 5. Structural Consistency & Anti-Pattern Prevention

- **Base Class Enforcement:** Every controller MUST extend `BaseController` and use the unified JSON response helpers (`successResponse`, `errorResponse`, etc.). Mixing raw `response()->json()` with custom arrays is an absolute anti-pattern.
- **Dead-Code Elimination:** Do not create or leave empty controller stubs (e.g., `store`, `update`, `destroy` placeholders) just to satisfy CRUD routing. If a route exists, it must be functional. If not, remove the route or throw a dedicated secure status code. Do not commit commented-out legacy blocks.
- **Domain Exceptions:** Avoid catching generic `\Exception`. Catch specific infrastructure exceptions, or throw custom Domain/Business Exceptions that the global exception handler can translate into uniform JSON responses.

### 6. Verification & Testing Guardrails

- **Test Integrity:** If the local test suite fails due to environmental issues (e.g., SQLite migration incompatibilities), do not proceed with features "on blind faith." Isolate the testing issue, guard the migrations using driver checks (`DB::getDriverName() !== 'sqlite'`), and ensure a passing baseline before shipping.
- **Manual Blueprinting:** For high-risk refactors (like RBAC, Auth, or financial calculation updates), present a text-based blueprint/diff to the user for structural approval before executing file updates.
