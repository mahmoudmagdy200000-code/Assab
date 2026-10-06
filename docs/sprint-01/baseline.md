# Runtime Baseline (Sprint 01)

## Authoritative Inputs Provenance
- `ASSAB_AGENT_EXECUTION_AND_REVIEW_PROTOCOL.md` (Version: Original, SHA256: `A96607441BA525F277880225746DE3B61E5CC412B8274CDDC0B26398C8717051`)
- `ASSAB_DATABASE_SCHEMA.md` (Version: 2.0, SHA256: `9DB0105B32C601F96E9EAE5AE44BFB19E8776EEF190B8C7221F3B2733EB4A6D4`)
- `Assab-ERP-Cash-Cycle-Business-Rules-v2.0-EN.md` (Version: 2.0, SHA256: `7BB57AD79B520B1C46C6793AC6396C43024D6F25B30508049A1FAAE25C88EEB6`)
- `Assab-ERP-Sprint-01-Agent-Implementation-Plan.md` (Version: 2.0, SHA256: `37583220BE76F3AB688BFDA3172070E07A65ED0B44507AEF97592344401F37E8`)

## Repository Provenance
- **Backend (`Assab`) Branch:** `sprint/01-financial-foundation`
- **Backend HEAD Commit:** `65a21656` (Includes `docs(S1-03)`)
- **Dashboard (`dashboard`) Branch:** `sprint/01-financial-foundation`
- **Dashboard HEAD Commit:** `0378530867c8a14706213768ce49553cefc203b2`
- **Mobile (`AssabAPP`) Branch:** `main` (Reference ONLY)
- **Mobile HEAD Commit:** `b2453481966fc1ae2cdfcac4161bbb29a3ba5828`

## Execution Context
- **Host System:** Windows OS 
- **PHP Binary:** `C:\Users\COMPUMARTS\.config\herd\bin\php84\php.exe`
- **PHP Version:** `8.4.26 (cli) (built: Sep 22 2026)`
- **Composer Invocation:** `composer` (Version `2.10.2`)
- **Node Binary:** `v22.22.2`
- **Package Manager Invocation:** `pnpm` (Version `11.1.2`)
- **Database Engine:** MySQL (Production/Local Default) / SQLite (in-memory for tests `DB_CONNECTION=sqlite`)

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
**Tested SHA:** `7b895e61832ecc3fd59beb6b843dcaacfc0d7d9d` (Initial codebase prior to documentation)

**Exact Command Executed:**
`php artisan test tests/Feature/ShiftCycleFixesTest.php tests/Feature/ShiftCloseChainTest.php tests/Feature/ShiftHandoverVarianceCustodyTest.php tests/Feature/SalesVarianceAllocationTest.php tests/Feature/HandoverLedgerDateTest.php tests/NFR/Security/AuthenticationTest.php`

**Context:** Windows Host, PHP 8.4.26 via Laravel Herd, SQLite in-memory DB.

**Actual Results:**
- `Tests: 41 passed (144 assertions)`
- `Duration: 31.73s`

**Blockers:**
- None observed. The codebase is ready and the baseline tests pass correctly. (There was a missing `vendor/autoload.php` initially which was resolved by `composer install`).
