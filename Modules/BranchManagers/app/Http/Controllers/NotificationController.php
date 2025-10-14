<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

use Modules\BranchManagers\Traits\ApiResponseTrait;
class NotificationController extends Controller
{
    use ApiResponseTrait;
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('branch.manager');
    }

    /**
     * Get all notifications
     */
    public function index(Request $request): JsonResponse
    {
        $manager = auth()->user();

        $perPage = $request->input('per_page', 15);
        $notifications = $manager->notifications()->paginate($perPage);

        return response()->paginated($notifications, 'Notifications retrieved successfully');
    }

    /**
     * Get unread notifications
     */
    public function unread(): JsonResponse
    {
        $manager = auth()->user();

        $notifications = $manager->unreadNotifications;

        return $this->successResponse([
            'count' => $notifications->count(),
            'notifications' => $notifications,
        ], 'Unread notifications retrieved successfully');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(string $id): JsonResponse
    {
        $manager = auth()->user();

        $notification = $manager->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $this->successResponse(null, 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        $manager = auth()->user();

        $manager->unreadNotifications->markAsRead();

        return $this->successResponse(null, 'All notifications marked as read');
    }

    /**
     * Delete notification
     */
    public function delete(string $id): JsonResponse
    {
        $manager = auth()->user();

        $notification = $manager->notifications()->findOrFail($id);
        $notification->delete();

        return $this->successResponse(null, 'Notification deleted successfully');
    }

    /**
     * Clear all notifications
     */
    public function clearAll(): JsonResponse
    {
        $manager = auth()->user();

        $manager->notifications()->delete();

        return $this->successResponse(null, 'All notifications cleared');
    }
}
