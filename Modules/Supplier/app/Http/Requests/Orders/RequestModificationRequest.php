<?php

namespace Modules\Supplier\Http\Requests\Orders;

use Illuminate\Foundation\Http\FormRequest;

class RequestModificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get note from note, message, or modification_request (for API clients that send different keys).
     */
    public function getNote(): ?string
    {
        $validated = $this->validated();

        return $validated['note'] ?? $validated['message'] ?? $validated['modification_request'] ?? null;
    }

    /**
     * Get reason from reason or message (for API clients that send message instead of reason).
     */
    public function getReason(): string
    {
        $validated = $this->validated();

        return $validated['reason'] ?? $validated['message'] ?? $validated['modification_request'] ?? '';
    }

    public function rules(): array
    {
        $route = $this->route();
        $routeName = $route?->getName();
        $routeAction = $route?->getActionMethod();
        $modificationType = $this->input('modification_type');

        // Rules for time change request
        // Check route name, action method, or modification_type
        if (
            $routeName === 'supplier.pending-orders.request-time-change' ||
            $routeAction === 'requestTimeChange' ||
            $modificationType === 'delivery_time'
        ) {
            return [
                'new_delivery_time' => 'required|date|after:now',
                'reason' => 'nullable|string|max:500',
                'note' => 'nullable|string|max:1000',
                'message' => 'nullable|string|max:1000',
                'modification_type' => 'nullable|in:delivery_time',
                'modification_request' => 'nullable|string|max:2000',
            ];
        }

        // Rules for alternative product request
        // Check route name, action method, or modification_type
        if (
            $routeName === 'supplier.pending-orders.request-alternative' ||
            $routeAction === 'requestAlternative' ||
            $modificationType === 'alternative_product'
        ) {
            return [
                'alternative_item_id' => 'required|uuid',
                'reason' => 'nullable|string|max:1000',
                'note' => 'nullable|string|max:1000',
                'message' => 'nullable|string|max:1000',
                'modification_type' => 'nullable|in:alternative_product',
                'modification_request' => 'nullable|string|max:2000',
            ];
        }

        // Default rules (for general modification request)
        return [
            'modification_type' => 'required|in:quantity,delivery_time,alternative_product',
            'modification_request' => 'nullable|string|max:2000',
            'message' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:1000',
            'reason' => 'nullable|string|max:1000',
            'suggested_changes' => 'nullable|array',
        ];
    }
}
