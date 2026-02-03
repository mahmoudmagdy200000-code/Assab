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
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Enums\InventorySessionStatus;

class InventorySession extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'session_number',
        'branch_id',
        'created_by',
        'assigned_to_id',
        'assigned_to_type',
        'inventory_date',
        'start_time',
        'end_time',
        'time_taken',
        'status',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'inventory_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'time_taken' => 'integer',
        'status' => InventorySessionStatus::class,
        'submitted_at' => 'datetime',
    ];

    protected $appends = [
        'time_taken_formatted',
        'status_label',
        'status_color',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($session) {
            if (empty($session->session_number)) {
                $session->session_number = static::generateSessionNumber();
            }

            if (empty($session->status)) {
                $session->status = InventorySessionStatus::DRAFT;
            }

            // Only set start_time automatically for personal assignments
            if (empty($session->start_time) && $session->assigned_to_type === 'personal') {
                $session->start_time = now();
            }
        });
    }

    /**
     * Generate unique session number
     */
    public static function generateSessionNumber(): string
    {
        $prefix = 'INV';
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(6));

        do {
            $sessionNumber = "{$prefix}-{$date}-{$random}";
        } while (static::where('session_number', $sessionNumber)->exists());

        return $sessionNumber;
    }

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(BranchManager::class, 'created_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'assigned_to_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    // Accessors
    public function getTimeTakenFormattedAttribute(): ?string
    {
        if (!$this->time_taken) {
            return null;
        }

        $hours = floor($this->time_taken / 3600);
        $minutes = floor(($this->time_taken % 3600) / 60);
        $seconds = $this->time_taken % 60;

        if ($hours > 0) {
            return "{$hours} hours {$minutes} minutes";
        } elseif ($minutes > 0) {
            return "{$minutes} minutes {$seconds} seconds";
        } else {
            return "{$seconds} seconds";
        }
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    // Methods
    public function calculateTimeTaken(): void
    {
        if ($this->end_time && $this->start_time) {
            $this->time_taken = $this->end_time->diffInSeconds($this->start_time);
        }
    }

    public function complete(): void
    {
        $this->end_time = now();
        $this->calculateTimeTaken();
        $this->status = InventorySessionStatus::COMPLETED;
        $this->submitted_at = now();
        $this->save();
    }
}

