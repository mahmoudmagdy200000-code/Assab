<?php

namespace Modules\Aggregator\Repositories;

use Illuminate\Support\Collection;
use Modules\Aggregator\Models\Aggregator;

interface AggregatorRepositoryInterface
{
    public function findById(string $id): ?Aggregator;

    public function getAll(): Collection;

    public function getActive(): Collection;

    public function getByIntegrationType(string $type): Collection;

    public function create(array $data): Aggregator;

    public function update(Aggregator $aggregator, array $data): Aggregator;

    public function delete(Aggregator $aggregator): bool;

    public function search(string $query): Collection;

    public function hasIntegration(): Collection;
}
