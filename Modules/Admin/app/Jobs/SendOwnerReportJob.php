<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Mail\OwnerReportMail;
use Modules\Admin\Services\ExportService;
use Modules\Admin\Services\ReportService;

/**
 * RPT-3 / BRO-1.2 — build a brand owner's report and email it with the rendered
 * PDF/Excel attached. Queued so a bulk send never blocks the request.
 */
class SendOwnerReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  string[]|null  $branchIds  restaurant's branches (scopes the P&L) */
    public function __construct(
        public string $reportKey,
        public string $ownerEmail,
        public ?string $from,
        public ?string $to,
        public ?array $branchIds,
        public string $format, // pdf | xlsx
        public ?string $coverMessage,
        public ?string $restaurantName,
    ) {}

    public function handle(ReportService $reports, ExportService $exports): void
    {
        $report = $reports->build([
            'reportKey' => $this->reportKey,
            'period' => ['from' => $this->from, 'to' => $this->to],
            'branchIds' => $this->branchIds,
        ]);

        $file = $exports->reportBytes($report, $this->format);

        $heading = 'تقرير: '.$this->reportKey.($this->restaurantName ? ' — '.$this->restaurantName : '');
        $body = ($this->coverMessage ? e($this->coverMessage).'<br><br>' : '').e($heading);

        Mail::to($this->ownerEmail)->send(new OwnerReportMail(
            $heading,
            $body,
            $file['contents'],
            $file['filename'],
            $file['mime'],
        ));
    }
}
