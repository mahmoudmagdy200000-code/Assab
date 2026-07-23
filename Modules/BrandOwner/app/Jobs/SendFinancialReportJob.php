<?php

namespace Modules\BrandOwner\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\BrandOwner\Mail\FinancialReportMail;

/**
 * Emails a rendered Brand Owner financial report with its file attached. Queued
 * so a send never blocks the API request. The file is read from disk inside the
 * job (the path is small and serialisable; the bytes are not).
 */
class SendFinancialReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $email,
        public string $title,
        public string $absolutePath,
        public string $filename,
        public string $mime,
    ) {}

    public function handle(): void
    {
        if (! is_file($this->absolutePath)) {
            Log::warning('Financial report attachment missing, skipping email.', [
                'path' => $this->absolutePath,
                'email' => $this->email,
            ]);

            return;
        }

        $contents = file_get_contents($this->absolutePath);

        Mail::to($this->email)->send(new FinancialReportMail(
            $this->title,
            $contents,
            $this->filename,
            $this->mime,
        ));
    }
}
