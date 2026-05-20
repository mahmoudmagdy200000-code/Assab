<?php

namespace Modules\Aggregator\Repositories;

use Illuminate\Support\Collection;
use Modules\Aggregator\Models\Aggregator;

class AggregatorRepository implements AggregatorRepositoryInterface
{
    public function findById(string $id): ?Aggregator
    {
        return Aggregator::find($id);
    }

    public function getAll(): Collection
    {
        return Aggregator::all();
    }

    public function getActive(): Collection
    {
        return Aggregator::active()->get();
    }

    public function getByIntegrationType(string $type): Collection
    {
        return Aggregator::byIntegrationType($type)->get();
    }

    public function create(array $data): Aggregator
    {
        return Aggregator::create($data);
    }

    public function update(Aggregator $aggregator, array $data): Aggregator
    {
        $aggregator->update($data);

        return $aggregator;
    }

    public function delete(Aggregator $aggregator): bool
    {
        return $aggregator->delete();
    }

    public function search(string $query): Collection
    {
        return Aggregator::search($query)->get();
    }

    public function hasIntegration(): Collection
    {
        return Aggregator::query()
            ->whereNotNull('api_key')
            ->whereNotNull('api_endpoint')
            ->get();
    }
}
