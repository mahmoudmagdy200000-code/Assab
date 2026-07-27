<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Enums\DevicePlatform;

/**
 * An FCM registration token owned by exactly one notifiable. A token is a
 * device-scoped secret: it is never shared between two owners, so re-registering
 * an existing token re-binds it (see DeviceTokenService::register).
 *
 * Not soft-deletable — see the migration for why.
 */
class DeviceToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'token',
        'token_hash',
        'platform',
        'app',
        'locale',
        'device_id',
        'device_name',
        'app_version',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'platform' => DevicePlatform::class,
        'app' => DeviceApp::class,
        'last_used_at' => 'datetime',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function hashFor(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('token_hash', self::hashFor($token));
    }

    public function scopeForPlatform(Builder $query, DevicePlatform $platform): Builder
    {
        return $query->where('platform', $platform->value);
    }

    public function scopeForApp(Builder $query, DeviceApp $app): Builder
    {
        return $query->where('app', $app->value);
    }

    /**
     * Tokens untouched for longer than the configured window. FCM drops a token
     * after ~270 days of app inactivity; pruning earlier keeps fan-out cheap.
     */
    public function scopeStale(Builder $query, int $days): Builder
    {
        return $query->where(function (Builder $q) use ($days) {
            $q->where('last_used_at', '<', now()->subDays($days))
                ->orWhere(function (Builder $inner) use ($days) {
                    $inner->whereNull('last_used_at')
                        ->where('created_at', '<', now()->subDays($days));
                });
        });
    }
}
