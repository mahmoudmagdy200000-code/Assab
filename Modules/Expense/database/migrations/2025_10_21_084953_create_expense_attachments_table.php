<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignId('invoice_detail_id')->nullable()->constrained('invoice_details')->cascadeOnDelete();

            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('file_type', 50);
            $table->unsignedBigInteger('file_size');

            $table->timestamps();

            $table->index('expense_id');
            $table->index('invoice_detail_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
    }
};
