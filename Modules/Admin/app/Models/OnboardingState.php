<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Per-user onboarding tour progress (FE completion request §2.2). */
class OnboardingState extends Model
{
    use HasUuids;

    protected $table = 'asab_onboarding_states';

    protected $fillable = ['user_id', 'completed_steps', 'skipped', 'completed_at'];

    protected $casts = [
        'completed_steps' => 'array',
        'skipped' => 'boolean',
        'completed_at' => 'datetime',
    ];
}
