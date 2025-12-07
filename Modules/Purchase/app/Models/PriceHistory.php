<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;

class PriceHistory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'item_id',
        'item_name',
        'source_type',
        'source_id',
        'source_name',
        'unit_price',
        'quality_level',
        'unit_of_measurement',
        'delivery_days',
        'rating',
        'recorded_date',
        'period_month',
    ];

    protected $casts = [
        'source_type' => OrderType::class,
        'quality_level' => QualityLevel::class,
        'unit_price' => 'decimal:2',
        'delivery_days' => 'integer',
        'rating' => 'decimal:2',
        'recorded_date' => 'date',
    ];

    // Scopes
    public function scopeByItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeBySourceType($query, OrderType $type)
    {
        return $query->where('source_type', $type);
    }

    public function scopeBySource($query, string $sourceId)
    {
        return $query->where('source_id', $sourceId);
    }

    public function scopeByPeriod($query, string $periodMonth)
    {
        return $query->where('period_month', $periodMonth);
    }

    public function scopeLastThreeMonths($query)
    {
        $threeMonthsAgo = now()->subMonths(3)->startOfMonth();
        return $query->where('recorded_date', '>=', $threeMonthsAgo);
    }

    public function scopeByQuality($query, QualityLevel $quality)
    {
        return $query->where('quality_level', $quality);
    }

    // Static Methods
    public static function recordPrice(
        string $itemId,
        string $itemName,
        OrderType $sourceType,
        ?string $sourceId,
        ?string $sourceName,
        float $unitPrice,
        ?QualityLevel $qualityLevel = null,
        string $unit = 'kg',
        ?int $deliveryDays = null,
        ?float $rating = null
    ): self {
        return static::create([
            'item_id' => $itemId,
            'item_name' => $itemName,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_name' => $sourceName,
            'unit_price' => $unitPrice,
            'quality_level' => $qualityLevel,
            'unit_of_measurement' => $unit,
            'delivery_days' => $deliveryDays,
            'rating' => $rating,
            'recorded_date' => now(),
            'period_month' => now()->format('Y-m'),
        ]);
    }

    /**
     * Get price comparison for an item across all sources
     */
    public static function getPriceComparison(string $itemId): array
    {
        $prices = static::byItem($itemId)
            ->lastThreeMonths()
            ->select('source_type', 'source_id', 'source_name', 'unit_price', 'delivery_days', 'rating', 'period_month')
            ->orderBy('period_month')
            ->get()
            ->groupBy('source_type');
        
        $comparison = [];
        
        foreach (OrderType::cases() as $type) {
            if ($type->hasCost()) {
                $sourceData = $prices->get($type->value, collect());
                
                if ($sourceData->isNotEmpty()) {
                    $latestPrice = $sourceData->sortByDesc('period_month')->first();
                    
                    $comparison[$type->value] = [
                        'type' => $type->value,
                        'type_label' => $type->label(),
                        'current_price' => $latestPrice->unit_price,
                        'delivery_days' => $latestPrice->delivery_days,
                        'rating' => $latestPrice->rating,
                        'price_history' => $sourceData->groupBy('period_month')->map(function ($items) {
                            return $items->avg('unit_price');
                        })->toArray(),
                    ];
                }
            }
        }
        
        return $comparison;
    }

    /**
     * Determine best option based on price, rating, and delivery
     */
    public static function getBestOption(string $itemId): ?array
    {
        $comparison = static::getPriceComparison($itemId);
        
        if (empty($comparison)) {
            return null;
        }
        
        // Calculate score for each option (lower is better for price and delivery, higher is better for rating)
        $scored = collect($comparison)->map(function ($option) {
            $priceScore = $option['current_price'];
            $deliveryScore = $option['delivery_days'] ?? PHP_INT_MAX;
            $ratingScore = 100 - (($option['rating'] ?? 0) * 20); // Convert 5-star to score (0-100)
            
            return array_merge($option, [
                'composite_score' => ($priceScore * 0.5) + ($deliveryScore * 10) + $ratingScore,
                'is_lowest_price' => false,
                'is_fastest_delivery' => false,
                'is_best_rating' => false,
            ]);
        });
        
        // Find best in each category
        $lowestPrice = $scored->sortBy('current_price')->first();
        $fastestDelivery = $scored->sortBy('delivery_days')->first();
        $bestRating = $scored->sortByDesc('rating')->first();
        $bestOverall = $scored->sortBy('composite_score')->first();
        
        return [
            'best_option' => $bestOverall['type'],
            'lowest_price' => $lowestPrice['type'],
            'fastest_delivery' => $fastestDelivery['type'],
            'best_rating' => $bestRating['type'],
            'comparison' => $comparison,
        ];
    }
}

