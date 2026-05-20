<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\WasteDamageReportItem;

class WasteDamageReportItemRepository
{
    public function create(array $data): WasteDamageReportItem
    {
        return WasteDamageReportItem::create($data);
    }

    public function update(WasteDamageReportItem $item, array $data): bool
    {
        return $item->update($data);
    }

    public function find(string $id, array $relations = []): ?WasteDamageReportItem
    {
        $query = WasteDamageReportItem::query();

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->find($id);
    }

    public function findByIdAndReport(string $itemId, string $reportId, array $relations = []): ?WasteDamageReportItem
    {
        $query = WasteDamageReportItem::where('id', $itemId)
            ->where('waste_damage_report_id', $reportId);

        if (! empty($relations)) {
            $query->with($relations);
        }

        return $query->first();
    }

    public function delete(WasteDamageReportItem $item): bool
    {
        return $item->delete();
    }
}
