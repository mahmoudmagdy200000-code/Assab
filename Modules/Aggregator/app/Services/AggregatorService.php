<?php

namespace Modules\Aggregator\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Repositories\AggregatorRepositoryInterface;

class AggregatorService
{
    public function __construct(
        private AggregatorRepositoryInterface $aggregatorRepository
    ) {}

    /**
     * Get aggregators with filters
     */
    public function getAggregators(array $filters = []): LengthAwarePaginator
    {
        $query = Aggregator::query();

        // Apply filters
        if (! empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (! empty($filters['integration_type'])) {
            $query->where('integration_type', $filters['integration_type']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['has_integration'])) {
            if ($filters['has_integration'] === 'true') {
                $query->hasIntegration();
            }
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'name';
        $sortOrder = $filters['sort_order'] ?? 'asc';
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $filters['per_page'] ?? 15;

        return $query->paginate($perPage);
    }

    /**
     * Create new aggregator
     */
    public function createAggregator(array $data): Aggregator
    {
        DB::beginTransaction();
        try {
            // Generate unique code if not provided
            if (empty($data['code'])) {
                $data['code'] = $this->generateUniqueCode($data['name']);
            }

            // Handle logo upload
            if (isset($data['logo']) && $data['logo'] instanceof UploadedFile) {
                $data['logo'] = $this->uploadLogo(null, $data['logo']);
            }

            $aggregator = Aggregator::create($data);

            DB::commit();

            return $aggregator->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update aggregator
     */
    public function updateAggregator(Aggregator $aggregator, array $data): Aggregator
    {
        DB::beginTransaction();
        try {
            // Handle logo upload
            if (isset($data['logo']) && $data['logo'] instanceof UploadedFile) {
                // Delete old logo
                if ($aggregator->logo) {
                    Storage::disk('public')->delete($aggregator->logo);
                }
                $data['logo'] = $this->uploadLogo($aggregator, $data['logo']);
            }

            $aggregator->update($data);

            DB::commit();

            return $aggregator->fresh();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Delete aggregator
     */
    public function deleteAggregator(Aggregator $aggregator): bool
    {
        DB::beginTransaction();
        try {
            // Delete logo
            if ($aggregator->logo) {
                Storage::disk('public')->delete($aggregator->logo);
            }

            // Soft delete aggregator
            $aggregator->delete();

            DB::commit();

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get aggregator details with relationships
     */
    public function getAggregatorDetails(string $aggregatorId): Aggregator
    {
        return Aggregator::with([
            'branches',
            'enabledBranches',
            'salesBreakdown' => function ($query) {
                $query->whereHas('cashierShift', function ($q) {
                    $q->whereDate('shift_date', '>=', now()->subDays(30));
                })->limit(50);
            },
        ])->findOrFail($aggregatorId);
    }

    /**
     * Upload aggregator logo
     */
    public function uploadLogo(?Aggregator $aggregator, UploadedFile $logo): string
    {
        $filename = 'aggregator_'.($aggregator?->id ?? 'new').'_'.time().'.'.$logo->getClientOriginalExtension();

        return $logo->storeAs('aggregators', $filename, 'public');
    }

    /**
     * Generate unique code from name
     */
    private function generateUniqueCode(string $name): string
    {
        $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 10));
        $counter = 1;
        $originalCode = $code;

        while (Aggregator::where('code', $code)->exists()) {
            $code = $originalCode.$counter;
            $counter++;
        }

        return $code;
    }

    /**
     * Search aggregators
     */
    public function searchAggregators(string $search): Collection
    {
        return Aggregator::search($search)
            ->active()
            ->limit(10)
            ->get();
    }
}
