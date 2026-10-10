<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireIdempotencyResponsesCommand extends Command
{
    protected $signature = 'asab:idempotency-expire-responses';

    protected $description = 'Remove expired response snapshots while retaining durable command identities';

    public function handle(): int
    {
        DB::table('asab_command_idempotency_keys')
            ->whereNotNull('response_expires_at')
            ->where('response_expires_at', '<=', now())
            ->whereNotNull('response_body')
            ->update([
                'response_body' => null,
                'response_headers' => null,
                'updated_at' => now(),
            ]);

        return self::SUCCESS;
    }
}
