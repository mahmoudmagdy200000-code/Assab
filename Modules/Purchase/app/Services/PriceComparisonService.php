<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Models\PriceComparison;
use Modules\Purchase\Repositories\PriceComparisonRepository;
use Carbon\Carbon;

class PriceComparisonService
{
    public function __construct(
        private PriceComparisonRepository $priceComparisonRepository
    ) {}

    /**
     * Compare prices for multiple items across sources
     */
    public function compareItemPrices(array $items, string $branchId): array
    {
        $comparisons = [];

        foreach ($items as $itemData) {
            $itemId = $itemData['item_id'];
            $quantity = $itemData['quantity'];

            // Get prices from different sources
            $directSupplier = $this->priceComparisonRepository->getLatestPrice(
                $itemId,
                $branchId,
                'direct_supplier'
            );

            $purchasingOfficer = $this->priceComparisonRepository->getLatestPrice(
                $itemId,
                $branchId,
                'purchasing_officer'
            );

            $internalTransfer = $this->priceComparisonRepository->getLatestPrice(
                $itemId,
                $branchId,
                'internal_transfer'
            );

            $sources = [];

            if ($directSupplier) {
                $sources[] = [
                    'type' => 'direct_supplier',
                    'price' => $directSupplier->price,
                    'total' => $directSupplier->price * $quantity,
                    'delivery_days' => $directSupplier->delivery_days,
                    'rating' => $directSupplier->rating,
                    'supplier' => $directSupplier->supplier,
                ];
            }

            if ($purchasingOfficer) {
                $sources[] = [
                    'type' => 'purchasing_officer',
                    'price' => $purchasingOfficer->price,
                    'total' => $purchasingOfficer->price * $quantity,
                    'delivery_days' => $purchasingOfficer->delivery_days,
                    'rating' => $purchasingOfficer->rating,
                ];
            }

            if ($internalTransfer) {
                $sources[] = [
                    'type' => 'internal_transfer',
                    'price' => 0, // No cost for internal transfer
                    'total' => 0,
                    'delivery_days' => $internalTransfer->delivery_days,
                    'rating' => $internalTransfer->rating,
                    'branch' => $internalTransfer->transferFromBranch,
                ];
            }

            // Determine best option
            $bestOption = $this->determineBestOption($sources);

            $comparisons[] = [
                'item_id' => $itemId,
                'quantity' => $quantity,
                'sources' => $sources,
                'best_option' => $bestOption,
                'insights' => $this->generateInsights($sources),
            ];
        }

        return $comparisons;
    }

    /**
     * Get price trends for last 3 months
     */
    public function getPriceTrends(string $itemId, string $branchId): array
    {
        $threeMonthsAgo = Carbon::now()->subMonths(3);

        $trends = $this->priceComparisonRepository->getPriceHistory(
            $itemId,
            $branchId,
            $threeMonthsAgo
        );

        // Group by month and order type
        $monthlyTrends = [];

        foreach ($trends as $trend) {
            $month = Carbon::parse($trend->recorded_date)->format('Y-m');

            if (!isset($monthlyTrends[$month])) {
                $monthlyTrends[$month] = [];
            }

            $monthlyTrends[$month][$trend->order_type] = [
                'price' => $trend->price,
                'delivery_days' => $trend->delivery_days,
                'rating' => $trend->rating,
            ];
        }

        return [
            'item_id' => $itemId,
            'period' => 'last_3_months',
            'trends' => $monthlyTrends,
        ];
    }

    /**
     * Determine best option based on price and rating
     */
    private function determineBestOption(array $sources): ?array
    {
        if (empty($sources)) {
            return null;
        }

        usort($sources, function ($a, $b) {
            // Calculate score: lower price and higher rating is better
            $scoreA = ($a['total'] * 0.7) - ($a['rating'] * 100);
            $scoreB = ($b['total'] * 0.7) - ($b['rating'] * 100);

            return $scoreA <=> $scoreB;
        });

        return $sources[0];
    }

    /**
     * Generate insights
     */
    private function generateInsights(array $sources): array
    {
        if (empty($sources)) {
            return [];
        }

        $insights = [];

        // Best compliance (highest rating)
        $bestCompliance = collect($sources)->sortByDesc('rating')->first();
        $insights['best_compliance'] = [
            'type' => $bestCompliance['type'],
            'rating' => $bestCompliance['rating'],
        ];

        // Fastest delivery
        $fastestDelivery = collect($sources)->sortBy('delivery_days')->first();
        $insights['fastest_delivery'] = [
            'type' => $fastestDelivery['type'],
            'days' => $fastestDelivery['delivery_days'],
        ];

        // Lowest price
        $lowestPrice = collect($sources)->sortBy('total')->first();
        $insights['lowest_price'] = [
            'type' => $lowestPrice['type'],
            'price' => $lowestPrice['total'],
        ];

        return $insights;
    }
}
