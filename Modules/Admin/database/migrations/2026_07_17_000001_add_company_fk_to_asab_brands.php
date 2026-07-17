<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * asab_brands.company_id was created as a bare indexed uuid with no FK, so a
 * typo'd companyId on POST /admin/brands silently produced a permanently
 * orphaned brand (and, with an ownerEmail, dragged the bad id into asab_users
 * and the identity map). The controller now validates the id; this closes the
 * hole at the database.
 *
 * Pre-existing orphans cannot be adopted or deleted automatically — they are
 * real brands whose owning company is unknown — so the FK is skipped and logged
 * rather than either exploding the deploy or destroying rows. Re-run after
 * triaging the orphans the log names.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite cannot add a FK to an existing table (no ALTER ... ADD CONSTRAINT).
        if (DB::getDriverName() === 'sqlite' || $this->hasCompanyForeignKey()) {
            return;
        }

        $orphans = DB::table('asab_brands')
            ->whereNotIn('company_id', DB::table('asab_companies')->select('id'))
            ->count();

        if ($orphans > 0) {
            Log::warning("Skipped asab_brands.company_id foreign key: {$orphans} brand row(s) reference a missing company. Triage them with: SELECT id, name, company_id FROM asab_brands WHERE company_id NOT IN (SELECT id FROM asab_companies);");

            return;
        }

        Schema::table('asab_brands', function (Blueprint $t) {
            $t->foreign('company_id', 'asab_brands_company_id_foreign')
                ->references('id')->on('asab_companies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' || ! $this->hasCompanyForeignKey()) {
            return;
        }

        Schema::table('asab_brands', function (Blueprint $t) {
            $t->dropForeign('asab_brands_company_id_foreign');
        });
    }

    private function hasCompanyForeignKey(): bool
    {
        foreach (Schema::getForeignKeys('asab_brands') as $foreignKey) {
            if ($foreignKey['columns'] === ['company_id']) {
                return true;
            }
        }

        return false;
    }
};
