<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * asab_settings.group_key was sized for the four fixed admin buckets
 * (notifications/backup/api/security, ≤13 chars) and never revisited when the
 * branch-manager settings screen started keying rows as `branch:<uuid>` — 43
 * chars, over the original VARCHAR(32) — so every save 500'd with "Data too
 * long for column 'group_key'" (SQLSTATE 22001).
 *
 * 64 covers `branch:<uuid>` (43) plus headroom for any sibling prefix
 * (`restaurant:<uuid>` = 47, `company:<uuid>` = 44, `brand:<uuid>` = 42) —
 * a UUID is always 36 chars, so no realistic `<prefix>:<uuid>` key exceeds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asab_settings', 'group_key')) {
            return;
        }

        Schema::table('asab_settings', function (Blueprint $t) {
            $t->string('group_key', 64)->change();
        });
    }

    public function down(): void
    {
        // No-op: shrinking back to 32 would truncate every existing
        // `branch:<uuid>` row (and any other key that only fits in 64).
    }
};
