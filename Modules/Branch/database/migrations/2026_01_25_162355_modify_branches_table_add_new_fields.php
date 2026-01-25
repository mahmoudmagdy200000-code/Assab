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
            // Add latitude and longitude as separate columns (only if they don't exist)
            if (!Schema::hasColumn('branches', 'lat')) {
                $table->decimal('lat', 10, 8)->nullable()->after('name');
            }
            if (!Schema::hasColumn('branches', 'lng')) {
                $table->decimal('lng', 11, 8)->nullable()->after('lat');
            }
            
            // Add closing_hours as string first (we'll convert it later)
            if (!Schema::hasColumn('branches', 'closing_hours')) {
                $table->string('closing_hours')->nullable()->after('opening_hours');
            }
        });

        // Step 2: Clean and migrate existing opening_hours data
        // Extract opening and closing times from format like "08:00 - 22:00"
        // Only process if opening_hours is still a string (contains dash or is not in time format)
        DB::table('branches')->whereNotNull('opening_hours')->get()->each(function ($branch) {
            $openingHours = $branch->opening_hours;
            
            // Check if it's already in time format (HH:MM:SS or HH:MM)
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $openingHours)) {
                // Already in time format, just normalize it
                $openingTime = $this->normalizeTime($openingHours);
                $updateData = ['opening_hours' => $openingTime];
                
                // Only update closing_hours if it doesn't exist or is null
                if (Schema::hasColumn('branches', 'closing_hours') && empty($branch->closing_hours)) {
                    $updateData['closing_hours'] = null;
                }
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update($updateData);
                return;
            }
            
            // Parse format like "08:00 - 22:00" or "08:00-22:00"
            if (preg_match('/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $openingHours, $matches)) {
                $openingTime = $matches[1];
                $closingTime = $matches[2];
                
                // Ensure time format is HH:MM:SS
                $openingTime = $this->normalizeTime($openingTime);
                $closingTime = $this->normalizeTime($closingTime);
                
                $updateData = ['opening_hours' => $openingTime];
                if (Schema::hasColumn('branches', 'closing_hours')) {
                    $updateData['closing_hours'] = $closingTime;
                }
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update($updateData);
            } else {
                // If format doesn't match, try to extract just the opening time
                // and set closing_hours to null
                $openingTime = $this->normalizeTime($openingHours);
                $updateData = ['opening_hours' => $openingTime];
                
                if (Schema::hasColumn('branches', 'closing_hours')) {
                    $updateData['closing_hours'] = null;
                }
                
                DB::table('branches')
                    ->where('id', $branch->id)
                    ->update($updateData);
            }
        });

        // Step 3: Extract lat/lng from map_coordinates if exists
        // Only update if lat/lng are null or empty
        if (Schema::hasColumn('branches', 'map_coordinates') && 
            Schema::hasColumn('branches', 'lat') && 
            Schema::hasColumn('branches', 'lng')) {
            DB::table('branches')
                ->whereNotNull('map_coordinates')
                ->where(function ($query) {
                    $query->whereNull('lat')
                        ->orWhereNull('lng')
                        ->orWhere('lat', '')
                        ->orWhere('lng', '');
                })
                ->get()
                ->each(function ($branch) {
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
        }

        // Step 4: Now modify the columns to their final types
        Schema::table('branches', function (Blueprint $table) {
            // Change opening_hours from string to time (only if it's still string)
            if (Schema::hasColumn('branches', 'opening_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'opening_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'time') === false) {
                    $table->time('opening_hours')->nullable()->change();
                }
            }
            
            // Change closing_hours from string to time (only if it exists and is string)
            if (Schema::hasColumn('branches', 'closing_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'closing_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'time') === false) {
                    $table->time('closing_hours')->nullable()->change();
                }
            }
        });

        // Step 5: Remove old columns after data migration (only if they exist)
        Schema::table('branches', function (Blueprint $table) {
            $columnsToDrop = [];
            
            if (Schema::hasColumn('branches', 'location')) {
                $columnsToDrop[] = 'location';
            }
            
            if (Schema::hasColumn('branches', 'map_coordinates')) {
                $columnsToDrop[] = 'map_coordinates';
            }
            
            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
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
            ]);
        });
    }
};
