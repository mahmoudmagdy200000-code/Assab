<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsRateLimit extends Model
{
    use HasUuids;

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'date',
        'count',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function canSend(int $maxPerDay = 10): bool
    {
        return $this->count < $maxPerDay;
    }

    public function incrementCount(): void
    {
        $this->increment('count');
    }
}

