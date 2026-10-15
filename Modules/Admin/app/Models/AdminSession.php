<?php

namespace Modules\Admin\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Additive Admin Session entity representing an authenticated session family (Slice 01).
 *
 * Implements Option B session tracking with atomic rotation generation and
 * an immutable legacy compatibility alias (D09).
 */
class AdminSession extends Model
{
    use HasUuids;

    protected $table = 'admin_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'company_id',
        'device_name',
        'ip_address',
        'user_agent',
        'is_remember_me',
        'generation',
        'current_access_token_id',
        'current_refresh_token_id',
        'legacy_access_token_id',
        'last_active_at',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'is_remember_me' => 'boolean',
        'generation' => 'integer',
        'current_access_token_id' => 'integer',
        'current_refresh_token_id' => 'integer',
        'legacy_access_token_id' => 'integer',
        'last_active_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (AdminSession $session) {
            if ($session->isDirty('legacy_access_token_id') && $session->getOriginal('legacy_access_token_id') !== null) {
                $session->legacy_access_token_id = $session->getOriginal('legacy_access_token_id');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AsabUser::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(AsabCompany::class, 'company_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class, 'session_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof CarbonInterface && now()->greaterThanOrEqualTo($this->expires_at);
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked()
            && $this->expires_at instanceof CarbonInterface
            && now()->lessThan($this->expires_at);
    }
}
