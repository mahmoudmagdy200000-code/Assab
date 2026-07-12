<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SRS §5.2b — dashboard-created purchase orders used to stamp the `origin`
 * column with `mobile` while recording the real source only inside the payload
 * JSON, so every procurement order rendered as «📱 تطبيق الفرع».
 *
 * The payload is decoded in PHP rather than queried with a JSON path so the
 * backfill behaves identically on MySQL and the SQLite test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('asab_operations')
            ->where('module_key', 'purchases')
            ->where('origin', 'mobile')
            ->select('id', 'payload')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                $ids = [];
                foreach ($rows as $row) {
                    $payload = json_decode((string) $row->payload, true);
                    if (is_array($payload) && ($payload['origin'] ?? null) === 'procurement') {
                        $ids[] = $row->id;
                    }
                }
                if ($ids !== []) {
                    DB::table('asab_operations')->whereIn('id', $ids)->update(['origin' => 'procurement']);
                }
            });
    }

    public function down(): void
    {
        // The pre-migration value is indistinguishable from a genuine mobile
        // upload, so reverting would corrupt correctly-stamped rows.
    }
};
