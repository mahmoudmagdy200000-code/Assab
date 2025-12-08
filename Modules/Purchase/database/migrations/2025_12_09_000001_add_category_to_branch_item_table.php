<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('branch_item')) {
            Schema::table('branch_item', function (Blueprint $table) {
                if (!Schema::hasColumn('branch_item', 'category')) {
                    $table->string('category')->nullable()->after('item_code');
                }
                if (!Schema::hasColumn('branch_item', 'subcategory')) {
                    $table->string('subcategory')->nullable()->after('category');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('branch_item')) {
            Schema::table('branch_item', function (Blueprint $table) {
                if (Schema::hasColumn('branch_item', 'subcategory')) {
                    $table->dropColumn('subcategory');
                }
                if (Schema::hasColumn('branch_item', 'category')) {
                    $table->dropColumn('category');
                }
            });
        }
    }
};

