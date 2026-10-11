<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable assertion of a physical presentation; request edits never edit this fact. */
class ShiftTransferAttempt extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $casts = ['presented_halalas' => 'integer', 'sequence' => 'integer', 'presented_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Physical transfer attempts are immutable.'));
        static::deleting(fn () => throw new LogicException('Physical transfer attempts are immutable.'));
    }
}
