<?php

namespace Modules\Aggregator\Repositories;



use Modules\Aggregator\Models\Aggregator;
use Illuminate\Support\Collection;

interface AggregatorRepositoryInterface
{
    public function findById(int $id): ?Aggregator;
    public function getAll(): Collection;
    public function getActive(): Collection;
    public function getByIntegrationType(string $type): Collection;
    public function create(array $data): Aggregator;
    public function update(Aggregator $aggregator, array $data): bool;
    public function delete(Aggregator $aggregator): bool;
    public function search(string $query): Collection;
    public function hasIntegration(): Collection;
}

