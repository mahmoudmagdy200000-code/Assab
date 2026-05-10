<?php

namespace Modules\FixedAssets\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\FixedAssets\Enums\ModificationDoneAction as ActionEnum;

class ModificationDoneAction extends Model
{
    use HasUuids;

    protected $table = 'fixed_asset_modification_done_actions';

    protected $fillable = [
        'modification_request_id',
        'action',
    ];

    protected $casts = [
        'action' => ActionEnum::class,
    ];

    public function modificationRequest(): BelongsTo
    {
        return $this->belongsTo(ModificationRequest::class, 'modification_request_id');
    }
}
