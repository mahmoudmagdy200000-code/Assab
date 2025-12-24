<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Migrate data from purchase_suppliers table to suppliers table
     */
    public function up(): void
    {
        if (!Schema::hasTable('purchase_suppliers') || !Schema::hasTable('suppliers')) {
            return;
        }

        // Migrate purchase_suppliers data to suppliers table
        $purchaseSuppliers = DB::table('purchase_suppliers')->get();

        foreach ($purchaseSuppliers as $purchaseSupplier) {
            // Check if supplier already exists by email or phone
            $existingSupplier = DB::table('suppliers')
                ->where(function ($query) use ($purchaseSupplier) {
                    if ($purchaseSupplier->email) {
                        $query->where('email', $purchaseSupplier->email);
                    }
                    if ($purchaseSupplier->phone) {
                        $query->orWhere('phone', $purchaseSupplier->phone);
                    }
                })
                ->first();

            if (!$existingSupplier) {
                // Create new supplier record
                DB::table('suppliers')->insert([
                    'id' => $purchaseSupplier->id,
                    'name' => $purchaseSupplier->name,
                    'email' => $purchaseSupplier->email,
                    'phone' => $purchaseSupplier->phone,
                    'image' => $purchaseSupplier->image,
                    'address' => $purchaseSupplier->address,
                    'tax_id' => $purchaseSupplier->tax_id,
                    'password' => bcrypt('default_password_' . $purchaseSupplier->id), // Temporary password
                    'is_active' => $purchaseSupplier->is_active ?? true,
                    'is_first_login' => true,
                    'status' => $purchaseSupplier->status ?? 'offline',
                    'contact_methods' => $purchaseSupplier->contact_methods,
                    'default_delivery_hours' => $purchaseSupplier->default_delivery_hours,
                    'min_order_amount' => $purchaseSupplier->min_order_amount,
                    'average_response_time_hours' => $purchaseSupplier->average_response_time_hours,
                    'response_rate_percentage' => $purchaseSupplier->response_rate_percentage,
                    'rating' => $purchaseSupplier->rating,
                    'total_orders' => $purchaseSupplier->total_orders ?? 0,
                    'completed_orders' => $purchaseSupplier->completed_orders ?? 0,
                    'categories' => $purchaseSupplier->categories,
                    'last_seen_at' => $purchaseSupplier->last_seen_at,
                    'created_by_admin_at' => $purchaseSupplier->created_at,
                    'created_at' => $purchaseSupplier->created_at,
                    'updated_at' => $purchaseSupplier->updated_at,
                    'deleted_at' => $purchaseSupplier->deleted_at ?? null,
                ]);
            }
        }

        // Update purchase_orders to reference new suppliers table
        // This will be handled in the foreign key update migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: This migration should not be reversed as it consolidates data
        // If needed, data should be manually restored from backups
    }
};
