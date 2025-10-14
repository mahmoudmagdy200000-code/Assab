<?php

namespace Modules\BranchManagers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class BranchManagerOtp extends Model
{
    use HasFactory;

    protected $fillable = [
        'identifier',
        'otp',
        'type',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    // Scopes
    public function scopeValid($query)
    {
        return $query->where('is_used', false)
            ->where('expires_at', '>', now());
    }

    public function scopeByIdentifier($query, string $identifier)
    {
        return $query->where('identifier', $identifier);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // Methods
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
        // Delete old OTPs
        self::where('identifier', $identifier)
            ->where('type', $type)
            ->delete();

        // Generate new OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return self::create([
            'identifier' => $identifier,
            'otp' => $otp,
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
            'is_used' => false,
        ]);
    }

    public static function verify(string $identifier, string $otp, string $type): bool
    {
        $record = self::where('identifier', $identifier)
            ->where('otp', $otp)
            ->where('type', $type)
            ->valid()
            ->first();

        if ($record) {
            $record->markAsUsed();
            return true;
        }

        return false;
    }
}
