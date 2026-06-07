<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\TablePref;

/**
 * Per-user persistent table column layout (MISSING_Dashboard §11.4).
 */
class TablePrefController extends AsabController
{
    public function show(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $table = (string) $request->query('table', '');
            $pref = TablePref::where('user_id', $request->user()->id)->where('table_key', $table)->first();

            return $this->ok($this->present($pref, $table));
        });
    }

    public function upsert(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'table' => 'required|string|max:80',
                'visibleColumns' => 'sometimes|array',
                'visibleColumns.*' => 'string',
                'columnOrder' => 'sometimes|array',
                'columnOrder.*' => 'string',
                'pageSize' => 'sometimes|integer|min:1|max:200',
            ]);

            $pref = TablePref::updateOrCreate(
                ['user_id' => $request->user()->id, 'table_key' => $data['table']],
                array_filter([
                    'visible_columns' => $data['visibleColumns'] ?? null,
                    'column_order' => $data['columnOrder'] ?? null,
                    'page_size' => $data['pageSize'] ?? null,
                ], fn ($v) => $v !== null),
            );

            return $this->ok($this->present($pref, $data['table']));
        });
    }

    private function present(?TablePref $pref, string $table): array
    {
        return [
            'table' => $table,
            'visibleColumns' => $pref?->visible_columns ?? [],
            'columnOrder' => $pref?->column_order ?? [],
            'pageSize' => $pref?->page_size ?? 20,
        ];
    }
}
