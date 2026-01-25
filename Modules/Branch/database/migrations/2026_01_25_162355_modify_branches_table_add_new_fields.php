<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add new columns first (before modifying existing ones)
        Schema::table('branches', function (Blueprint $table) {
            // Add latitude and longitude as separate columns
            $table->decimal('lat', 10, 8)->nullable()->after('name');
            $table->decimal('lng', 11, 8)->nullable()->after('lat');
            
            // Add closing_hours as string first (we'll convert it later)
            $table->string('closing_hours')->nullable()->after('opening_hours');
            
            // Add branch manager relationship
            $table->uuid('branch_manager_id')->nullable()->after('image');
            
            // Add branch manager image
            $table->string('branch_manager_image')->nullable()->after('branch_manager_id');
        });

        // Step 2: Clean and migrate existing opening_hours data
        // Extract opening and closing times from format like "08:00 - 22:00"
        DB::table('branches')->whereNotNull('opening_hours')->get()->each(function ($branch) {
            $openingHours = $branch->opening_hours;
            
            // Parse format like "08:00 - 22:00" or "08:00-22:00"
            if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $openingHours, $matches)) {
                $openingTime = $matches[1];
                $closingTime = $matches[2];
                
                // Ensure time format is HH:MM:SS
                $openingTime = $this->normalizeTime($openingTime);
                $closingTime = $this->normalizeTime($closingTime);
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update([
                        'opening_hours' => $openingTime,
                        'closing_hours' => $closingTime,
                    ]);
            } else {
                // If format doesn't match, try to extract just the opening time
                // and set closing_hours to null
                $openingTime = $this->normalizeTime($openingHours);
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update([
                        'opening_hours' => $openingTime,
                        'closing_hours' => null,
                    ]);
            }
        });

        // Step 3: Extract lat/lng from map_coordinates if exists
        DB::table('branches')->whereNotNull('map_coordinates')->get()->each(function ($branch) {
            $coordinates = $branch->map_coordinates;
            
            // Parse format like "24.7136,46.6753" or "24.7136, 46.6753"
            if (preg_match('/([\d.]+)\s*,\s*([\d.]+)/', $coordinates, $matches)) {
                $lat = (float) $matches[1];
                $lng = (float) $matches[2];
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update([
                        'lat' => $lat,
                        'lng' => $lng,
                    ]);
            }
        });

        // Step 4: Now modify the columns to their final types
        Schema::table('branches', function (Blueprint $table) {
            // Change opening_hours from string to time
            $table->time('opening_hours')->nullable()->change();
            
            // Change closing_hours from string to time
            $table->time('closing_hours')->nullable()->change();
            
            // Add foreign key constraint for branch_manager_id
            $table->foreign('branch_manager_id')
                ->references('id')
                ->on('branch_managers')
                ->nullOnDelete();
            
            // Add index for performance
            $table->index('branch_manager_id');
        });

        // Step 5: Remove old columns after data migration
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['location', 'map_coordinates']);
        });
    }

    /**
     * Normalize time string to HH:MM:SS format
     */
    private function normalizeTime(?string $time): ?string
    {
        if (empty($time)) {
            return null;
        }
        
        // Remove any extra spaces
        $time = trim($time);
        
        if (empty($time)) {
            return null;
        }
        
        // If already in HH:MM:SS format, return as is
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        
        // If in HH:MM format, add :00 for seconds
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            $hours = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $minutes = $matches[2];
            return "{$hours}:{$minutes}:00";
        }
        
        // If can't parse, return null
        return null;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Convert opening_hours and closing_hours back to combined format
        DB::table('branches')->whereNotNull('opening_hours')->get()->each(function ($branch) {
            $openingTime = $branch->opening_hours;
            $closingTime = $branch->closing_hours;
            
            // Combine opening and closing times into "08:00 - 22:00" format
            if ($openingTime && $closingTime) {
                // Extract time part (remove seconds if present)
                $opening = preg_replace('/:\d{2}$/', '', $openingTime);
                $closing = preg_replace('/:\d{2}$/', '', $closingTime);
                $combined = "{$opening} - {$closing}";
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update(['opening_hours' => $combined]);
            } elseif ($openingTime) {
                // If only opening time exists, use it as is
                $opening = preg_replace('/:\d{2}$/', '', $openingTime);
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update(['opening_hours' => $opening]);
            }
        });

        // Step 2: Combine lat/lng back into map_coordinates
        DB::table('branches')->whereNotNull('lat')->whereNotNull('lng')->get()->each(function ($branch) {
            $coordinates = "{$branch->lat},{$branch->lng}";
            
            DB::table('branches')
                ->where('id', $branch->id)
                ->update(['map_coordinates' => $coordinates]);
        });

        // Step 3: Modify column types and restore old structure
        Schema::table('branches', function (Blueprint $table) {
            // Drop foreign key and index first
            $table->dropForeign(['branch_manager_id']);
            $table->dropIndex(['branch_manager_id']);
            
            // Revert opening_hours to string
            $table->string('opening_hours')->nullable()->change();
            
            // Add back old columns
            $table->string('location')->nullable()->after('name');
            $table->string('map_coordinates')->nullable()->after('location');
        });

        // Step 4: Drop new columns
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'lat',
                'lng',
                'closing_hours',
                'branch_manager_id',
                'branch_manager_image'
            ]);
        });
    }
};
