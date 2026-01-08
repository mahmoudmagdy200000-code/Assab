<?php

namespace Modules\Purchase\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseVariance;

class SupplierCommunicationService
{
    public function __construct(
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Track supplier contact
     */
    public function trackContact(
        PurchaseOrder $order,
        string $channel,
        ?string $message = null
    ): void {
        DB::transaction(function () use ($order, $channel, $message) {
            // Log the contact in timeline
            $this->timelineService->logSupplierContact($order, $channel, $message);

            // Store contact record (if you have a contacts table)
            // For now, we'll just use the timeline
        });
    }

    /**
     * Get contact methods used for an order
     */
    public function getContactMethods(string $orderId): array
    {
        $order = PurchaseOrder::with('timelines')->find($orderId);
        
        if (!$order) {
            return [];
        }

        $contacts = $order->timelines()
            ->where('event_type', 'order_viewed')
            ->whereNotNull('metadata->channel')
            ->get();

        return $contacts->map(function ($contact) {
            return [
                'channel' => $contact->metadata['channel'] ?? null,
                'date_sent' => $contact->occurred_at?->format('Y-m-d H:i:s'),
            ];
        })->unique('channel')->values()->toArray();
    }

    /**
     * Get supplier performance metrics
     */
    public function getSupplierPerformance(string $supplierId): array
    {
        // Get all variances for this supplier
        $variances = PurchaseVariance::with('purchaseOrder')
            ->whereHas('purchaseOrder', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId);
            })
            ->whereNotNull('responded_at')
            ->get();

        if ($variances->isEmpty()) {
            return [
                'average_response_time_hours' => 0,
                'response_rate_percentage' => 0,
            ];
        }

        $totalResponseTime = 0;
        $responseCount = 0;

        foreach ($variances as $variance) {
            if ($variance->created_at && $variance->responded_at) {
                $responseTime = $variance->created_at->diffInHours($variance->responded_at);
                $totalResponseTime += $responseTime;
                $responseCount++;
            }
        }

        $averageResponseTime = $responseCount > 0 ? $totalResponseTime / $responseCount : 0;

        // Calculate response rate
        $totalVariances = PurchaseVariance::whereHas('purchaseOrder', function ($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId);
        })->count();

        $responseRate = $totalVariances > 0 ? ($responseCount / $totalVariances) * 100 : 0;

        return [
            'average_response_time_hours' => round($averageResponseTime, 2),
            'response_rate_percentage' => round($responseRate, 2),
        ];
    }

    /**
     * Get supplier information with communication details
     */
    public function getSupplierInfo(string $supplierId, string $orderId): array
    {
        $order = PurchaseOrder::with('supplier')->find($orderId);
        
        if (!$order || !$order->supplier) {
            return [];
        }

        $supplier = $order->supplier;
        $performance = $this->getSupplierPerformance($supplierId);
        $contactMethods = $this->getContactMethods($orderId);

        return [
            'supplier_name' => $supplier->name,
            'supplier_image' => $supplier->image ?? null,
            'supplier_status' => $this->getSupplierStatus($supplier), // online, away, offline
            'available_contact_methods' => [
                'whatsapp' => !empty($supplier->whatsapp),
                'sms' => !empty($supplier->phone),
                'email' => !empty($supplier->email),
                'in_app_chat' => true, // Always available
            ],
            'response_statistics' => $performance,
            'contact_history' => $contactMethods,
        ];
    }

    /**
     * Get supplier status (simplified - you may want to implement real-time status)
     */
    private function getSupplierStatus($supplier): string
    {
        // This is a placeholder - implement based on your actual supplier status logic
        // You might check last activity, online presence, etc.
        return 'offline'; // Default
    }
}
