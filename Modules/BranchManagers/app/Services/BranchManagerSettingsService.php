<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\BranchManagers\Enums\NotificationSettingType;
use Modules\BranchManagers\Events\PasswordChangedEvent;
use Modules\BranchManagers\Exceptions\BranchManagerSettingsException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Settings\Models\UserSetting;
use Modules\Settings\Services\SettingsService;

/**
 * Orchestrates the branch manager settings screen: account details,
 * the settings snapshot, notification toggles and self-service password reset.
 */
class BranchManagerSettingsService
{
    public function __construct(
        private readonly BranchManagerAggregatorService $aggregators,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Resolve the authenticated user with its branch eager loaded (when any).
     */
    public function accountDetails(Model $user): Model
    {
        if (method_exists($user, 'branch')) {
            $user->loadMissing('branch');
        }

        return $user;
    }

    /**
     * Full settings snapshot for the branch manager settings home section.
     *
     * @return array<string, mixed>
     */
    public function snapshot(BranchManager $manager): array
    {
        $manager->loadMissing('branch');
        $branch = $manager->branch;
        $branchId = $manager->branch_id;

        $added = $this->aggregators->assigned($branchId);

        return [
            'branch' => $branch,
            'manager' => $manager,
            'settings' => $this->notificationSettings($manager),
            'available' => $this->aggregators->available($branchId),
            'added' => $added,
            'total_cashiers' => $branch ? $branch->cashiers()->count() : 0,
            'total_aggregators' => $added->count(),
        ];
    }

    /**
     * Persisted notification preferences for the manager (created with
     * defaults on first access).
     */
    public function notificationSettings(BranchManager $manager): UserSetting
    {
        return $this->settings->getUserSettings($manager->id, 'branch_manager');
    }

    /**
     * Toggle a single notification preference and return the updated setting.
     */
    public function toggleNotification(
        BranchManager $manager,
        NotificationSettingType $type,
        bool $enabled,
    ): UserSetting {
        return $this->settings->updateNotificationSettings(
            $manager->id,
            'branch_manager',
            [$type->settingsKey() => $enabled],
        );
    }

    /**
     * Reset the authenticated user's password after verifying the current one.
     * Revokes every other access token so stale sessions are invalidated.
     */
    public function resetPassword(Model $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            throw BranchManagerSettingsException::incorrectCurrentPassword();
        }

        DB::transaction(function () use ($user, $newPassword): void {
            $user->password = Hash::make($newPassword);
            $user->save();

            // This route is auth:sanctum only, with no branch.manager middleware,
            // so $user may be a Cashier/Supplier/BrandOwner. Dispatching for a
            // non-BranchManager would TypeError on the event's constructor.
            if ($user instanceof BranchManager) {
                PasswordChangedEvent::dispatch($user);
            }

            $this->revokeOtherTokens($user);
        });
    }

    private function revokeOtherTokens(Model $user): void
    {
        if (! method_exists($user, 'tokens')) {
            return;
        }

        $query = $user->tokens();

        $current = method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if ($current instanceof PersonalAccessToken) {
            $query->whereKeyNot($current->getKey());
        }

        $query->delete();
    }
}
