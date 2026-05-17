<?php

namespace Modules\BrandOwner\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BrandOwnerOtp extends Model
{
    use HasUuids;

    protected $table = 'brand_owner_otps';

    protected $fillable = [
        'identifier',
        'otp',
        'type',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used'    => 'boolean',
    ];

    public function scopeValid($query)
    {
        return $query->where('is_used', false)
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isValid(): bool
    {
        return !$this->is_used && !$this->isExpired();
    }

    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    public static function generate(string $identifier, string $type, int $expiryMinutes = 10): self
    {
        self::where('identifier', $identifier)->where('type', $type)->delete();

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return self::create([
            'identifier' => $identifier,
            'otp'        => $otp,
            'type'       => $type,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
            'is_used'    => false,
        ]);
    }
}
