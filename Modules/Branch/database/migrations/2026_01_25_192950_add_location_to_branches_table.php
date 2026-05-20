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
        Schema::table('branches', function (Blueprint $table) {
            // Add location column if it doesn't exist
            if (! Schema::hasColumn('branches', 'location')) {
                $table->string('location')->nullable()->after('name');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Drop location column if it exists
            if (Schema::hasColumn('branches', 'location')) {
                $table->dropColumn('location');
            }
        });
    }
};
