<?php

namespace Modules\BranchManagers\Transformers\Settings;

use App\Http\Resources\BaseResource;

/**
 * A single aggregator row on the branch manager settings screen.
 *
 * The `enabled` flag is resolved from the `enabled_for_branch` attribute
 * set by the service layer (false for aggregators not yet added).
 *
 * @mixin \Modules\Aggregator\Models\Aggregator
 */
class SettingsAggregatorResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            // Strings, never null: the mobile client casts them (a null logo
            // crashed the end-shift «add aggregators» sheet).
            'name' => (string) $this->name,
            'logo_url' => (string) ($this->logo_url ?? ''),
            'enabled' => (bool) ($this->enabled_for_branch ?? false),
        ];
    }
}
