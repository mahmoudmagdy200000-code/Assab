<?php

namespace Modules\Admin\Services;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ReportDefinition;

/**
 * Self-serve report builder over the operations table (FE completion request
 * §2.4). Dimensions/metrics/filters are a fixed, whitelisted catalog so no
 * user-supplied SQL ever reaches the query. Results are capped at 1000 rows.
 */
class ReportBuilderService
{
    private const ROW_CAP = 1000;

    /** key => [col, labelAr, labelEn, type] */
    private const DIMENSIONS = [
        'moduleKey' => ['col' => 'module_key', 'labelAr' => 'الوحدة', 'labelEn' => 'Module', 'type' => 'enum'],
        'branchId' => ['col' => 'branch_id', 'labelAr' => 'الفرع', 'labelEn' => 'Branch', 'type' => 'string'],
        'status' => ['col' => 'status', 'labelAr' => 'الحالة', 'labelEn' => 'Status', 'type' => 'enum'],
        'origin' => ['col' => 'origin', 'labelAr' => 'المصدر', 'labelEn' => 'Origin', 'type' => 'enum'],
        'operationDate' => ['col' => 'operation_date', 'labelAr' => 'التاريخ', 'labelEn' => 'Date', 'type' => 'date'],
    ];

    /** key => [agg, col, labelAr, labelEn] */
    private const METRICS = [
        'totalAmount' => ['agg' => 'SUM', 'col' => 'amount', 'labelAr' => 'إجمالي المبلغ', 'labelEn' => 'Total Amount'],
        'count' => ['agg' => 'COUNT', 'col' => '*', 'labelAr' => 'عدد العمليات', 'labelEn' => 'Count'],
        'avgAmount' => ['agg' => 'AVG', 'col' => 'amount', 'labelAr' => 'متوسط المبلغ', 'labelEn' => 'Avg Amount'],
    ];

    private const FILTERS = [
        'moduleKey' => ['col' => 'module_key', 'labelAr' => 'الوحدة', 'type' => 'enum',
            'options' => ['sales', 'expenses', 'purchases', 'inventory', 'waste', 'assets', 'custody']],
        'status' => ['col' => 'status', 'labelAr' => 'الحالة', 'type' => 'enum',
            'options' => ['pending', 'approved', 'final-approved', 'rejected']],
        'branchId' => ['col' => 'branch_id', 'labelAr' => 'الفرع', 'type' => 'string'],
    ];

    /** GET fields — the buildable catalog. */
    public function fields(): array
    {
        return [
            'dimensions' => array_map(fn ($k, $d) => [
                'key' => $k, 'labelAr' => $d['labelAr'], 'labelEn' => $d['labelEn'], 'type' => $d['type'],
            ], array_keys(self::DIMENSIONS), self::DIMENSIONS),
            'metrics' => array_map(fn ($k, $m) => [
                'key' => $k, 'labelAr' => $m['labelAr'], 'labelEn' => $m['labelEn'], 'aggregation' => strtolower($m['agg']),
            ], array_keys(self::METRICS), self::METRICS),
            'filters' => array_map(fn ($k, $f) => array_filter([
                'key' => $k, 'labelAr' => $f['labelAr'], 'type' => $f['type'], 'options' => $f['options'] ?? null,
            ], fn ($v) => $v !== null), array_keys(self::FILTERS), self::FILTERS),
        ];
    }

    /**
     * POST preview — grouped aggregate over the (tenant-scoped) operations table.
     *
     * @param  array{dimensions?:array, metrics?:array, filters?:array, dateRange?:array}  $def
     */
    public function preview(array $def): array
    {
        $dimensions = $this->whitelist($def['dimensions'] ?? [], array_keys(self::DIMENSIONS), 'dimension');
        $metrics = $this->whitelist($def['metrics'] ?? [], array_keys(self::METRICS), 'metric');
        if (empty($metrics)) {
            $metrics = ['count'];
        }

        $select = [];
        foreach ($dimensions as $d) {
            $select[] = self::DIMENSIONS[$d]['col'].' as '.$d;
        }
        foreach ($metrics as $m) {
            $select[] = self::METRICS[$m]['agg'].'('.self::METRICS[$m]['col'].') as '.$m;
        }

        $query = $this->applyFilters(Operation::query(), $def);
        if ($dimensions) {
            $query->groupBy(array_map(fn ($d) => self::DIMENSIONS[$d]['col'], $dimensions));
        }

        $results = $query->selectRaw(implode(', ', $select))->limit(self::ROW_CAP)->get();

        $rows = $results->map(function ($r) use ($dimensions, $metrics) {
            $row = [];
            foreach ($dimensions as $d) {
                $row[$d] = $r->{$d};
            }
            foreach ($metrics as $m) {
                $row[$m] = $this->castMetric($m, $r->{$m});
            }

            return $row;
        })->all();

        return [
            'rows' => $rows,
            'totals' => $this->totals($def, $metrics),
            'rowCount' => count($rows),
            'capped' => count($rows) >= self::ROW_CAP,
        ];
    }

    public function save(array $payload, string $companyId, string $userId): ReportDefinition
    {
        return ReportDefinition::create([
            'company_id' => $companyId,
            'created_by_id' => $userId,
            'name' => $payload['name'],
            'description_ar' => $payload['descriptionAr'] ?? null,
            'definition' => $payload['definition'],
        ]);
    }

    /** Saved definitions for the current tenant (BelongsToTenant scopes company). */
    public function saved(int $perPage, int $page): \Illuminate\Pagination\LengthAwarePaginator
    {
        return ReportDefinition::query()->orderByDesc('created_at')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Replay a saved definition through preview(). findOrFail is tenant-scoped
     * (BelongsToTenant) so another company's id yields a 404, not a leak.
     */
    public function run(string $id): array
    {
        $definition = ReportDefinition::findOrFail($id);

        return $this->preview($definition->definition ?? []);
    }

    /** Grand totals for each metric across the whole filtered set (no grouping). */
    private function totals(array $def, array $metrics): array
    {
        $select = array_map(fn ($m) => self::METRICS[$m]['agg'].'('.self::METRICS[$m]['col'].') as '.$m, $metrics);
        $row = $this->applyFilters(Operation::query(), $def)->selectRaw(implode(', ', $select))->first();

        $totals = [];
        foreach ($metrics as $m) {
            $totals[$m] = $this->castMetric($m, $row?->{$m});
        }

        return $totals;
    }

    private function applyFilters($query, array $def)
    {
        foreach (($def['filters'] ?? []) as $key => $value) {
            if (! isset(self::FILTERS[$key]) || $value === null || $value === '') {
                continue;
            }
            $col = self::FILTERS[$key]['col'];
            is_array($value) ? $query->whereIn($col, $value) : $query->where($col, $value);
        }
        $range = $def['dateRange'] ?? [];
        if (! empty($range['from'])) {
            $query->whereDate('operation_date', '>=', $range['from']);
        }
        if (! empty($range['to'])) {
            $query->whereDate('operation_date', '<=', $range['to']);
        }

        return $query;
    }

    private function castMetric(string $metric, $value): int|float
    {
        return $metric === 'avgAmount' ? round((float) $value, 2) : (int) $value;
    }

    /** @return string[] */
    private function whitelist(array $requested, array $allowed, string $kind): array
    {
        $clean = array_values(array_intersect($requested, $allowed));
        $unknown = array_diff($requested, $allowed);
        if ($unknown) {
            throw new AsabException('VALIDATION_ERROR', 'Unknown '.$kind, 'حقل غير معروف', 422, [
                $kind => array_values($unknown),
            ]);
        }

        return $clean;
    }
}
