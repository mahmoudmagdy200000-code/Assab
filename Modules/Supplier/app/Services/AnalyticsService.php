<?php

namespace Modules\Supplier\Services;

use Carbon\Carbon;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierInvoice;

class AnalyticsService
{
    /**
     * Get order statistics
     */
    public function getOrderStatistics(Supplier $supplier, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();
        $endDate = $endDate ?? now()->endOfMonth();

        $orders = PurchaseOrder::where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier')
            ->whereBetween('created_at', [$startDate, $endDate]);

        $totalOrders = $orders->count();
        $completedOrders = (clone $orders)->where('status', OrderStatus::DELIVERED)->count();
        $pendingOrders = (clone $orders)->whereIn('status', [OrderStatus::PENDING, OrderStatus::PENDING_CONFIRMATION])->count();
        $inProgressOrders = (clone $orders)->whereIn('status', [OrderStatus::CONFIRMED, OrderStatus::PREPARING, OrderStatus::ON_THE_WAY])->count();

        $fulfillmentRate = $totalOrders > 0 ? ($completedOrders / $totalOrders) * 100 : 0;

        // Calculate average response time
        $responseTimes = (clone $orders)
            ->whereNotNull('confirmed_at')
            ->get()
            ->map(function ($order) {
                return $order->created_at->diffInHours($order->confirmed_at);
            });

        $averageResponseTime = $responseTimes->count() > 0 ? $responseTimes->avg() : 0;

        // Calculate on-time delivery rate
        $deliveredOrders = (clone $orders)
            ->where('status', OrderStatus::DELIVERED)
            ->whereNotNull('expected_delivery_at')
            ->whereNotNull('actual_delivery_at')
            ->get();

        $onTimeDeliveries = $deliveredOrders->filter(function ($order) {
            return $order->actual_delivery_at <= $order->expected_delivery_at;
        })->count();

        $onTimeDeliveryRate = $deliveredOrders->count() > 0 ? ($onTimeDeliveries / $deliveredOrders->count()) * 100 : 0;

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'pending_orders' => $pendingOrders,
            'in_progress_orders' => $inProgressOrders,
            'fulfillment_rate' => round($fulfillmentRate, 2),
            'average_response_time_hours' => round($averageResponseTime, 2),
            'on_time_delivery_rate' => round($onTimeDeliveryRate, 2),
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Get financial report
     */
    public function getFinancialReport(Supplier $supplier, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $startDate = $startDate ?? now()->startOfMonth();
        $endDate = $endDate ?? now()->endOfMonth();

        $invoices = SupplierInvoice::where('supplier_id', $supplier->id)
            ->whereBetween('invoice_date', [$startDate, $endDate]);

        $totalRevenue = (clone $invoices)->sum('total_amount');
        $totalTax = (clone $invoices)->sum('tax_amount');
        $totalPaid = (clone $invoices)->where('payment_status', 'paid')->sum('paid_amount');
        $totalPending = (clone $invoices)->where('payment_status', 'pending')->sum('total_amount');

        return [
            'total_revenue' => (float) $totalRevenue,
            'total_tax' => (float) $totalTax,
            'total_paid' => (float) $totalPaid,
            'total_pending' => (float) $totalPending,
            'outstanding_amount' => (float) ($totalRevenue - $totalPaid),
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
            ],
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(Supplier $supplier): array
    {
        $orders = PurchaseOrder::where('supplier_id', $supplier->id)
            ->where('order_type', 'direct_supplier');

        $totalOrders = $orders->count();
        $completedOrders = (clone $orders)->where('status', OrderStatus::DELIVERED)->count();

        // Calculate response rate
        $respondedOrders = (clone $orders)
            ->whereIn('status', [
                OrderStatus::CONFIRMED,
                OrderStatus::REJECTED,
                OrderStatus::DELIVERED,
            ])
            ->count();

        $responseRate = $totalOrders > 0 ? ($respondedOrders / $totalOrders) * 100 : 0;

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'response_rate' => round($responseRate, 2),
            'rating' => (float) ($supplier->rating ?? 0),
            'average_response_time_hours' => (float) ($supplier->average_response_time_hours ?? 0),
        ];
    }
}

