<?php

namespace Modules\RecurringOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Purchase\Models\Item;

class RecurringOrderItem extends Model
{
    use HasUuids;

    protected $table = 'recurring_order_items';

    protected $fillable = [
        'recurring_order_id',
        'item_id',
        'item_name',
        'item_logo',
        'quantity',
        'quality',
        'unit_price',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
    ];

    protected $appends = ['item_logo_url'];

    public function recurringOrder(): BelongsTo
    {
        return $this->belongsTo(RecurringOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getItemLogoUrlAttribute(): ?string
    {
        if (!$this->item_logo) {
            return $this->item?->logo_url ?? null;
        }
        return str_starts_with($this->item_logo, 'http')
            ? $this->item_logo
            : asset('storage/' . $this->item_logo);
    }
}
