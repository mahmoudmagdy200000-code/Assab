<?php

namespace Modules\Admin\Services;

use Illuminate\Http\Request;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AuditLog;

/**
 * Records platform audit entries (BACKEND_API_SPEC.md §6.1.7 / §3.6).
 */
class AuditService
{
    public function record(
        string $action,
        ?AsabUser $actor = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?string $description = null,
        array $before = [],
        array $after = [],
        ?Request $request = null
    ): void {
        AuditLog::create([
            'company_id' => $actor?->company_id,
            'actor_user_id' => $actor?->id,
            'actor_label' => $actor?->name,
            'actor_role' => $actor?->primaryRole(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'before' => $before ?: null,
            'after' => $after ?: null,
            'occurred_at' => now(),
        ]);
    }
}
