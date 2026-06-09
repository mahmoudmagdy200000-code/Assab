<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** GDPR/PDPL personal-data export job (FE completion request §3.4). */
class DataExportJob extends Model
{
    use HasUuids;

    protected $table = 'asab_data_export_jobs';

    protected $fillable = [
        'user_id', 'company_id', 'status', 'storage_key', 'download_url', 'expires_at', 'error',
    ];

    protected $casts = ['expires_at' => 'datetime'];
}
