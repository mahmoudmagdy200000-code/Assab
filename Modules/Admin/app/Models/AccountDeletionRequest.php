<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** GDPR/PDPL account-deletion request (FE completion request §3.4). */
class AccountDeletionRequest extends Model
{
    use HasUuids;

    protected $table = 'asab_account_deletion_requests';

    protected $fillable = ['user_id', 'confirm_email', 'reason', 'status', 'scheduled_for'];

    protected $casts = ['scheduled_for' => 'datetime'];
}
