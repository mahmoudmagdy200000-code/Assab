<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
{
    $driver = DB::getDriverName();

    // SQLite doesn't support MODIFY COLUMN or ENUM
    // SQLite uses TEXT for enum-like columns, so this migration is not needed
    if ($driver === 'sqlite') {
        return;
    }

    DB::statement("
        ALTER TABLE expense_timelines
        MODIFY COLUMN action
        ENUM(
            'created',
            'updated',
            'submit',
            'view',
            'approve',
            'reject',
            'resubmit',
            'edit',
            'attachments_added',
            'attachments_deleted'
        ) NOT NULL
    ");
}

public function down()
{
    $driver = DB::getDriverName();

    // SQLite doesn't support MODIFY COLUMN or ENUM
    if ($driver === 'sqlite') {
        return;
    }

    DB::statement("
        ALTER TABLE expense_timelines
        MODIFY COLUMN action
        ENUM(
            'created',
            'updated',
            'submit',
            'view',
            'approve',
            'reject',
            'resubmit',
            'edit'
        ) NOT NULL
    ");
}

};
