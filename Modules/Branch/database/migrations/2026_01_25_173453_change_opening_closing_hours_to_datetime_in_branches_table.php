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
        // Step 1: Convert existing time values to datetime (using today's date)
        if (Schema::hasColumn('branches', 'opening_hours') || Schema::hasColumn('branches', 'closing_hours')) {
            DB::table('branches')->where(function ($query) {
                $query->whereNotNull('opening_hours')
                    ->orWhereNotNull('closing_hours');
            })->get()->each(function ($branch) {
                $updates = [];
                
                // Convert opening_hours from time to datetime
                if ($branch->opening_hours) {
                    $time = $branch->opening_hours;
                    // Normalize time format
                    if (preg_match('/^(\d{2}):(\d{2})(:(\d{2}))?$/', $time, $matches)) {
                        $hours = $matches[1];
                        $minutes = $matches[2];
                        $seconds = $matches[4] ?? '00';
                        // Use today's date with the time
                        $datetime = now()->setTime((int)$hours, (int)$minutes, (int)$seconds)->format('Y-m-d H:i:s');
                        $updates['opening_hours'] = $datetime;
                    }
                }
                
                // Convert closing_hours from time to datetime
                if ($branch->closing_hours) {
                    $time = $branch->closing_hours;
                    // Normalize time format
                    if (preg_match('/^(\d{2}):(\d{2})(:(\d{2}))?$/', $time, $matches)) {
                        $hours = $matches[1];
                        $minutes = $matches[2];
                        $seconds = $matches[4] ?? '00';
                        // Use today's date with the time
                        $datetime = now()->setTime((int)$hours, (int)$minutes, (int)$seconds)->format('Y-m-d H:i:s');
                        $updates['closing_hours'] = $datetime;
                    }
                }
                
                if (!empty($updates)) {
                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update($updates);
                }
            });
        }

        // Step 2: Change column types from time to datetime
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'opening_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'opening_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'time') !== false && strpos($columnType[0]->Type, 'datetime') === false) {
                    $table->datetime('opening_hours')->nullable()->change();
                }
            }
            
            if (Schema::hasColumn('branches', 'closing_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'closing_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'time') !== false && strpos($columnType[0]->Type, 'datetime') === false) {
                    $table->datetime('closing_hours')->nullable()->change();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Convert datetime back to time (extract only time part)
        if (Schema::hasColumn('branches', 'opening_hours') || Schema::hasColumn('branches', 'closing_hours')) {
            DB::table('branches')->where(function ($query) {
                $query->whereNotNull('opening_hours')
                    ->orWhereNotNull('closing_hours');
            })->get()->each(function ($branch) {
                $updates = [];
                
                // Convert opening_hours from datetime to time
                if ($branch->opening_hours) {
                    try {
                        $datetime = \Carbon\Carbon::parse($branch->opening_hours);
                        $updates['opening_hours'] = $datetime->format('H:i:s');
                    } catch (\Exception $e) {
                        // If parsing fails, keep as is
                    }
                }
                
                // Convert closing_hours from datetime to time
                if ($branch->closing_hours) {
                    try {
                        $datetime = \Carbon\Carbon::parse($branch->closing_hours);
                        $updates['closing_hours'] = $datetime->format('H:i:s');
                    } catch (\Exception $e) {
                        // If parsing fails, keep as is
                    }
                }
                
                if (!empty($updates)) {
                    DB::table('branches')
                        ->where('id', $branch->id)
                        ->update($updates);
                }
            });
        }

        // Step 2: Change column types back from datetime to time
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'opening_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'opening_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'datetime') !== false) {
                    $table->time('opening_hours')->nullable()->change();
                }
            }
            
            if (Schema::hasColumn('branches', 'closing_hours')) {
                $columnType = DB::select("SHOW COLUMNS FROM branches WHERE Field = 'closing_hours'");
                if (!empty($columnType) && strpos($columnType[0]->Type, 'datetime') !== false) {
                    $table->time('closing_hours')->nullable()->change();
                }
            }
        });
    }
};
