<?php

namespace Modules\BranchManagers\Transformers\Settings;

use App\Http\Resources\BaseResource;
use Modules\BranchManagers\Enums\NotificationSettingType;
use Modules\BranchManagers\Support\BranchScheduleFormatter;

/**
 * Full settings snapshot for the branch manager settings home section.
 *
 * Wraps the array assembled by BranchManagerSettingsService::snapshot().
 */
class SettingsSnapshotResource extends BaseResource
{
    public function toArray($request): array
    {
        $branch = $this->resource['branch'];
        $manager = $this->resource['manager'];
        $settings = $this->resource['settings'];

        return [
            'branch_info' => [
                'branch_id' => $branch?->id,
                'branch_name' => $branch?->name,
                'branch_manager_name' => $manager->name,
                'branch_manager_image_url' => $manager->image_url,
                'branch_image_url' => $branch && $branch->image
                    ? asset('storage/'.$branch->image)
                    : null,
                'branch_opening' => BranchScheduleFormatter::branchOpening($branch),
                'total_cashiers' => (int) $this->resource['total_cashiers'],
                'total_aggregators' => (int) $this->resource['total_aggregators'],
            ],
            'available_aggregators' => SettingsAggregatorResource::collection(
                $this->resource['available']
            )->toArray($request),
            'added_aggregators' => SettingsAggregatorResource::collection(
                $this->resource['added']
            )->toArray($request),
            'notifications' => collect(NotificationSettingType::cases())
                ->map(fn (NotificationSettingType $type) => [
                    'type' => $type->value,
                    'enabled' => (bool) $settings->{$type->column()},
                ])
                ->all(),
        ];
    }
}
