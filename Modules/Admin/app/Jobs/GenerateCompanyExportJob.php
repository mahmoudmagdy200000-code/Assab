<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Services\ExportService;
use Modules\Admin\Services\NotificationService;

/**
 * Generates a large export asynchronously (COMPANY_DASHBOARD_API_SPEC.md §5.2.10 —
 * billing/invoices/export returns 202 + jobId). The finished file is stored under
 * the company's namespace and the requester is notified (+ realtime) with a link.
 */
class GenerateCompanyExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @param  array{format?:string, from?:string|null, to?:string|null}  $params */
    public function __construct(
        public string $jobId,
        public string $companyId,
        public string $requesterUserId,
        public string $type,
        public array $params = [],
    ) {}

    public function handle(ExportService $exports, NotificationService $notify): void
    {
        $format = ($this->params['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

        $tmp = match ($this->type) {
            'billing-invoices' => $exports->billingInvoicesToFile(
                $this->companyId, $this->params['from'] ?? null, $this->params['to'] ?? null, $format
            ),
            default => throw new \InvalidArgumentException("Unknown export type: {$this->type}"),
        };

        $relative = "exports/{$this->companyId}/{$this->jobId}.{$format}";
        Storage::disk('local')->put($relative, file_get_contents($tmp));
        @unlink($tmp);

        $notify->push(
            $this->requesterUserId,
            'export.ready',
            'التصدير جاهز للتحميل',
            'اكتمل توليد ملف التصدير الخاص بك.',
            "/api/v1/company/me/exports/{$this->jobId}/download",
            ['type' => 'export', 'id' => $this->jobId],
        );
    }
}
