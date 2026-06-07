<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;

/**
 * Global header quick-stats widget (MISSING_Dashboard §11.3). Operations /
 * reminders inherit the tenant global scope; notifications are user-scoped.
 */
class QuickStatsController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            return $this->ok([
                'pendingApprovalsCount' => Operation::where('status', Operation::STATUS_PENDING)->count(),
                'unreadNotificationsCount' => AsabNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
                'todayOperationsCount' => Operation::where('operation_date', '>=', now()->startOfDay())->count(),
                'openRemindersCount' => Reminder::where('reminder_status', '!=', 'responded')->count(),
            ]);
        });
    }
}
