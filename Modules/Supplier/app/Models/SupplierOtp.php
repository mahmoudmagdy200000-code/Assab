<?php

namespace Modules\Supplier\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class SupplierOtp extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'identifier',
        'otp',
        'type',
        'expires_at',
        'is_used',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    /**
     * Scope a query to only include valid OTPs.
     */
    public function scopeValid($query)
    {
        return $query->where('is_used', false)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope a query to filter by identifier.
     */
    public function scopeByIdentifier($query, string $identifier)
    {
        return $query->where('identifier', $identifier);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Check if OTP is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if OTP is valid.
     */
    public function isValid(): bool
    {
        return ! $this->is_used && ! $this->isExpired();
    }

    /**
     * Mark OTP as used.
     */
    public function markAsUsed(): void
    {
        $this->update(['is_used' => true]);
    }

    /**
     * Verify OTP.
     */
    public function verify(string $otp): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        return Hash::check($otp, $this->otp) || $this->otp === $otp;
    }

    /**
     * Generate a new OTP.
     */
    public static function generate(string $identifier, string $type, int $expiryMinutes = 10): self
    {
        // Delete old OTPs for this identifier
        self::where('identifier', $identifier)
            ->where('type', $type)
            ->delete();

        // Generate new OTP
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return self::create([
            'identifier' => $identifier,
            'otp' => Hash::make($otp),
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes($expiryMinutes),
            'is_used' => false,
        ]);
    }

    /**
     * Verify OTP by identifier and OTP code.
     */
    public static function verifyOtp(string $identifier, string $otp, string $type): ?self
    {
        $record = self::where('identifier', $identifier)
            ->where('type', $type)
            ->valid()
            ->latest()
            ->first();

        if (! $record) {
            return null;
        }

        // Check if OTP matches (hashed or plain)
        if (Hash::check($otp, $record->otp) || $record->otp === $otp) {
            return $record;
        }

        return null;
    }
}
