<?php

namespace Modules\Cashier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Database\Factories\CashierFactory;
use Modules\Cashier\Notifications\CashierActivationNotification;
use Modules\Notification\Traits\HasDeviceTokens;
use Modules\Shift\Models\CashierShift;

class Cashier extends Authenticatable
{
    use HasApiTokens, HasDeviceTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'image',
        'branch_id',
        'status',
        'created_by',
        'activated_at',
        'deactivated_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'status_color',
    ];

    // Factory
    protected static function newFactory()
    {
        return CashierFactory::new();
    }

    public function settings()
    {
        return $this->morphOne(\Modules\Settings\Models\UserSetting::class, 'userable');
    }

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'cashier_id');
    }

    public function assignedShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'cashier_id'); // تغيير هنا
    }

    public function receivedHandovers(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'next_cashier_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDeactivated($query)
    {
        return $query->where('status', 'deactivated');
    }

    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    public function scopeCreatedBetween($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'pending' => 'Pending Activation',
            'deactivated' => 'Deactivated',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'active' => 'green',
            'pending' => 'yellow',
            'deactivated' => 'red',
            default => 'gray',
        };
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    // Methods
    public function activate(): void
    {
        $this->update([
            'status' => 'active',
            'activated_at' => now(),
            'deactivated_at' => null,
        ]);
    }

    public function deactivate(): void
    {
        $this->update([
            'status' => 'deactivated',
            'deactivated_at' => now(),
        ]);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDeactivated(): bool
    {
        return $this->status === 'deactivated';
    }

    /**
     * The cashier's counterpart of `is_first_login` on the other three mobile
     * account types (branch manager, brand owner, supplier).
     *
     * A cashier is created `pending` and carries no `is_first_login` column —
     * `status` is the same fact under a different name. Naming it explicitly
     * lets the shared activation contract (FirstLoginActivation::maySetPassword)
     * apply here unchanged, instead of the cashier being the one surface with
     * bespoke rules (meeting 2026-08-15).
     */
    public function isFirstLogin(): bool
    {
        return $this->isPending();
    }

    public function hasActiveShift(): bool
    {
        return $this->shifts()
            ->whereIn('status', ['not_started', 'in_progress'])
            ->whereDate('shift_date', today())
            ->exists();
    }

    public function getCurrentShift()
    {
        return $this->shifts()
            ->where('status', 'in_progress')
            ->whereDate('shift_date', today())
            ->first();
    }

    public function getNextShift()
    {
        return $this->shifts()
            ->where('status', 'not_started')
            ->where('shift_date', '>=', today())
            ->join('shifts', 'cashier_shifts.shift_id', '=', 'shifts.id')
            ->orderBy('cashier_shifts.shift_date', 'asc')
            ->orderBy('shifts.start_time', 'asc')
            ->select('cashier_shifts.*')
            ->first();
    }

    public function getTotalShiftsCount(): int
    {
        try {
            return $this->shifts()->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getCompletedShiftsCount(): int
    {
        return $this->shifts()->where('status', 'completed')->count();
    }

    public function getTotalSales(): float
    {
        return $this->shifts()
            ->where('status', 'completed')
            ->sum('total_sales');
    }

    public function getTotalVariance(): float
    {
        return $this->shifts()
            ->where('status', 'completed')
            ->sum('variance');
    }

    public function sendActivationNotification(): void
    {
        $this->notify(new CashierActivationNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    // Route key name for route model binding
    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
