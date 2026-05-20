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
        if (Schema::hasTable('suppliers')) {
            // Table already exists (likely from Expense module), add missing columns
            Schema::table('suppliers', function (Blueprint $table) {
                // Add password column if it doesn't exist
                // Make it nullable initially since existing records won't have passwords
                // The migrate_expense_suppliers_data migration will set default passwords
                if (! Schema::hasColumn('suppliers', 'password')) {
                    $table->string('password')->nullable()->after('tax_id');
                }

                // Add other missing columns from Supplier module structure
                if (! Schema::hasColumn('suppliers', 'is_first_login')) {
                    $table->boolean('is_first_login')->default(true)->after('is_active');
                }
                if (! Schema::hasColumn('suppliers', 'email_verified_at')) {
                    $table->timestamp('email_verified_at')->nullable()->after('is_first_login');
                }
                if (! Schema::hasColumn('suppliers', 'phone_verified_at')) {
                    $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
                }
                if (! Schema::hasColumn('suppliers', 'remember_token')) {
                    $table->rememberToken();
                }
                if (! Schema::hasColumn('suppliers', 'company_name')) {
                    $table->string('company_name')->nullable()->after('remember_token');
                }
                if (! Schema::hasColumn('suppliers', 'service_areas')) {
                    $table->json('service_areas')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'working_hours')) {
                    $table->json('working_hours')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'holiday_schedules')) {
                    $table->json('holiday_schedules')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'language')) {
                    $table->enum('language', ['ar', 'en'])->default('ar');
                }
                if (! Schema::hasColumn('suppliers', 'theme')) {
                    $table->enum('theme', ['light', 'dark'])->default('light');
                }
                if (! Schema::hasColumn('suppliers', 'notification_preferences')) {
                    $table->json('notification_preferences')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'status')) {
                    $table->enum('status', ['online', 'offline', 'away'])->default('offline');
                }
                if (! Schema::hasColumn('suppliers', 'contact_methods')) {
                    $table->json('contact_methods')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'default_delivery_hours')) {
                    $table->integer('default_delivery_hours')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'min_order_amount')) {
                    $table->decimal('min_order_amount', 12, 2)->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'average_response_time_hours')) {
                    $table->decimal('average_response_time_hours', 8, 2)->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'response_rate_percentage')) {
                    $table->decimal('response_rate_percentage', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'rating')) {
                    $table->decimal('rating', 3, 2)->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'total_orders')) {
                    $table->integer('total_orders')->default(0);
                }
                if (! Schema::hasColumn('suppliers', 'completed_orders')) {
                    $table->integer('completed_orders')->default(0);
                }
                if (! Schema::hasColumn('suppliers', 'categories')) {
                    $table->json('categories')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'created_by_admin_at')) {
                    $table->timestamp('created_by_admin_at')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'last_seen_at')) {
                    $table->timestamp('last_seen_at')->nullable();
                }
                if (! Schema::hasColumn('suppliers', 'deleted_at')) {
                    $table->softDeletes();
                }

                // Add indexes if they don't exist
                // Note: Laravel doesn't provide a direct way to check if index exists
                // We'll add them and ignore errors if they already exist
                try {
                    $table->index('email');
                } catch (\Exception $e) {
                    // Index might already exist
                }
                try {
                    $table->index('phone');
                } catch (\Exception $e) {
                    // Index might already exist
                }
                try {
                    $table->index('status');
                } catch (\Exception $e) {
                    // Index might already exist
                }
                try {
                    $table->index('is_first_login');
                } catch (\Exception $e) {
                    // Index might already exist
                }
                try {
                    $table->index('rating');
                } catch (\Exception $e) {
                    // Index might already exist
                }
                try {
                    $table->index('created_at');
                } catch (\Exception $e) {
                    // Index might already exist
                }
            });

            return;
        }

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Basic Information
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique()->nullable();
            $table->string('image')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_id')->nullable();

            // Account Information
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_first_login')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();

            // Company/Business Information
            $table->string('company_name')->nullable();
            $table->json('service_areas')->nullable(); // Array of area IDs or names
            $table->json('working_hours')->nullable(); // Working hours configuration
            $table->json('holiday_schedules')->nullable(); // Holiday dates

            // Settings
            $table->enum('language', ['ar', 'en'])->default('ar');
            $table->enum('theme', ['light', 'dark'])->default('light');
            $table->json('notification_preferences')->nullable(); // Notification channel preferences

            // Status and Availability
            $table->enum('status', ['online', 'offline', 'away'])->default('offline');
            $table->json('contact_methods')->nullable(); // ['email', 'whatsapp', 'app', 'sms']

            // Delivery Information
            $table->integer('default_delivery_hours')->nullable();
            $table->decimal('min_order_amount', 12, 2)->nullable();

            // Performance Metrics
            $table->decimal('average_response_time_hours', 8, 2)->nullable();
            $table->decimal('response_rate_percentage', 5, 2)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('total_orders')->default(0);
            $table->integer('completed_orders')->default(0);

            // Categories
            $table->json('categories')->nullable(); // Category IDs this supplier serves

            // Timestamps
            $table->timestamp('created_by_admin_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('email');
            $table->index('phone');
            $table->index('status');
            $table->index('is_active');
            $table->index('is_first_login');
            $table->index('rating');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
