<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->foreignUuid('executed_by_user_id')->nullable()->after('received_by')
                ->constrained('asab_users')->restrictOnDelete();
            $table->timestamp('executed_at')->nullable()->after('executed_by_user_id');
            $table->string('transfer_reference', 120)->nullable()->after('executed_at');
            $table->string('execution_proof_path', 500)->nullable()->after('transfer_reference');
            $table->string('execution_proof_original_name', 255)->nullable()->after('execution_proof_path');
            $table->string('execution_proof_sha256', 64)->nullable()->after('execution_proof_original_name');
        });
    }

    public function down(): void
    {
        Schema::table('custody_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('executed_by_user_id');
            $table->dropColumn([
                'executed_at', 'transfer_reference', 'execution_proof_path',
                'execution_proof_original_name', 'execution_proof_sha256',
            ]);
        });
    }
};
