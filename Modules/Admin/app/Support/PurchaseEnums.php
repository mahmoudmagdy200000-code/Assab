<?php

namespace Modules\Admin\Support;

/**
 * SRS ACC-3 — the purchases screen vocabulary: order source, per-line match
 * state and the return-order status labels.
 *
 * The «3-way match» is modelled with two legs live today (ordered vs received
 * quantity, ordered vs invoiced unit price) and a third — the supplier invoice
 * quantity — reserved: each line already carries `orderedUnitPriceHalalas`
 * beside `unitPriceHalalas`, so the invoice leg slots in without reshaping the
 * payload. See PurchasePresenterService.
 */
final class PurchaseEnums
{
    /** Where the order entered the pipeline (ACC-3, 3 sources). */
    public const ORDER_SOURCE = [
        'supplier' => 'مورد',
        'branch' => 'فرع آخر',
        'procurement' => 'مدير المشتريات',
    ];

    /**
     * Per-line match after the 3 legs are compared:
     *  - `matched`  quantity received == ordered AND invoice price == ordered price
     *  - `diff`     a confirmed disagreement on quantity or price
     *  - `pending`  not yet received — cannot be verified
     */
    public const LINE_MATCH = [
        'matched' => ['labelAr' => 'مطابق', 'icon' => '✅'],
        'diff' => ['labelAr' => 'فرق', 'icon' => '⚠️'],
        'pending' => ['labelAr' => 'بانتظار الاستلام', 'icon' => '⏳'],
    ];

    /** Legacy mobile `return_orders.status` → Arabic (T06.9). */
    public const RETURN_STATUS = [
        'draft' => 'مسودة',
        'pending' => 'قيد المراجعة',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
        'escalated' => 'مُصعّد',
        'escalated_resolved' => 'حُلّ التصعيد',
        'escalated_rejected' => 'رُفض التصعيد',
        'closed' => 'مُغلق',
        'resolved' => 'مُسوّى',
    ];

    /** Legacy `purchase_orders.order_type` → the 3 ACC-3 sources. */
    public const ORDER_TYPE_TO_SOURCE = [
        'direct_supplier' => 'supplier',
        'internal_transfer' => 'branch',
        'transfer_received' => 'branch',
        'via_purchasing_officer' => 'procurement',
        'multiple_sources' => 'procurement',
    ];

    public static function orderSource(?string $key): array
    {
        $key = $key !== null && isset(self::ORDER_SOURCE[$key]) ? $key : 'procurement';

        return ['key' => $key, 'labelAr' => self::ORDER_SOURCE[$key]];
    }

    public static function lineMatch(string $key): array
    {
        return ['key' => $key] + (self::LINE_MATCH[$key] ?? self::LINE_MATCH['pending']);
    }

    public static function returnStatusLabelAr(?string $key): ?string
    {
        return $key === null ? null : (self::RETURN_STATUS[$key] ?? $key);
    }

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        $labelled = fn (array $map) => array_map(
            fn ($key, $labelAr) => ['key' => $key, 'labelAr' => $labelAr],
            array_keys($map),
            array_values($map),
        );

        return [
            'orderSource' => $labelled(self::ORDER_SOURCE),
            'lineMatch' => array_map(
                fn ($key, $meta) => ['key' => $key] + $meta,
                array_keys(self::LINE_MATCH),
                array_values(self::LINE_MATCH),
            ),
            'returnStatus' => $labelled(self::RETURN_STATUS),
        ];
    }
}
