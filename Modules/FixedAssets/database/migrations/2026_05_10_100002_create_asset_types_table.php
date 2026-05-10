<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('asset_types')) {
            return;
        }

        Schema::create('asset_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->uuid('parent_id')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');

            $table->foreign('parent_id')
                ->references('id')
                ->on('asset_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
