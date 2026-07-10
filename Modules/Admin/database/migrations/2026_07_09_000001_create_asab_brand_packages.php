<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand subscription package catalog (WS6). Replaces the hardcoded
 * فضي/ذهبي/بلاتيني plan+price maps in Subscription/BrandController with an
 * admin-manageable table. Prices are in the same unit as the old map (whole
 * SAR, e.g. gold = 175000).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asab_brand_packages')) {
            return;
        }
        Schema::create('asab_brand_packages', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id')->primary();
            $t->string('code', 32)->unique();
            $t->string('name', 120);
            $t->string('name_en', 120)->nullable();
            $t->unsignedBigInteger('price')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asab_brand_packages');
    }
};
