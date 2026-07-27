<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Any of the 7 authenticatable models (AsabUser, BrandOwner, Cashier,
            // BranchManager, Supplier, SupplierUser, User).
            $table->uuidMorphs('notifiable');

            // FCM registration tokens have no documented upper bound and today run
            // past 300 chars, so the raw value lives in TEXT and uniqueness is
            // enforced on a sha256 digest — a VARCHAR unique index would either
            // truncate the token or blow the InnoDB key-length limit.
            $table->text('token');
            $table->char('token_hash', 64)->unique();

            $table->string('platform', 16);           // android | ios | web
            $table->string('app', 16)->default('mobile'); // mobile | dashboard
            $table->string('locale', 8)->default('en');
            $table->string('device_id')->nullable();  // vendor/install id, for de-dupe per device
            $table->string('device_name')->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            // Deliberately no soft deletes: `token_hash` is uniquely indexed and
            // the index ignores deleted_at, so a soft-deleted row would keep its
            // slot forever and the next registration of that token — the normal
            // case when a handset changes hands — would fail on the constraint.
            // A revoked device token also has no audit value.

            // uuidMorphs already indexes (notifiable_type, notifiable_id).
            $table->index(['notifiable_type', 'notifiable_id', 'platform'], 'device_tokens_owner_platform_idx');
            $table->index(['notifiable_type', 'notifiable_id', 'device_id'], 'device_tokens_owner_device_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
