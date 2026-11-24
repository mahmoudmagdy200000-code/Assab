<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InternalTransferVariance extends Model
{
    use HasUuids;

    protected $table = 'internal_transfer_variances';

    protected $fillable = [
        'purchase_order_id',
        'purchase_order_item_id',
        'goods_receipt_id',
        'from_branch_id',
        'to_branch_id',
        'item_id',
        'dispatched_qty',
        'received_qty',
        'in_transit_qty',
        'variance_qty',
        'sender_unit_cost_halalas',
        'resolution',
        'status',
        'reason',
        'evidence_path',
        'idempotency_key',
        'closed_by_id',
        'closed_at',
    ];

    protected $casts = [
        'dispatched_qty' => 'decimal:3',
        'received_qty' => 'decimal:3',
        'in_transit_qty' => 'decimal:3',
        'variance_qty' => 'decimal:3',
        'sender_unit_cost_halalas' => 'integer',
        'closed_at' => 'datetime',
    ];
}
