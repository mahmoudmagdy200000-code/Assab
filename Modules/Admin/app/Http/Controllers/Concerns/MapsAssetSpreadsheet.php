<?php

namespace Modules\Admin\Http\Controllers\Concerns;

/**
 * Header-driven reading of an asset spreadsheet, shared by the accountant
 * register import and the admin fixed-assets upload.
 *
 * Both importers face the same two client layouts (an 8-column Arabic template
 * and an 11-column English one) in arbitrary column order, so columns are
 * resolved by header name rather than by position.
 */
trait MapsAssetSpreadsheet
{
    /**
     * Build a column-index → field map from a header row (Arabic/English tolerant).
     *
     * «اسم الفرع» is deliberately absent from `branchId`: the Arabic template
     * carries a branch *name* there, while `branchId` is matched against real
     * branch ids.
     *
     * @return array<string, int>
     */
    private function mapAssetHeaders(array $headers): array
    {
        $aliases = [
            'name' => ['name', 'asset', 'asset name', 'الاسم', 'اسم', 'الأصل', 'اسم الأصل'],
            'category' => ['category', 'asset category', 'asset category (type)', 'الفئة', 'التصنيف', 'النوع'],
            'cost' => ['cost', 'value', 'price', 'amount', 'purchase value', 'القيمة', 'التكلفة', 'التكلفة (ر.س)', 'السعر', 'المبلغ'],
            // «اسم الفرع» ships in the ratified Arabic template — it was mapped
            // nowhere, so a brand-level upload could never place a row in a
            // branch and the assets stayed invisible to the app (2026-08-03).
            'branchId' => ['branchid', 'branch', 'branch name', 'الفرع', 'فرع', 'اسم الفرع'],
            'usefulLife' => ['usefullife', 'useful_life_months', 'life', 'العمر', 'العمر الإنتاجي', 'العمر الانتاجي', 'العمر الافتراضي (شهر)'],
            'serial' => ['serial', 'serialnumber', 'الرقم التسلسلي', 'السيريال'],
            'zone' => ['zone', 'المنطقة', 'النطاق'],
            'quantity' => ['quantity', 'totalquantity', 'الكمية', 'الكمية الإجمالية'],
            'excellent' => ['excellent', 'ممتاز'],
            'maintenance' => ['maintenance', 'صيانة'],
            'problem' => ['problem', 'مشكلة', 'عطل'],
            'purchasedAt' => ['purchasedate', 'تاريخ الشراء'],
            'invNum' => ['invoice', 'invoicenumber', 'invnum', 'رقم الفاتورة'],
            'custodian' => ['custodian', 'أمين العهدة'],
            'notes' => ['notes', 'ملاحظات'],
        ];
        $map = [];
        foreach ($headers as $idx => $h) {
            $norm = mb_strtolower(trim((string) $h));
            foreach ($aliases as $field => $names) {
                if (in_array($norm, $names, true) || in_array(str_replace(' ', '', $norm), $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    private function cell(array $cells, array $map, string $field): mixed
    {
        return isset($map[$field]) ? ($cells[$map[$field]] ?? null) : null;
    }

    /** A spreadsheet money value (SAR, possibly decimal) → integer halalas. */
    private function toHalalas(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        $n = (float) preg_replace('/[^0-9.\-]/', '', (string) $v);

        return (int) round($n * 100);
    }
}
