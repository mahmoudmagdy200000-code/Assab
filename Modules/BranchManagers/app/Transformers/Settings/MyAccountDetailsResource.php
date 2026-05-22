<?php

namespace Modules\BranchManagers\Transformers\Settings;

use App\Http\Resources\BaseResource;
use Illuminate\Support\Str;
use Modules\BranchManagers\Support\BranchScheduleFormatter;

/**
 * Account details for the settings screen.
 *
 * Works for any authenticated user model; branch dependent fields fall
 * back to null when the user is not tied to a branch.
 */
class MyAccountDetailsResource extends BaseResource
{
    public function toArray($request): array
    {
        [$firstName, $lastName] = $this->splitName((string) ($this->name ?? ''));

        $branch = $this->resource->branch ?? null;

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email_address' => $this->email,
            'role' => $this->resolveRole(),
            'assigned_to' => $branch?->name,
            'weekdays' => BranchScheduleFormatter::weekdays(),
            'work_shift' => BranchScheduleFormatter::workShift($branch),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = explode(' ', trim($name), 2);

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    private function resolveRole(): string
    {
        $role = $this->resource->role ?? null;

        return Str::headline(
            ! empty($role) ? (string) $role : class_basename($this->resource)
        );
    }
}
