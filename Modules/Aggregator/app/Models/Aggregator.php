<?php

namespace Modules\Aggregator\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Aggregator\Database\Factories\AggregatorFactory;
use Modules\Branch\Models\Branch;
use Modules\Shift\Models\ShiftSalesBreakdown;

class Aggregator extends Model
{
    use HasFactory, SoftDeletes , HasUuids;

    protected $fillable = [
        'name',
        'code',
        'logo',
        'description',
        'contact_email',
        'contact_phone',
        'commission_rate',
        'payment_terms',
        'is_active',
        'integration_type',
        'api_key',
        'api_endpoint',
        'webhook_url',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'commission_rate' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = [
        'status_label',
        'status_color',
        'logo_url',
    ];

    // Factory
    protected static function newFactory()
    {
        return AggregatorFactory::new();
    }

    // Relationships

    /**
     * Aggregator belongs to many branches (through pivot)
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(
            Branch::class,
            'branch_aggregators',
            'aggregator_id',
            'branch_id'
        )->withPivot('is_enabled')->withTimestamps();
    }

    /**
     * Get enabled branches
     */
    public function enabledBranches(): BelongsToMany
    {
        return $this->branches()->wherePivot('is_enabled', true);
    }

    /**
     * Aggregator has many sales breakdown entries
     */
    public function salesBreakdown(): HasMany
    {
        return $this->hasMany(ShiftSalesBreakdown::class);
    }

    /**
     * Get sales breakdown for a specific period
     */
    public function salesBreakdownForPeriod(string $period = 'month')
    {
        $query = $this->salesBreakdown()->with(['cashierShift.cashier', 'cashierShift.shift']);

        switch ($period) {
            case 'today':
                $query->whereHas('cashierShift', function ($q) {
                    $q->whereDate('shift_date', today());
                });
                break;
            case 'week':
                $query->whereHas('cashierShift', function ($q) {
                    $q->whereBetween('shift_date', [now()->startOfWeek(), now()->endOfWeek()]);
                });
                break;
            case 'month':
                $query->whereHas('cashierShift', function ($q) {
                    $q->whereMonth('shift_date', now()->month)
                        ->whereYear('shift_date', now()->year);
                });
                break;
            case 'year':
                $query->whereHas('cashierShift', function ($q) {
                    $q->whereYear('shift_date', now()->year);
                });
                break;
        }

        return $query;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeByIntegrationType($query, string $type)
    {
        return $query->where('integration_type', $type);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    public function scopeHasIntegration($query)
    {
        return $query->whereNotNull('api_key')
            ->whereNotNull('api_endpoint');
    }

    // Accessors
    public function getStatusLabelAttribute(): string
    {
        return $this->is_active ? 'Active' : 'Inactive';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->is_active ? 'green' : 'gray';
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo ? asset('storage/' . $this->logo) : null;
    }

    public function getCommissionPercentageAttribute(): string
    {
        return number_format($this->commission_rate, 2) . '%';
    }

    // Methods
    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function hasIntegration(): bool
    {
        return !empty($this->api_key) && !empty($this->api_endpoint);
    }

    public function getTotalBranches(): int
    {
        return $this->branches()->count();
    }

    public function getEnabledBranches(): int
    {
        return $this->enabledBranches()->count();
    }

    public function getTotalSales(?string $period = 'all'): float
    {
        if ($period === 'all') {
            return $this->salesBreakdown()->sum('amount');
        }

        return $this->salesBreakdownForPeriod($period)->sum('amount');
    }

    public function getTotalOrders(?string $period = 'all'): int
    {
        if ($period === 'all') {
            return $this->salesBreakdown()->count();
        }

        return $this->salesBreakdownForPeriod($period)->count();
    }

    public function getAverageSalesPerOrder(?string $period = 'all'): float
    {
        $totalSales = $this->getTotalSales($period);
        $totalOrders = $this->getTotalOrders($period);

        return $totalOrders > 0 ? ($totalSales / $totalOrders) : 0;
    }

    public function getCommissionAmount(?string $period = 'all'): float
    {
        $totalSales = $this->getTotalSales($period);
        return $totalSales * ($this->commission_rate / 100);
    }

    public function getAggregatorStatistics(): array
    {
        return [
            'branches' => [
                'total' => $this->getTotalBranches(),
                'enabled' => $this->getEnabledBranches(),
            ],
            'sales' => [
                'today' => $this->getTotalSales('today'),
                'week' => $this->getTotalSales('week'),
                'month' => $this->getTotalSales('month'),
                'year' => $this->getTotalSales('year'),
                'all_time' => $this->getTotalSales('all'),
            ],
            'orders' => [
                'today' => $this->getTotalOrders('today'),
                'week' => $this->getTotalOrders('week'),
                'month' => $this->getTotalOrders('month'),
                'year' => $this->getTotalOrders('year'),
                'all_time' => $this->getTotalOrders('all'),
            ],
            'averages' => [
                'today' => $this->getAverageSalesPerOrder('today'),
                'week' => $this->getAverageSalesPerOrder('week'),
                'month' => $this->getAverageSalesPerOrder('month'),
                'year' => $this->getAverageSalesPerOrder('year'),
            ],
            'commission' => [
                'rate' => $this->commission_rate,
                'today' => $this->getCommissionAmount('today'),
                'week' => $this->getCommissionAmount('week'),
                'month' => $this->getCommissionAmount('month'),
                'year' => $this->getCommissionAmount('year'),
            ],
        ];
    }

    public function getSalesBreakdownByBranch(?string $period = 'month'): array
    {
        $breakdown = $this->salesBreakdownForPeriod($period)
            ->with(['cashierShift.shift.branch'])
            ->get()
            ->groupBy(function ($sale) {
                return $sale->cashierShift->shift->branch_id;
            });

        return $breakdown->map(function ($branchSales) {
            $branch = $branchSales->first()->cashierShift->shift->branch;
            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'total_sales' => $branchSales->sum('amount'),
                'total_orders' => $branchSales->count(),
                'average_per_order' => $branchSales->count() > 0
                    ? ($branchSales->sum('amount') / $branchSales->count())
                    : 0,
            ];
        })->sortByDesc('total_sales')->values()->toArray();
    }

    public function getSalesBreakdownByDate(?string $period = 'month'): array
    {
        $breakdown = $this->salesBreakdownForPeriod($period)
            ->with(['cashierShift'])
            ->get()
            ->groupBy(function ($sale) {
                return $sale->cashierShift->shift_date->format('Y-m-d');
            });

        return $breakdown->map(function ($dateSales, $date) {
            return [
                'date' => $date,
                'total_sales' => $dateSales->sum('amount'),
                'total_orders' => $dateSales->count(),
                'average_per_order' => $dateSales->count() > 0
                    ? ($dateSales->sum('amount') / $dateSales->count())
                    : 0,
            ];
        })->sortBy('date')->values()->toArray();
    }

    public function enableForBranch(string $branchId): void
    {
        $this->branches()->updateExistingPivot($branchId, [
            'is_enabled' => true,
            'updated_at' => now(),
        ]);
    }

    public function disableForBranch(string $branchId): void
    {
        $this->branches()->updateExistingPivot($branchId, [
            'is_enabled' => false,
            'updated_at' => now(),
        ]);
    }

    public function isEnabledForBranch(string $branchId): bool
    {
        $pivot = $this->branches()->where('branch_id', $branchId)->first();
        return $pivot ? $pivot->pivot->is_enabled : false;
    }

    // Route key name for route model binding
    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
