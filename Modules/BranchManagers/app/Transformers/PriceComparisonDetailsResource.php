<?php

namespace Modules\BranchManagers\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full saved price-comparison details for the branch-manager screen.
 *
 * Maps the raw comparePrices() snapshot stored on SavedPriceComparison into
 * the camelCase contract consumed by BranchManagerPriceComparisonRepositoryImpl
 * (sources, computed factors and price trends).
 *
 * @mixin \Modules\Purchase\Models\SavedPriceComparison
 */
class PriceComparisonDetailsResource extends JsonResource
{
    public function toArray($request): array
    {
        $s = is_array($this->snapshot) ? $this->snapshot : [];
        $sources = $s['sources'] ?? [];
        $insights = $s['insights'] ?? [];

        return [
            'comparisonId' => $this->id,
            'itemId' => $s['item_id'] ?? $this->item_id,
            'itemName' => $s['item_name'] ?? $this->item_name,
            'itemCode' => $s['item_code'] ?? null,
            'itemUnit' => $s['item_unit'] ?? null,
            'itemLogo' => $s['item_logo'] ?? null,
            'itemPrice' => $this->numeric($s['item_price'] ?? null),
            'quantity' => $this->numeric($s['quantity'] ?? $this->quantity),
            'sources' => [
                'directSupplier' => $this->mapSource($this->bestOf($sources['direct_supplier'] ?? []), 'supplier'),
                'viaPurchasingOfficer' => $this->mapSource($this->single($sources['via_purchasing_officer'] ?? null), 'officer'),
                'internalTransfer' => $this->mapSource($this->bestOf($sources['internal_transfer'] ?? []), 'branch'),
            ],
            'factors' => [
                'bestCompliance' => $this->mapFactor($insights['best_compliance'] ?? null),
                'fastestDelivery' => $this->mapFactor($insights['fastest_delivery'] ?? null),
                'lowestPrice' => $this->mapFactor($insights['lowest_price'] ?? null),
            ],
            'priceTrends' => $this->mapTrends($s['price_trends'] ?? []),
        ];
    }

    /**
     * Pick the cheapest row from a list of source options.
     */
    private function bestOf($list): ?array
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        return collect($list)
            ->filter(fn ($row) => is_array($row))
            ->sortBy(fn ($row) => $row['unit_price'] ?? $row['price'] ?? PHP_FLOAT_MAX)
            ->first();
    }

    /**
     * Resolve a single-object source (purchasing officer) — tolerates the
     * value being either an associative array or a list of options.
     */
    private function single($source): ?array
    {
        if (! is_array($source) || $source === []) {
            return null;
        }

        return array_is_list($source) ? $this->bestOf($source) : $source;
    }

    /**
     * Map one source row into the {price, deliveryDays, rating, sourceId} shape.
     */
    private function mapSource(?array $row, string $kind): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'price' => $this->numeric($row['unit_price'] ?? $row['price'] ?? null),
            'deliveryDays' => $this->deliveryLabel($row['delivery_days'] ?? null),
            'rating' => $this->numeric($row['rating'] ?? null),
            'sourceId' => match ($kind) {
                'supplier' => $row['supplier_id'] ?? null,
                'branch' => $row['branch_id'] ?? null,
                default => $row['source_id'] ?? null,
            },
        ];
    }

    /**
     * Map a computed insight into the {sourceType, sourceId, sourceName, score}
     * factor shape.
     */
    private function mapFactor($factor): ?array
    {
        if (! is_array($factor) || $factor === []) {
            return null;
        }

        return [
            'sourceType' => $factor['source_type'] ?? $factor['type'] ?? null,
            'sourceId' => $factor['source_id'] ?? null,
            'sourceName' => $factor['source_name'] ?? null,
            'score' => $this->numeric($factor['value'] ?? null),
        ];
    }

    /**
     * Normalise the price-trend series to [{date, value}].
     */
    private function mapTrends($trends): array
    {
        if (! is_array($trends)) {
            return [];
        }

        return collect($trends)
            ->filter(fn ($t) => is_array($t) && isset($t['date']))
            ->map(fn ($t) => [
                'date' => $t['date'],
                'value' => $this->numeric($t['value'] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * Present a delivery duration as a human label (e.g. "6 days").
     * A value that is already a string (e.g. "5-7 days") is passed through.
     */
    private function deliveryLabel($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ! is_numeric($value)) {
            return $value;
        }

        $number = (float) $value;
        $rendered = fmod($number, 1.0) === 0.0 ? (string) (int) $number : (string) $number;

        return $rendered.' '.($number === 1.0 ? 'day' : 'days');
    }

    private function numeric($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }
}
