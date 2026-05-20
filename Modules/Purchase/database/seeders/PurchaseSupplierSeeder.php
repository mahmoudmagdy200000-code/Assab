<?php

namespace Modules\Purchase\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Purchase\Models\PurchaseSupplier;

class PurchaseSupplierSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'Fresh Foods Trading Co.',
                'email' => 'orders@freshfoods.com',
                'phone' => '+966501234567',
                'address' => 'Industrial Area, Riyadh, Saudi Arabia',
                'tax_id' => '310123456789012',
                'status' => 'online',
                'is_active' => true,
                'contact_methods' => ['email', 'whatsapp', 'app'],
                'default_delivery_hours' => 24,
                'min_order_amount' => 500.00,
                'average_response_time_hours' => 2.5,
                'response_rate_percentage' => 95.5,
                'rating' => 4.8,
                'total_orders' => 150,
                'completed_orders' => 145,
                'categories' => ['fruits', 'vegetables', 'dairy'],
            ],
            [
                'name' => 'Al Marai Supplies',
                'email' => 'supply@almarai.com',
                'phone' => '+966502345678',
                'address' => 'Jeddah, Saudi Arabia',
                'tax_id' => '310234567890123',
                'status' => 'online',
                'is_active' => true,
                'contact_methods' => ['email', 'app', 'sms'],
                'default_delivery_hours' => 48,
                'min_order_amount' => 1000.00,
                'average_response_time_hours' => 1.5,
                'response_rate_percentage' => 98.0,
                'rating' => 4.9,
                'total_orders' => 200,
                'completed_orders' => 198,
                'categories' => ['dairy', 'beverages'],
            ],
            [
                'name' => 'Gulf Meat Supplies',
                'email' => 'orders@gulfmeat.com',
                'phone' => '+966503456789',
                'address' => 'Dammam, Saudi Arabia',
                'tax_id' => '310345678901234',
                'status' => 'away',
                'is_active' => true,
                'contact_methods' => ['whatsapp', 'sms'],
                'default_delivery_hours' => 36,
                'min_order_amount' => 750.00,
                'average_response_time_hours' => 4.0,
                'response_rate_percentage' => 88.5,
                'rating' => 4.2,
                'total_orders' => 80,
                'completed_orders' => 72,
                'categories' => ['meat', 'poultry'],
            ],
            [
                'name' => 'Bakery Ingredients Ltd.',
                'email' => 'contact@bakeryingredients.sa',
                'phone' => '+966504567890',
                'address' => 'Riyadh, Saudi Arabia',
                'tax_id' => '310456789012345',
                'status' => 'offline',
                'is_active' => true,
                'contact_methods' => ['email'],
                'default_delivery_hours' => 72,
                'min_order_amount' => 300.00,
                'average_response_time_hours' => 8.0,
                'response_rate_percentage' => 75.0,
                'rating' => 3.8,
                'total_orders' => 45,
                'completed_orders' => 38,
                'categories' => ['bakery', 'ingredients'],
            ],
            [
                'name' => 'Premium Seafood Co.',
                'email' => 'sales@premiumseafood.com',
                'phone' => '+966505678901',
                'address' => 'Al Khobar, Saudi Arabia',
                'tax_id' => '310567890123456',
                'status' => 'online',
                'is_active' => true,
                'contact_methods' => ['email', 'whatsapp', 'app', 'sms'],
                'default_delivery_hours' => 12,
                'min_order_amount' => 2000.00,
                'average_response_time_hours' => 0.5,
                'response_rate_percentage' => 99.0,
                'rating' => 5.0,
                'total_orders' => 300,
                'completed_orders' => 299,
                'categories' => ['seafood', 'fish'],
            ],
        ];

        foreach ($suppliers as $supplier) {
            PurchaseSupplier::create(array_merge($supplier, [
                'id' => Str::uuid(),
                'last_seen_at' => $supplier['status'] === 'online' ? now() : now()->subHours(rand(1, 48)),
            ]));
        }
    }
}
