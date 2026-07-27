<?php

namespace Modules\Notification\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;

class RegisterDeviceTokenRequest extends BaseRequest
{
    /**
     * Only an authenticated principal that can actually own device tokens may
     * register one. The token is always bound to the caller — the request body
     * carries no owner field, so there is nothing to spoof.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && method_exists($user, 'deviceTokens');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // FCM registration tokens are opaque and have grown over time; the
            // bounds only reject obvious junk.
            'token' => ['required', 'string', 'min:32', 'max:4096'],
            'platform' => ['required', 'string', 'in:'.implode(',', DevicePlatform::values())],
            'app' => ['sometimes', 'string', 'in:'.implode(',', DeviceApp::values())],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'token.required' => 'The FCM registration token is required.',
            'platform.in' => 'Platform must be one of: '.implode(', ', DevicePlatform::values()).'.',
            'app.in' => 'App must be one of: '.implode(', ', DeviceApp::values()).'.',
        ]);
    }
}
