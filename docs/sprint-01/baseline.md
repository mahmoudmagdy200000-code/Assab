# Runtime Baseline (Sprint 01)

## Backend Repository (`Assab`)
- **Current Branch:** `sprint/01-financial-foundation`
- **HEAD Commit:** `7b895e61832ecc3fd59beb6b843dcaacfc0d7d9d`
- **PHP Version:** `8.4.26`
- **Composer:** Installation successful (`151 installs`), dependencies match `composer.lock`.
- **Database Engine:** MySQL is the default in `.env.example`, while SQLite is used for in-memory testing (`DB_CONNECTION=sqlite` in `phpunit.xml`).

## Dashboard Repository (`dashboard`)
- **Current Branch:** `sprint/01-financial-foundation`
- **HEAD Commit:** `0378530867c8a14706213768ce49553cefc203b2`
- **Node Version:** `v22.22.2`
- **Package Manager:** `pnpm 11.1.2`

## Startup Instructions
The following commands are sufficient for another developer to reproduce the startup and trace routes without invented endpoints:

**Assab (Backend):**
```bash
# 1. Install dependencies
composer install

# 2. Setup environment without secrets
cp .env.example .env
php artisan key:generate

# 3. Create disposable DB fixtures and role accounts
php artisan migrate:fresh --seed

# 4. Start local server
php artisan serve
```

**Dashboard (Frontend):**
```bash
# 1. Install dependencies
pnpm install

# 2. Start local server
pnpm dev
```

**Role Accounts Seeded (Passwords are all `password`):**
- Head Accountant: `head@nakhat.sa`
- Platform Admin: `admin@nakhat.sa`
- Brand Accountants: `accountant.burger@nakhat.sa`, `accountant.shawarma@nakhat.sa`
- Branch Managers: `manager1@nakhat.sa` (mobile app)
- Cashiers: `cashier1001@nakhat.sa` (mobile app)

## Test Results & Blockers
The following actual baseline checks were executed on the core financial shifts:

- `php artisan test tests/Feature/ShiftCycleFixesTest.php tests/Feature/ShiftCloseChainTest.php tests/Feature/ShiftHandoverVarianceCustodyTest.php tests/Feature/SalesVarianceAllocationTest.php tests/Feature/HandoverLedgerDateTest.php tests/NFR/Security/AuthenticationTest.php`

**Actual Results:**
- `Tests: 41 passed (144 assertions)`
- `Duration: 31.73s`

**Blockers:**
- None observed. The codebase is ready and the baseline tests pass correctly. (There was a missing `vendor/autoload.php` initially which was resolved by `composer install`).
