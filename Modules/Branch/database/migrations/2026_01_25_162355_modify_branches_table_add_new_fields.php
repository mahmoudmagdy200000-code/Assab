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
            // Remove old location and map_coordinates columns
            $table->dropColumn(['location', 'map_coordinates']);
            
            // Add latitude and longitude as separate columns
            $table->decimal('lat', 10, 8)->nullable()->after('name');
            $table->decimal('lng', 11, 8)->nullable()->after('lat');
            
            // Change opening_hours from string to time
            $table->time('opening_hours')->nullable()->change();
            
            // Add closing_hours as time
            $table->time('closing_hours')->nullable()->after('opening_hours');
            
            // Add branch manager relationship
            $table->uuid('branch_manager_id')->nullable()->after('image');
            $table->foreign('branch_manager_id')
                ->references('id')
                ->on('branch_managers')
                ->nullOnDelete();
            
            // Add branch manager image
            $table->string('branch_manager_image')->nullable()->after('branch_manager_id');
            
            // Add index for performance
            $table->index('branch_manager_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            // Drop new columns
            $table->dropForeign(['branch_manager_id']);
            $table->dropIndex(['branch_manager_id']);
            $table->dropColumn([
                'lat',
                'lng',
                'closing_hours',
                'branch_manager_id',
                'branch_manager_image'
            ]);
            
            // Restore old columns
            $table->string('location')->nullable()->after('name');
            $table->string('map_coordinates')->nullable()->after('location');
            
            // Revert opening_hours to string
            $table->string('opening_hours')->nullable()->change();
        });
    }
};
