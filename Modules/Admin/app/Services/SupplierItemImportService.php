<?php

namespace Modules\Admin\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\SupplierItem;

/**
 * «الأصناف والأسعار» bulk upload for the supplier portal (2026-08-04).
 *
 * The portal shipped with an Excel button and an export endpoint but no import
 * one, so the supplier could add a catalog only one row at a time — and the
 * button itself had nothing to call. This parses the same sheet the export
 * produces, so «صدّر → عدّل → ارفع» is a closed loop.
 *
 * Idempotent: a row is matched on `code` when present, else on `name`, within
 * the uploading supplier's own catalog — re-uploading an exported sheet UPDATES
 * prices instead of minting duplicates.
 */
class SupplierItemImportService
{
    /** Ratified header row — also what the template endpoint hands out. */
    public const TEMPLATE = [
        'الصنف', 'الرمز', 'الوحدة', 'السعر (ر.س)', 'الحد الأدنى', 'الحد الأقصى', 'مدة التحضير (يوم)', 'الفئة', 'متاح',
    ];

    private const ALIASES = [
        'name' => ['الصنف', 'اسم الصنف', 'المنتج', 'name', 'item', 'item name', 'product'],
        'code' => ['الرمز', 'رمز الصنف', 'كود', 'code', 'sku'],
        'unit' => ['الوحدة', 'unit', 'uom'],
        'price' => ['السعر', 'السعر (ر.س)', 'سعر', 'price', 'unit price'],
        'minQty' => ['الحد الأدنى', 'الحد الادنى', 'min', 'minqty', 'min qty'],
        'maxQty' => ['الحد الأقصى', 'الحد الاقصى', 'max', 'maxqty', 'max qty'],
        'leadTimeDays' => ['مدة التحضير (يوم)', 'مدة التحضير', 'مدة التجهيز', 'leadtime', 'lead time', 'lead time days'],
        'category' => ['الفئة', 'التصنيف', 'category'],
        'available' => ['متاح', 'الحالة', 'available', 'status'],
    ];

    public function __construct(private readonly ProcurementCatalogBridgeService $bridge) {}

    /**
     * @return array{count:int, errors:array<int, array{row:int, message:string}>}
     */
    public function import(UploadedFile $file, AsabUser $user, AsabSupplier $owner): array
    {
        [$map, $rows] = $this->parse($file);
        $count = 0;
        $errors = [];
        $items = [];

        DB::transaction(function () use ($rows, $map, $user, $owner, &$count, &$errors, &$items) {
            foreach ($rows as $i => $row) {
                try {
                    $items[] = $this->importRow($row, $map, $user, $owner);
                    $count++;
                } catch (AsabException $e) {
                    $errors[] = ['row' => $i + 2, 'message' => $e->messageAr ?? $e->getMessage()];
                } catch (\Throwable $e) {
                    report($e);
                    $errors[] = ['row' => $i + 2, 'message' => 'تعذّر حفظ الصف — راجع قيم الصف وأعد المحاولة'];
                }
            }
        });

        // The write-through to the mobile catalog runs AFTER commit: a bridge
        // failure must not cost the supplier their whole upload.
        foreach ($items as $item) {
            try {
                $this->bridge->syncItem($item);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($count === 0) {
            throw new AsabException(
                'UPLOAD_FAILED',
                'No row from the file could be stored.',
                'لم يتم حفظ أي صف من الملف. راجع الأخطاء وأعد الرفع.',
                422,
                ['errors' => $errors],
            );
        }

        return ['count' => $count, 'errors' => $errors];
    }

    /** @param  array<string, int>  $map */
    private function importRow(array $row, array $map, AsabUser $user, AsabSupplier $owner): SupplierItem
    {
        $name = $this->text($row, $map, 'name');
        if ($name === null) {
            throw new AsabException('INVALID_INPUT', 'Item name is required', 'اسم الصنف مطلوب', 422);
        }

        $code = $this->text($row, $map, 'code');
        $available = $this->toBool($this->cell($row, $map, 'available'));

        $attributes = [
            'supplier_user_id' => $user->id,
            'company_id' => $owner->company_id,
            'supplier_id' => $owner->id,
            'brand_id' => $owner->brand_id,
            'name' => $name,
            'code' => $code,
            'unit' => $this->text($row, $map, 'unit'),
            'category' => $this->text($row, $map, 'category'),
            'price' => $this->toHalalas($this->cell($row, $map, 'price')),
            'min_qty' => $this->toInt($this->cell($row, $map, 'minQty')),
            'max_qty' => $this->toInt($this->cell($row, $map, 'maxQty')),
            'lead_time_days' => $this->toInt($this->cell($row, $map, 'leadTimeDays')),
            'available' => $available,
            'status' => $available ? 'active' : 'inactive',
        ];

        // Matched inside this login's own catalog only — never across suppliers.
        $existing = SupplierItem::where('supplier_user_id', $user->id)
            ->where('supplier_id', $owner->id)
            ->when($code !== null, fn ($q) => $q->where('code', $code))
            ->when($code === null, fn ($q) => $q->whereNull('code')->where('name', $name))
            ->first();

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing->fresh();
        }

        return SupplierItem::create($attributes);
    }

    /**
     * @return array{0: array<string, int>, 1: array<int, array<int, mixed>>}
     */
    private function parse(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $reader = new \OpenSpout\Reader\XLSX\Reader;
            $reader->open($file->getRealPath());
            $rows = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                break; // first sheet only, matching the single-sheet template
            }
            $reader->close();
        } else {
            $rows = array_map('str_getcsv', file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }

        if ($rows === []) {
            throw $this->emptyFile();
        }

        $map = $this->mapHeaders(array_shift($rows));
        if (! isset($map['name'])) {
            throw new AsabException(
                'HEADER_MISMATCH',
                'The sheet must carry an item-name column.',
                'يجب أن يحتوي الملف على عمود «الصنف». نزّل القالب واستخدم رؤوس أعمدته.',
                422,
                ['expected' => self::TEMPLATE],
            );
        }

        $data = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
        if ($data === []) {
            throw $this->emptyFile();
        }

        return [$map, $data];
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<string, int>
     */
    private function mapHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $idx => $h) {
            // The BOM Excel writes ahead of the first cell would otherwise make
            // «الصنف» unmatchable in a CSV export.
            $norm = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h)));
            foreach (self::ALIASES as $field => $names) {
                if (in_array($norm, $names, true) || in_array(str_replace(' ', '', $norm), $names, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    private function emptyFile(): AsabException
    {
        return new AsabException('EMPTY_FILE', 'The file has no data rows.', 'الملف لا يحتوي على بيانات.', 422);
    }

    /** @param  array<string, int>  $map */
    private function cell(array $row, array $map, string $field): mixed
    {
        return isset($map[$field]) ? ($row[$map[$field]] ?? null) : null;
    }

    /** @param  array<string, int>  $map */
    private function text(array $row, array $map, string $field): ?string
    {
        $v = trim((string) ($this->cell($row, $map, $field) ?? ''));

        return $v === '' ? null : $v;
    }

    /** Sheet money is SAR (the column says ر.س); storage is integer halalas. */
    private function toHalalas(mixed $v): int
    {
        if ($v === null || trim((string) $v) === '') {
            return 0;
        }

        return (int) round(((float) preg_replace('/[^0-9.\-]/', '', (string) $v)) * 100);
    }

    private function toInt(mixed $v): ?int
    {
        if ($v === null || trim((string) $v) === '') {
            return null;
        }

        return (int) round((float) preg_replace('/[^0-9.\-]/', '', (string) $v));
    }

    /** «متاح»: نعم/لا, 1/0, true/false, active/inactive — blank means available. */
    private function toBool(mixed $v): bool
    {
        $s = mb_strtolower(trim((string) ($v ?? '')));
        if ($s === '') {
            return true;
        }

        return ! in_array($s, ['0', 'false', 'no', 'لا', 'غير متاح', 'inactive', 'موقوف'], true);
    }
}
