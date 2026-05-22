<?php

namespace Modules\BranchManagers\Transformers\Settings;

use App\Http\Resources\BaseResource;
use Modules\BranchManagers\Enums\NotificationSettingType;

/**
 * A notification toggle with its title, description and current state.
 *
 * Wraps an array shaped as ['type' => NotificationSettingType, 'enabled' => bool].
 */
class NotificationSettingResource extends BaseResource
{
    public function toArray($request): array
    {
        /** @var NotificationSettingType $type */
        $type = $this->resource['type'];

        return [
            'type' => $type->value,
            'title' => $type->title(),
            'description' => $type->description(),
            'enabled' => (bool) $this->resource['enabled'],
        ];
    }
}
