<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Initiation is immutable. Original sender may append confirmation once through the locked writer. */
class ShiftTransferReturn extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['returned_halalas' => 'integer', 'initiated_at' => 'datetime', 'sender_confirmed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row) {
            $allowed = ['sender_confirmed_by_type', 'sender_confirmed_by_id', 'sender_confirmed_at', 'updated_at'];
            if ($row->getRawOriginal('sender_confirmed_at') !== null || array_diff(array_keys($row->getDirty()), $allowed)) {
                throw new LogicException('Return evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Return evidence is immutable.'));
    }
}
