<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up()
{
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
