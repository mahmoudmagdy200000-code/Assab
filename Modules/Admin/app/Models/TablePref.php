<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user persistent table column layout (MISSING_Dashboard §11.4). The DB
 * column is `table_key` ('table' is a SQL reserved word); the API uses `table`.
 */
class TablePref extends Model
{
    use HasUuids;

    protected $table = 'asab_table_prefs';

    protected $fillable = ['user_id', 'table_key', 'visible_columns', 'column_order', 'page_size'];

    protected $casts = [
        'visible_columns' => 'array',
        'column_order' => 'array',
        'page_size' => 'integer',
    ];
}
