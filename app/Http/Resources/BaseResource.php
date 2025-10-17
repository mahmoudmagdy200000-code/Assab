<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use App\ApiResponse;
use Carbon\Carbon;

abstract class BaseResource extends JsonResource
{
    use ApiResponse;
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * Format timestamps consistently
     */
    protected function formatTimestamps(): array
    {
        return [
            'created_at' => $this->created_at?->format(self::DATETIME_FORMAT),
            'updated_at' => $this->updated_at?->format(self::DATETIME_FORMAT),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Format status consistently
     */
    protected function formatStatus(): array
    {
        $status = $this->status ?? $this->is_active ?? null;
        // $label = $this->status_label ?? $this->getStatusLabel($status);
        // $color = $this->status_color ?? $this->getStatusColor($status);

        return [
            'value' => $status,
            // 'label' => $label,
            // 'color' => $color,
        ];
    }

    /**
     * Get status label based on value
     */
    protected function getStatusLabel($status): string
    {
        return match ($status) {
            'active', true => 'Active',
            'inactive', false => 'Inactive',
            'pending' => 'Pending',
            'deactivated' => 'Deactivated',
            'suspended' => 'Suspended',
            'completed' => 'Completed',
            'in_progress' => 'In Progress',
            'not_started' => 'Not Started',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    /**
     * Get status color based on value
     */
    protected function getStatusColor($status): string
    {
        return match ($status) {
            'active', true => 'green',
            'inactive', false => 'gray',
            'pending' => 'yellow',
            'deactivated' => 'red',
            'suspended' => 'orange',
            'completed' => 'blue',
            'in_progress' => 'purple',
            'not_started' => 'gray',
            'cancelled' => 'red',
            default => 'gray',
        };
    }

    /**
     * Format currency values
     */
    protected function formatCurrency($amount, string $currency = 'SAR'): array
    {
        return [
            'amount' => (float) $amount,
            'formatted' => number_format($amount, 2) . ' ' . $currency,
            'currency' => $currency,
        ];
    }

    /**
     * Format percentage values
     */
    protected function formatPercentage($value, int $decimals = 2): array
    {
        return [
            'value' => (float) $value,
            'formatted' => number_format($value, $decimals) . '%',
            'percentage' => $value,
        ];
    }

    /**
     * Format date values
     */
    protected function formatDate($date, string $format = 'Y-m-d'): ?array
    {
        if (!$date) {
            return null;
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);

        return [
            'date' => $carbon->format($format),
            'datetime' => $carbon->format(self::DATETIME_FORMAT),
            'human' => $carbon->diffForHumans(),
            'timestamp' => $carbon->timestamp,
        ];
    }

    /**
     * Format image URL
     */
    protected function formatImageUrl(?string $imagePath): ?array
    {
        if (!$imagePath) {
            return null;
        }

        return [
            'url' => asset('storage/' . $imagePath),
            'path' => $imagePath,
            'exists' => file_exists(storage_path('app/public/' . $imagePath)),
        ];
    }

    /**
     * Format contact information
     */
    protected function formatContact(array $contact): array
    {
        return [
            'email' => $contact['email'] ?? null,
            'phone' => $contact['phone'] ?? null,
            'address' => $contact['address'] ?? null,
            'website' => $contact['website'] ?? null,
        ];
    }

    /**
     * Format location information
     */
    protected function formatLocation(array $location): array
    {
        return [
            'address' => $location['address'] ?? null,
            'city' => $location['city'] ?? null,
            'country' => $location['country'] ?? null,
            'coordinates' => [
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
            ],
            'map_url' => $this->generateMapUrl($location),
        ];
    }

    /**
     * Generate map URL from coordinates
     */
    protected function generateMapUrl(array $location): ?string
    {
        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;

        if ($lat && $lng) {
            return "https://www.google.com/maps?q={$lat},{$lng}";
        }

        return null;
    }

    /**
     * Format statistics
     */
    protected function formatStatistics(array $stats): array
    {
        return [
            'total' => $stats['total'] ?? 0,
            'active' => $stats['active'] ?? 0,
            'inactive' => $stats['inactive'] ?? 0,
            'percentage' => $this->calculatePercentage($stats),
        ];
    }

    /**
     * Calculate percentage
     */
    protected function calculatePercentage(array $stats): float
    {
        $total = $stats['total'] ?? 0;
        $active = $stats['active'] ?? 0;

        if ($total === 0) {
            return 0;
        }

        return round(($active / $total) * 100, 2);
    }

    /**
     * Format boolean values
     */
    protected function formatBoolean($value): array
    {
        return [
            'value' => (bool) $value,
            'label' => $value ? 'Yes' : 'No',
            'color' => $value ? 'green' : 'red',
        ];
    }

    /**
     * Format array of IDs
     */
    protected function formatIds($items, string $key = 'id'): array
    {
        if (!$items) {
            return [];
        }

        return collect($items)->pluck($key)->toArray();
    }



    protected function formatNestedResource($resource, string $resourceClass = null): ?array
    {
        if (!$resource || $resource instanceof MissingValue) {
            return null;
        }

        if ($resourceClass && class_exists($resourceClass)) {
            return (new $resourceClass($resource))->toArray(request());
        }

        return [
            'id' => $resource->id ?? null,
            'name' => $resource->name ?? $resource->title ?? 'Unknown',
        ];
    }


    /**
     * Format collection of nested resources
     */
    protected function formatNestedCollection($collection, string $resourceClass = null): array
    {
        if (!$collection || $collection->isEmpty()) {
            return [];
        }

        if ($resourceClass && class_exists($resourceClass)) {
            return $resourceClass::collection($collection)->toArray(request());
        }

        return $collection->map(function ($item) {
            return [
                'id' => $item->id,
                'name' => $item->name ?? $item->title ?? 'Unknown',
            ];
        })->toArray();
    }
}
