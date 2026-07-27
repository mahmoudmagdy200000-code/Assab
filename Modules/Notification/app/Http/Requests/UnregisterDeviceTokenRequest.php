<?php

namespace Modules\Notification\Http\Requests;

use App\Http\Requests\BaseRequest;

class UnregisterDeviceTokenRequest extends BaseRequest
{
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
            'token' => ['required', 'string', 'min:32', 'max:4096'],
        ];
    }
}
