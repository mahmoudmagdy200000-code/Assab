<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\SavedFilter;

/**
 * Per-user saved filter presets (MISSING_Dashboard §11.1). Scoped to the
 * authenticated user; never trusts a user_id from the client.
 */
class SavedFilterController extends AsabController
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $q = SavedFilter::where('user_id', $request->user()->id);
            if ($page = $request->query('page')) {
                $q->where('page', $page);
            }

            return $this->listResponse($q->orderByDesc('created_at')->limit(200)->get()->map([$this, 'present'])->all());
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:120',
                'page' => 'required|string|max:80',
                'params' => 'sometimes|array',
            ]);
            $filter = SavedFilter::create([
                'user_id' => $request->user()->id,
                'name' => $data['name'],
                'page' => $data['page'],
                'params' => $data['params'] ?? [],
                'created_at' => now(),
            ]);

            return $this->created($this->present($filter));
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            SavedFilter::where('user_id', $request->user()->id)->findOrFail($id)->delete();

            return $this->noContent();
        });
    }

    public function present(SavedFilter $f): array
    {
        return [
            'id' => $f->id,
            'name' => $f->name,
            'page' => $f->page,
            'params' => $f->params ?? [],
            'createdAt' => optional($f->created_at)->toIso8601String(),
        ];
    }
}
