<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One persisted recipient of an admin report send (BACKEND_API_SPEC.md §1.7a).
 */
class ReportDistribution extends Model
{
    use HasUuids;

    protected $table = 'asab_report_distributions';

    protected $fillable = [
        'company_id', 'report_key', 'restaurant_id', 'channels', 'format', 'cover_message',
        'period_from', 'period_to', 'sent', 'sent_at', 'sent_by_id', 'viewed', 'viewed_at',
    ];

    protected $casts = [
        'channels' => 'array',
        'sent' => 'boolean',
        'sent_at' => 'datetime',
        'viewed' => 'boolean',
        'viewed_at' => 'datetime',
    ];
}
