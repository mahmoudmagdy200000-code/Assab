<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_rate_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('notifiable');
            $table->date('date');
            $table->integer('count')->default(0);
            $table->timestamps();

            $table->unique(['notifiable_type', 'notifiable_id', 'date'], 'unique_sms_rate_limit');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_rate_limits');
    }
};
