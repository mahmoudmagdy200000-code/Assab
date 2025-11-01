<?php

namespace Modules\BranchManagers\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Modules\Branch\Models\Branch;
use Modules\BranchManager\Database\Factories\BranchManagerFactory;
use Modules\BranchManagers\Database\Factories\BranchManagerFactory as FactoriesBranchManagerFactory;
use Modules\BranchManagers\Notifications\ResetPasswordNotification;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\Expense;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;

class BranchManager extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'branch_id',
        'image',
        'status',
        'is_active',
        'is_first_login',
        'email_verified_at',
        'phone_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_first_login' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'status_color',
        'image_url',
    ];

    // Factory
    protected static function newFactory()
    {
        return FactoriesBranchManagerFactory::new();
    }

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashiers(): HasMany
    {
        return $this->hasMany(Cashier::class, 'created_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function cashierShifts(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'created_by');
    }

    // public function settings(): HasMany
    // {
    //     return $this->hasMany(UserSetting::class, 'user_id')
    //         ->where('user_type', self::class);
    // }


    public function settings()
    {
        return $this->morphOne(\Modules\Settings\Models\UserSetting::class, 'userable');
    }


    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeByBranch($query, int $branchId)
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

    public function scopeEmailVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopePhoneVerified($query)
    {
        return $query->whereNotNull('phone_verified_at');
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        if (!$this->is_active) {
            return 'Inactive';
        }

        return match ($this->status) {
            'active' => 'Active',
            'pending' => 'Pending',
            'suspended' => 'Suspended',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute(): string
    {
        if (!$this->is_active) {
            return 'gray';
        }

        return match ($this->status) {
            'active' => 'green',
            'pending' => 'yellow',
            'suspended' => 'red',
            default => 'gray',
        };
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/' . $this->image) : null;
    }

    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    // Methods
    public function isActive(): bool
    {
        return $this->is_active && $this->status === 'active';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isFirstLogin(): bool
    {
        return $this->is_first_login;
    }

    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function activate(): void
    {
        $this->update([
            'status' => 'active',
            'is_active' => true,
        ]);
    }

    public function deactivate(): void
    {
        $this->update([
            'is_active' => false,
        ]);
    }

    public function suspend(string $reason = null): void
    {
        $this->update([
            'status' => 'suspended',
            'is_active' => false,
        ]);

        // Log suspension reason
        Log::warning('Branch Manager suspended', [
            'manager_id' => $this->id,
            'reason' => $reason,
        ]);
    }

    public function markFirstLoginComplete(): void
    {
        $this->update([
            'is_first_login' => false,
        ]);
    }

    public function verifyEmail(): void
    {
        $this->update([
            'email_verified_at' => now(),
        ]);
    }

    public function verifyPhone(): void
    {
        $this->update([
            'phone_verified_at' => now(),
        ]);
    }

    public function getTotalCashiers(): int
    {
        return $this->cashiers()->count();
    }

    public function getActiveCashiers(): int
    {
        return $this->cashiers()->where('status', 'active')->count();
    }

    public function getTodayShifts(): int
    {
        return CashierShift::whereHas('shift', function ($q) {
            $q->where('branch_id', $this->branch_id);
        })
            ->whereDate('shift_date', today())
            ->count();
    }

    // public function getTotalExpenses(): float
    // {
    //     return $this->expenses()
    //         ->where('status', 'approved')
    //         ->sum('amount');
    // }

    public function hasPermission(string $permission): bool
    {
        // Implement permission logic if needed
        return true; // For now, all branch managers have full access
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
