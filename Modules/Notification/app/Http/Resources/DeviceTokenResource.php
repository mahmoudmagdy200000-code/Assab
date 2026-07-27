<?php

namespace Modules\Notification\Http\Resources;

use App\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property-read \Modules\Notification\Models\DeviceToken $resource
 */
class DeviceTokenResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            // The raw token is never echoed back: it is a device credential,
            // and a compromised access token must not be a route to it.
            'token_preview' => $this->preview(),
            'platform' => $this->resource->platform?->value,
            'app' => $this->resource->app?->value,
            'locale' => $this->resource->locale,
            'device_id' => $this->resource->device_id,
            'device_name' => $this->resource->device_name,
            'app_version' => $this->resource->app_version,
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }

    private function preview(): string
    {
        $token = (string) $this->resource->token;

        return mb_strlen($token) <= 12
            ? str_repeat('*', mb_strlen($token))
            : mb_substr($token, 0, 6).'…'.mb_substr($token, -4);
    }
}
