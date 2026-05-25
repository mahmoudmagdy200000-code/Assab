<?php

namespace Modules\FixedAssets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Enums\RequestStatus;
use Modules\FixedAssets\Enums\TimelineEventType;
use Modules\FixedAssets\Enums\TransferDisposalKind;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\Timeline;
use Modules\FixedAssets\Models\TransferDisposalItem;
use Modules\FixedAssets\Models\TransferDisposalRequest;

class DashboardTransferDemoSeeder extends Seeder
{
    public function run(): void
    {
        $manager = BranchManager::query()->where('email', 'manager@assab.com')->first()
            ?? BranchManager::query()->first();

        if (! $manager) {
            $this->command?->warn('No branch manager found.');

            return;
        }

        $branch = Branch::query()->find($manager->branch_id);
        if (! $branch) {
            $this->command?->warn('Branch not found.');

            return;
        }

        $sourceBranch = Branch::query()->where('id', '!=', $branch->id)->first() ?? $branch;

        $assets = FixedAsset::query()
            ->where('branch_id', $sourceBranch->id)
            ->take(2)
            ->get();

        if ($assets->isEmpty()) {
            $this->command?->warn('No assets found for source branch.');

            return;
        }

        $alreadySeeded = TransferDisposalRequest::query()
            ->where('recipient_branch_id', $branch->id)
            ->where('kind', TransferDisposalKind::EXTERNAL_TRANSFER->value)
            ->exists();

        if ($alreadySeeded) {
            $this->command?->info('Dashboard transfer already seeded.');

            return;
        }

        $sourceManager = BranchManager::query()->where('branch_id', $sourceBranch->id)->first() ?? $manager;

        $request = TransferDisposalRequest::create([
            'kind' => TransferDisposalKind::EXTERNAL_TRANSFER->value,
            'branch_id' => $sourceBranch->id,
            'requested_by_id' => $sourceManager->id,
            'recipient_branch_id' => $branch->id,
            'auto_approve' => false,
            'direction' => null,
            'status' => RequestStatus::PENDING->value,
            'approved_at' => null,
        ]);

        foreach ($assets as $asset) {
            TransferDisposalItem::create([
                'request_id' => $request->id,
                'asset_id' => $asset->id,
                'transfer_reason' => 'Initiated from dashboard for redistribution.',
                'status' => RequestStatus::PENDING->value,
            ]);
        }

        Timeline::create([
            'timelineable_type' => $request->getMorphClass(),
            'timelineable_id' => $request->id,
            'event_type' => TimelineEventType::SUBMITTED->value,
            'name' => 'Transfer initiated from dashboard',
            'actor_image_path' => $sourceManager->image,
            'actor_id' => $sourceManager->id,
            'occurred_at' => now()->subHours(2),
        ]);

        $this->command?->info('Dashboard transfer seeded to branch: '.$branch->name.' from '.$sourceBranch->name);
    }
}
