<?php

namespace Modules\Expense\Services;

use Modules\Expense\Models\{Category, Supplier};

/**
 * Expense Helper Service
 * Common functionality across all expense types
 */
class ExpenseHelperService
{
    /**
     * Get all categories
     */
    public function getCategories(?string $search = null)
    {
        $query = Category::with('parent')
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get()->map(function ($category) {
            return [
                'id' => $category->id,
                'name' => $category->name,
                'parent' => $category->parent ? [
                    'id' => $category->parent->id,
                    'name' => $category->parent->name,
                ] : null,
                'type' => $category->type, // 'purchase' or 'expense'
            ];
        });
    }

    /**
     * Get all suppliers
     */
    public function getSuppliers(?string $search = null)
    {
        $query = Supplier::where('is_active', true)
            ->orderBy('name');

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->get()->map(function ($supplier) {
            return [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
            ];
        });
    }

    /**
     * Parse QR Code (Saudi ZATCA format)
     */
    public function parseQRCode(string $qrCode): array
    {
        // TODO: Implement actual ZATCA QR code parsing
        // This is a placeholder

        // ZATCA format: TLV (Tag-Length-Value)
        // Tag 1: Seller name
        // Tag 2: VAT registration number
        // Tag 3: Timestamp
        // Tag 4: Total with VAT
        // Tag 5: VAT amount

        return [
            'seller_name' => 'Sample Supplier',
            'tax_id' => '300000000000003',
            'timestamp' => now()->toIso8601String(),
            'total_amount' => 1150.00,
            'vat_amount' => 150.00,
            'net_amount' => 1000.00,
            'invoice_number' => 'INV-' . rand(1000, 9999),
        ];
    }

    /**
     * Scan invoice code (alternative to QR)
     */
    public function scanInvoiceCode(string $code): array
    {
        // Similar to QR code parsing
        return $this->parseQRCode($code);
    }


    
}
