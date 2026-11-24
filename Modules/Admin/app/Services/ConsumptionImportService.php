<?php

namespace Modules\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\ConsumptionDailyCoverage;
use Modules\Inventory\Models\ConsumptionDailyLine;
use Modules\Inventory\Models\ConsumptionImportBatch;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * 05-CONSUMPTION-IMPORT.md — Preview & atomic Confirm for external consumption
 * `.xlsx` files (`Coverage` + `Consumption` sheets) with revision/hash control.
 */
class ConsumptionImportService
{
    private const COVERAGE_HEADERS = [
        'branch_code',
        'business_date',
        'revision',
        'day_status',
        'includes_waste',
        'source_ref',
    ];

    private const CONSUMPTION_HEADERS = [
        'branch_code',
        'business_date',
        'item_code',
        'unit_code',
        'consumed_qty',
        'included_waste_qty',
    ];

    /**
     * Parse and validate an uploaded .xlsx file, returning a stored PREVIEW batch.
     *
     * @param  string[]|null  $allowedBranchIds
     */
    public function previewFile(
        string $filePath,
        string $fileName,
        string $companyId,
        ?array $allowedBranchIds,
        AsabUser $actor,
        string $sourceSystem = 'external_pos'
    ): ConsumptionImportBatch {
        $fileHash = hash_file('sha256', $filePath) ?: '';
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        [$coverageRows, $consumptionRows, $parseErrors] = $ext === 'csv'
            ? $this->readCsvFile($filePath)
            : $this->readXlsxSheets($filePath);

        if ($parseErrors !== []) {
            return ConsumptionImportBatch::create([
                'company_id' => $companyId,
                'source_system' => $sourceSystem,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'status' => 'REJECTED',
                'validation_errors' => $parseErrors,
                'preview_summary' => ['valid' => false, 'errorCount' => count($parseErrors)],
                'uploaded_by_id' => $actor->id,
            ]);
        }

        return $this->previewParsedRows($coverageRows, $consumptionRows, $fileName, $fileHash, $companyId, $allowedBranchIds, $actor, $sourceSystem);
    }

    /**
     * Validate normalized Coverage and Consumption rows (also usable directly in tests).
     *
     * @param  array<int, array<string, mixed>>  $coverageRows
     * @param  array<int, array<string, mixed>>  $consumptionRows
     * @param  string[]|null  $allowedBranchIds
     */
    public function previewParsedRows(
        array $coverageRows,
        array $consumptionRows,
        string $fileName,
        string $fileHash,
        string $companyId,
        ?array $allowedBranchIds,
        AsabUser $actor,
        string $sourceSystem = 'external_pos'
    ): ConsumptionImportBatch {
        $errors = [];

        $branches = Branch::where('asab_company_id', $companyId)
            ->when($allowedBranchIds !== null, fn ($q) => $q->whereIn('id', $allowedBranchIds))
            ->get();

        $branchByCode = [];
        foreach ($branches as $b) {
            foreach ([$b->code ?? null, $b->name ?? null, $b->id ?? null] as $candidate) {
                if ($candidate) {
                    $branchByCode[strtoupper(trim((string) $candidate))] = $b;
                }
            }
        }

        $itemsByCode = [];
        foreach (Item::all() as $item) {
            foreach ([$item->code ?? null, $item->sku ?? null, $item->name ?? null, $item->id ?? null] as $candidate) {
                if ($candidate) {
                    $itemsByCode[strtoupper(trim((string) $candidate))] = $item;
                }
            }
        }

        $coverageMap = [];
        $parsedCoverages = [];

        foreach ($coverageRows as $idx => $row) {
            $rowNum = $idx + 2;
            $branchCode = strtoupper(trim((string) ($row['branch_code'] ?? '')));
            $date = $this->normalizeDate($row['business_date'] ?? null);
            $revision = is_numeric($row['revision'] ?? null) ? (int) $row['revision'] : 0;
            $dayStatus = strtoupper(trim((string) ($row['day_status'] ?? '')));
            $includesWaste = $this->normalizeBool($row['includes_waste'] ?? null);
            $sourceRef = trim((string) ($row['source_ref'] ?? ''));

            $branch = $branchByCode[$branchCode] ?? null;
            if (! $branch) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'branch_code', 'reason' => "الفرع '{$branchCode}' غير موجود أو خارج نطاق صلاحيتك"];
                continue;
            }
            if ($date === null) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'business_date', 'reason' => 'تاريخ يوم العمل غير صالح'];
                continue;
            }
            if ($revision < 1) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'revision', 'reason' => 'رقم النسخة يجب أن يكون عدداً صحيحاً موجباً'];
                continue;
            }
            if (! in_array($dayStatus, ['COMPLETE', 'PARTIAL'], true)) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'day_status', 'reason' => "حالة اليوم '{$dayStatus}' يجب أن تكون COMPLETE أو PARTIAL"];
                continue;
            }
            if ($includesWaste === null) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'includes_waste', 'reason' => 'قيمة includes_waste يجب أن تكون TRUE أو FALSE'];
                continue;
            }

            $key = $branchCode.'|'.$date;
            if (isset($coverageMap[$key])) {
                $errors[] = ['sheet' => 'Coverage', 'row' => $rowNum, 'field' => 'branch_code,business_date', 'reason' => "تكرار مفتاح يوم الفرع ({$branchCode}, {$date})"];
                continue;
            }

            $entry = [
                'branchId' => $branch->id,
                'branchCode' => $branchCode,
                'branchName' => $branch->name,
                'businessDate' => $date,
                'revision' => $revision,
                'dayStatus' => $dayStatus,
                'includesWaste' => $includesWaste,
                'sourceRef' => $sourceRef,
                'lines' => [],
            ];
            $coverageMap[$key] = $entry;
            $parsedCoverages[$key] = $entry;
        }

        $lineKeys = [];
        foreach ($consumptionRows as $idx => $row) {
            $rowNum = $idx + 2;
            $branchCode = strtoupper(trim((string) ($row['branch_code'] ?? '')));
            $date = $this->normalizeDate($row['business_date'] ?? null);
            $itemCode = strtoupper(trim((string) ($row['item_code'] ?? '')));
            $unitCode = strtoupper(trim((string) ($row['unit_code'] ?? '')));
            $consumedRaw = $row['consumed_qty'] ?? null;
            $wasteRaw = $row['included_waste_qty'] ?? 0;

            $key = $branchCode.'|'.($date ?? '');
            if (! isset($coverageMap[$key])) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'branch_code,business_date', 'reason' => "صف استهلاك بلا يوم مقابل في ورقة Coverage ({$branchCode}, {$date})"];
                continue;
            }

            $item = $itemsByCode[$itemCode] ?? null;
            if (! $item) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'item_code', 'reason' => "كود الصنف '{$itemCode}' غير معرف"];
                continue;
            }

            $expectedUnit = strtoupper(trim((string) ($item->unit ?? $item->unit_of_measurement ?? '')));
            if ($expectedUnit !== '' && $unitCode !== $expectedUnit) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'unit_code', 'reason' => "وحدة الصنف '{$unitCode}' لا تطابق الوحدة المعتمدة '{$expectedUnit}'"];
                continue;
            }

            if (! is_numeric($consumedRaw) || (float) $consumedRaw < 0) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'consumed_qty', 'reason' => 'كمية الاستهلاك يجب أن تكون رقماً غير سالب'];
                continue;
            }
            if (! is_numeric($wasteRaw) || (float) $wasteRaw < 0) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'included_waste_qty', 'reason' => 'كمية الهدر المدرج يجب أن تكون رقماً غير سالب'];
                continue;
            }

            $consumedQty = round((float) $consumedRaw, 3);
            $includedWasteQty = round((float) $wasteRaw, 3);
            $cov = $coverageMap[$key];

            if (! $cov['includesWaste'] && $includedWasteQty > 0) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'included_waste_qty', 'reason' => 'يجب أن يكون الهدر المدرج 0 عندما includes_waste=FALSE'];
                continue;
            }
            if ($includedWasteQty > $consumedQty) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'included_waste_qty', 'reason' => 'الهدر المدرج يتجاوز الكمية المبلّغة'];
                continue;
            }

            $lineKey = $key.'|'.$itemCode;
            if (isset($lineKeys[$lineKey])) {
                $errors[] = ['sheet' => 'Consumption', 'row' => $rowNum, 'field' => 'item_code', 'reason' => "تكرار مفتاح الصنف ({$branchCode}, {$date}, {$itemCode})"];
                continue;
            }
            $lineKeys[$lineKey] = true;

            $parsedCoverages[$key]['lines'][] = [
                'itemId' => $item->id,
                'itemCode' => $itemCode,
                'unitCode' => $unitCode,
                'consumedQty' => $consumedQty,
                'includedWasteQty' => $includedWasteQty,
                'normalConsumedQty' => round($consumedQty - $includedWasteQty, 3),
            ];
        }

        // Check expected branch items for COMPLETE days
        foreach ($parsedCoverages as $key => &$cov) {
            $expectedItemIds = BranchItem::where('branch_id', $cov['branchId'])
                ->pluck('item_id')
                ->all();
            $presentIds = array_column($cov['lines'], 'itemId');
            $missingIds = array_values(array_diff($expectedItemIds, $presentIds));

            $cov['missingItemIds'] = $missingIds;
            $cov['contentHash'] = $this->computeContentHash($cov);

            if ($cov['dayStatus'] === 'COMPLETE' && $missingIds !== []) {
                $errors[] = [
                    'sheet' => 'Coverage',
                    'row' => 0,
                    'field' => 'day_status',
                    'reason' => "اليوم {$cov['businessDate']} للفرع {$cov['branchCode']} موسوم COMPLETE لكنه يفتقد ".count($missingIds).' صنفاً متوقعاً في التغطية',
                ];
            }

            // Check conflicts against already confirmed revisions
            $existingSameRev = ConsumptionDailyCoverage::where('company_id', $companyId)
                ->where('source_system', $sourceSystem)
                ->where('branch_id', $cov['branchId'])
                ->whereDate('business_date', $cov['businessDate'])
                ->where('revision', $cov['revision'])
                ->first();

            if ($existingSameRev && $existingSameRev->content_hash !== $cov['contentHash']) {
                $errors[] = [
                    'sheet' => 'Coverage',
                    'row' => 0,
                    'field' => 'revision',
                    'reason' => "تعارض محتوى للنسخة {$cov['revision']} لفرع {$cov['branchCode']} بتاريخ {$cov['businessDate']}",
                ];
            }

            $higherRev = ConsumptionDailyCoverage::where('company_id', $companyId)
                ->where('source_system', $sourceSystem)
                ->where('branch_id', $cov['branchId'])
                ->whereDate('business_date', $cov['businessDate'])
                ->where('status', 'CONFIRMED')
                ->where('revision', '>', $cov['revision'])
                ->exists();

            if ($higherRev) {
                $errors[] = [
                    'sheet' => 'Coverage',
                    'row' => 0,
                    'field' => 'revision',
                    'reason' => "توجد نسخة أحدث مؤكدة بالفعل لفرع {$cov['branchCode']} بتاريخ {$cov['businessDate']}",
                ];
            }
        }
        unset($cov);

        $isValid = $errors === [];
        $summary = [
            'valid' => $isValid,
            'sourceSystem' => $sourceSystem,
            'daysCount' => count($parsedCoverages),
            'completeDays' => count(array_filter($parsedCoverages, fn ($c) => $c['dayStatus'] === 'COMPLETE')),
            'partialDays' => count(array_filter($parsedCoverages, fn ($c) => $c['dayStatus'] === 'PARTIAL')),
            'linesCount' => array_sum(array_map(fn ($c) => count($c['lines']), $parsedCoverages)),
            'errorCount' => count($errors),
            'days' => array_values(array_map(fn ($c) => [
                'branchCode' => $c['branchCode'],
                'branchName' => $c['branchName'],
                'businessDate' => $c['businessDate'],
                'revision' => $c['revision'],
                'dayStatus' => $c['dayStatus'],
                'includesWaste' => $c['includesWaste'],
                'linesCount' => count($c['lines']),
                'missingCount' => count($c['missingItemIds']),
            ], $parsedCoverages)),
        ];

        return ConsumptionImportBatch::create([
            'company_id' => $companyId,
            'source_system' => $sourceSystem,
            'file_name' => $fileName,
            'file_hash' => $fileHash,
            'status' => $isValid ? 'PREVIEW' : 'REJECTED',
            'validation_errors' => $errors,
            'preview_summary' => $summary,
            'parsed_payload' => $isValid ? ['coverages' => array_values($parsedCoverages)] : null,
            'uploaded_by_id' => $actor->id,
        ]);
    }

    /**
     * Atomically confirm a valid PREVIEW batch, handling idempotency & revision replacement.
     */
    public function confirm(string $batchId, string $companyId, AsabUser $actor): ConsumptionImportBatch
    {
        return DB::transaction(function () use ($batchId, $companyId, $actor) {
            $batch = ConsumptionImportBatch::where('company_id', $companyId)
                ->whereKey($batchId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($batch->status === 'CONFIRMED') {
                return $batch;
            }
            if ($batch->status !== 'PREVIEW' || empty($batch->parsed_payload['coverages'])) {
                $errs = (array) ($batch->validation_errors ?? []);
                $hasRevConflict = collect($errs)->contains(fn ($e) => ($e['field'] ?? null) === 'revision');
                if ($hasRevConflict) {
                    throw new AsabException('REVISION_CONTENT_CONFLICT', 'Revision conflict or stale revision detected', 'تعارض محتوى أو نسخة أقدم لنفس يوم الفرع — تم رفض الملف بالكامل', 409);
                }

                throw new AsabException('IMPORT_NOT_CONFIRMABLE', 'Import batch has validation errors and cannot be confirmed', 'لا يمكن تأكيد ملف به أخطاء تحقق', 422);
            }

            $coverages = $batch->parsed_payload['coverages'];

            // Re-verify all days before applying any single row (atomic all-or-nothing)
            foreach ($coverages as $cov) {
                $existingSameRev = ConsumptionDailyCoverage::where('company_id', $companyId)
                    ->where('source_system', $batch->source_system)
                    ->where('branch_id', $cov['branchId'])
                    ->whereDate('business_date', $cov['businessDate'])
                    ->where('revision', $cov['revision'])
                    ->lockForUpdate()
                    ->first();

                if ($existingSameRev && $existingSameRev->content_hash !== $cov['contentHash']) {
                    throw new AsabException('REVISION_CONTENT_CONFLICT', 'Revision already exists with different content', 'تعارض محتوى لنفس رقم النسخة — تم رفض الملف بالكامل', 409);
                }

                $higherRev = ConsumptionDailyCoverage::where('company_id', $companyId)
                    ->where('source_system', $batch->source_system)
                    ->where('branch_id', $cov['branchId'])
                    ->whereDate('business_date', $cov['businessDate'])
                    ->where('status', 'CONFIRMED')
                    ->where('revision', '>', $cov['revision'])
                    ->exists();

                if ($higherRev) {
                    throw new AsabException('STALE_REVISION', 'A newer confirmed revision already exists', 'توجد نسخة أحدث مؤكدة بالفعل — تم رفض الملف بالكامل', 409);
                }
            }

            $confirmedAt = now();
            foreach ($coverages as $cov) {
                $existingSameRev = ConsumptionDailyCoverage::where('company_id', $companyId)
                    ->where('source_system', $batch->source_system)
                    ->where('branch_id', $cov['branchId'])
                    ->whereDate('business_date', $cov['businessDate'])
                    ->where('revision', $cov['revision'])
                    ->first();

                if ($existingSameRev && $existingSameRev->content_hash === $cov['contentHash']) {
                    // Idempotent replay of exact same revision+content
                    continue;
                }

                // Supersede older confirmed revisions for this branch+date
                ConsumptionDailyCoverage::where('company_id', $companyId)
                    ->where('source_system', $batch->source_system)
                    ->where('branch_id', $cov['branchId'])
                    ->whereDate('business_date', $cov['businessDate'])
                    ->where('status', 'CONFIRMED')
                    ->update(['status' => 'SUPERSEDED']);

                $coverage = ConsumptionDailyCoverage::create([
                    'company_id' => $companyId,
                    'source_system' => $batch->source_system,
                    'branch_id' => $cov['branchId'],
                    'branch_code' => $cov['branchCode'],
                    'business_date' => $cov['businessDate'],
                    'revision' => $cov['revision'],
                    'day_status' => $cov['dayStatus'],
                    'includes_waste' => (bool) $cov['includesWaste'],
                    'source_ref' => $cov['sourceRef'] ?: null,
                    'content_hash' => $cov['contentHash'],
                    'status' => 'CONFIRMED',
                    'batch_id' => $batch->id,
                    'confirmed_by_id' => $actor->id,
                    'confirmed_at' => $confirmedAt,
                ]);

                foreach ($cov['lines'] as $line) {
                    ConsumptionDailyLine::create([
                        'coverage_id' => $coverage->id,
                        'company_id' => $companyId,
                        'branch_id' => $cov['branchId'],
                        'business_date' => $cov['businessDate'],
                        'item_id' => $line['itemId'],
                        'item_code' => $line['itemCode'],
                        'unit_code' => $line['unitCode'],
                        'consumed_qty' => $line['consumedQty'],
                        'included_waste_qty' => $line['includedWasteQty'],
                        'normal_consumed_qty' => $line['normalConsumedQty'],
                    ]);
                }
            }

            $batch->update([
                'status' => 'CONFIRMED',
                'confirmed_by_id' => $actor->id,
                'confirmed_at' => $confirmedAt,
            ]);

            return $batch->fresh();
        });
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>} */
    private function readCsvFile(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return [[], [], [['sheet' => 'CSV', 'row' => 1, 'field' => 'file', 'reason' => 'تعذر قراءة ملف CSV']]];
        }

        $rawRows = [];
        while (($data = fgetcsv($handle)) !== false) {
            $rawRows[] = $data;
        }
        fclose($handle);

        $errors = [];
        $parsed = $this->assocRows($rawRows, self::CONSUMPTION_HEADERS, 'Consumption', $errors);
        if ($errors !== []) {
            return [[], [], $errors];
        }

        $covMap = [];
        foreach ($parsed as $row) {
            $k = strtoupper(trim((string) ($row['branch_code'] ?? ''))).'|'.(string) ($row['business_date'] ?? '');
            if (! isset($covMap[$k])) {
                $covMap[$k] = [
                    'branch_code' => $row['branch_code'] ?? null,
                    'business_date' => $row['business_date'] ?? null,
                    'revision' => $row['revision'] ?? 1,
                    'day_status' => $row['day_status'] ?? 'COMPLETE',
                    'includes_waste' => $row['includes_waste'] ?? 'TRUE',
                    'source_ref' => $row['source_ref'] ?? 'csv-import',
                ];
            }
        }

        return [array_values($covMap), $parsed, []];
    }

    /** @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>} */
    private function readXlsxSheets(string $filePath): array
    {
        $reader = new XlsxReader;
        $reader->open($filePath);

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $name = $sheet->getName();
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$name] = $rows;
        }
        $reader->close();

        $errors = [];
        if (! isset($sheets['Coverage'])) {
            $errors[] = ['sheet' => 'Coverage', 'row' => 1, 'field' => 'sheet', 'reason' => 'ورقة Coverage مفقودة'];
        }
        if (! isset($sheets['Consumption'])) {
            $errors[] = ['sheet' => 'Consumption', 'row' => 1, 'field' => 'sheet', 'reason' => 'ورقة Consumption مفقودة'];
        }
        if ($errors !== []) {
            return [[], [], $errors];
        }

        $covRows = $this->assocRows($sheets['Coverage'], self::COVERAGE_HEADERS, 'Coverage', $errors);
        $consRows = $this->assocRows($sheets['Consumption'], self::CONSUMPTION_HEADERS, 'Consumption', $errors);

        return [$covRows, $consRows, $errors];
    }

    private function assocRows(array $rawRows, array $expectedHeaders, string $sheetName, array &$errors): array
    {
        if (empty($rawRows)) {
            $errors[] = ['sheet' => $sheetName, 'row' => 1, 'field' => 'headers', 'reason' => 'الورقة فارغة'];
            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $rawRows[0]);
        foreach ($expectedHeaders as $required) {
            if (! in_array($required, $headers, true)) {
                $errors[] = ['sheet' => $sheetName, 'row' => 1, 'field' => $required, 'reason' => "العمود المطلوب '{$required}' غير موجود"];
            }
        }
        if ($errors !== []) {
            return [];
        }

        $out = [];
        foreach (array_slice($rawRows, 1) as $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $assoc[$h] = $row[$i] ?? null;
                }
            }
            $out[] = $assoc;
        }

        return $out;
    }

    private function normalizeDate(mixed $val): ?string
    {
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d');
        }
        if (is_string($val) && trim($val) !== '') {
            try {
                return Carbon::parse(trim($val))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function normalizeBool(mixed $val): ?bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (int) $val === 1 ? true : ((int) $val === 0 ? false : null);
        }
        if (is_string($val)) {
            $s = strtoupper(trim($val));
            if (in_array($s, ['TRUE', '1', 'YES'], true)) {
                return true;
            }
            if (in_array($s, ['FALSE', '0', 'NO'], true)) {
                return false;
            }
        }

        return null;
    }

    private function computeContentHash(array $cov): string
    {
        $lines = $cov['lines'];
        usort($lines, fn ($a, $b) => strcmp($a['itemCode'], $b['itemCode']));
        $canonical = [
            'branchCode' => $cov['branchCode'],
            'businessDate' => $cov['businessDate'],
            'dayStatus' => $cov['dayStatus'],
            'includesWaste' => $cov['includesWaste'],
            'sourceRef' => $cov['sourceRef'],
            'lines' => array_map(fn ($l) => [
                'itemCode' => $l['itemCode'],
                'unitCode' => $l['unitCode'],
                'consumedQty' => $l['consumedQty'],
                'includedWasteQty' => $l['includedWasteQty'],
            ], $lines),
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE));
    }
}
