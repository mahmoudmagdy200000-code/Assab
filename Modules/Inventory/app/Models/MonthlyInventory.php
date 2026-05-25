<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Enums\MonthlyInventoryStatus;

class MonthlyInventory extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'inventory_number',
        'branch_id',
        'created_by',
        'inventory_date',
        'start_time',
        'end_time',
        'time_taken',
        'number_of_products',
        'expected_time_minutes',
        'status',
        'submitted_at',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'inventory_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'status' => MonthlyInventoryStatus::class,
    ];

    protected $appends = [
        'time_taken_formatted',
        'status_label',
        'status_color',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->inventory_number)) {
                $model->inventory_number = static::generateInventoryNumber();
            }
            if (empty($model->status)) {
                $model->status = MonthlyInventoryStatus::PENDING;
            }
            if (empty($model->start_time)) {
                $model->start_time = now();
            }
        });
    }

    public static function generateInventoryNumber(): string
    {
        $prefix = 'MI';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(6));

        do {
            $number = "{$prefix}-{$date}-{$random}";
        } while (static::where('inventory_number', $number)->exists());

        return $number;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(MonthlyInventoryStaff::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(MonthlyInventoryProduct::class, 'monthly_inventory_id');
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(MonthlyInventoryTimeline::class, 'monthly_inventory_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(MonthlyInventoryFeedback::class, 'monthly_inventory_id');
    }

    public function getTimeTakenFormattedAttribute(): ?string
    {
        if (! $this->time_taken) {
            return null;
        }

        $hours = (int) floor($this->time_taken / 3600);
        $minutes = (int) floor(($this->time_taken % 3600) / 60);
        $seconds = (int) ($this->time_taken % 60);

        return match (true) {
            $hours > 0 => "{$hours} hours {$minutes} minutes",
            $minutes > 0 => "{$minutes} minutes {$seconds} seconds",
            default => "{$seconds} seconds",
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    public function calculateTimeTaken(): void
    {
        if ($this->end_time && $this->start_time) {
            $this->time_taken = (int) $this->start_time->diffInSeconds($this->end_time);
        }
    }
}
