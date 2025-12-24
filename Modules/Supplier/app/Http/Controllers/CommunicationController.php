<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Http\Requests\Communication\SendMessageRequest;
use Modules\Supplier\Services\CommunicationService;
use Modules\Supplier\Transformers\MessageResource;
use Modules\Supplier\Transformers\NotificationResource;

class CommunicationController extends BaseController
{
    public function __construct(
        private readonly CommunicationService $communicationService
    ) {}

    /**
     * Send message
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $message = $this->communicationService->sendMessage($supplier, $request->validated());

            return $this->createdResponse(
                new MessageResource($message),
                'Message sent successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'sending message');
        }
    }

    /**
     * Get messages
     */
    public function getMessages(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['branch_id', 'order_id', 'is_read']);
            $perPage = request()->get('per_page', 15);

            $messages = $this->communicationService->getMessages($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                MessageResource::collection($messages),
                'Messages retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching messages');
        }
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(?string $id = null): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $this->communicationService->markAsRead($supplier, $id);

            return $this->successResponse(null, 'Messages marked as read');
        } catch (\Exception $e) {
            return $this->handleException($e, 'marking messages as read');
        }
    }

    /**
     * Get notifications
     */
    public function getNotifications(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $filters = request()->only(['type', 'is_read']);
            $perPage = request()->get('per_page', 15);

            $notifications = $this->communicationService->getNotifications($supplier, $filters, $perPage);

            return $this->paginatedResponse(
                NotificationResource::collection($notifications),
                'Notifications retrieved successfully'
            );
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching notifications');
        }
    }

    /**
     * Mark notifications as read
     */
    public function markNotificationAsRead(?string $id = null): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $this->communicationService->markNotificationAsRead($supplier, $id);

            return $this->successResponse(null, 'Notifications marked as read');
        } catch (\Exception $e) {
            return $this->handleException($e, 'marking notifications as read');
        }
    }
}

