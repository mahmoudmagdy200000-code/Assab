<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Services\NotificationPreferenceService;

class NotificationPreferenceController extends BaseController
{
    public function __construct(
        private NotificationPreferenceService $preferenceService
    ) {}

    /**
     * Get all preferences for authenticated user
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $preferences = $this->preferenceService->getUserPreferences($user);

        return $this->successResponse($preferences, 'Preferences retrieved successfully');
    }

    /**
     * Create or update preference
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notification_type' => 'required|string',
            'channels' => 'required|array',
            'channels.*' => 'string|in:app,email,sms',
            'priority_level' => 'required|string|in:low,medium,high,critical',
            'enabled' => 'boolean',
        ]);

        $user = Auth::user();
        $type = NotificationType::from($validated['notification_type']);

        $this->preferenceService->updatePreference(
            $user,
            $type,
            $validated['channels'],
            $validated['priority_level'],
            $validated['enabled'] ?? true
        );

        return $this->successResponse(null, 'Preference updated successfully');
    }

    /**
     * Update preference
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'channels' => 'sometimes|array',
            'channels.*' => 'string|in:app,email,sms',
            'priority_level' => 'sometimes|string|in:low,medium,high,critical',
            'enabled' => 'sometimes|boolean',
        ]);

        $user = Auth::user();
        $preference = \Modules\Notification\Models\NotificationPreference::findOrFail($id);

        // Verify ownership
        if ($preference->notifiable_type !== get_class($user) || $preference->notifiable_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $type = NotificationType::from($preference->notification_type);

        $this->preferenceService->updatePreference(
            $user,
            $type,
            $validated['channels'] ?? $preference->channels,
            $validated['priority_level'] ?? $preference->priority_level->value,
            $validated['enabled'] ?? $preference->enabled
        );

        return $this->successResponse(null, 'Preference updated successfully');
    }

    /**
     * Delete preference
     */
    public function destroy(string $id): JsonResponse
    {
        $user = Auth::user();
        $preference = \Modules\Notification\Models\NotificationPreference::findOrFail($id);

        // Verify ownership
        if ($preference->notifiable_type !== get_class($user) || $preference->notifiable_id !== $user->id) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $preference->delete();

        return $this->successResponse(null, 'Preference deleted successfully');
    }
}

