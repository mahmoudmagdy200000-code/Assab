<?php

namespace Modules\Expense\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expense Timeline Resource
 */
class ExpenseTimelineResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'action' => [
                'value' => $this->action,
                'label' => $this->getActionLabel(),
            ],
            'status' => [
                'value' => $this->status,
                'label' => ucfirst($this->status),
            ],
            'performed_by' => [
                'id' => $this->performed_by,
                'name' => $this->getPerformerName(),
                'type' => $this->performed_by_type,
                'image' => $this->getPerformerImage(),
            ],
            'notes' => $this->notes,
            'timestamp' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }

    private function getActionLabel(): string
    {
        return match($this->action) {
            'created' => 'Created',
            'updated' => 'Updated',
            'submit' => 'Submitted',
            'view' => 'Viewed',
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'resubmit' => 'Resubmitted',
            'edit' => 'Edited',
            default => ucfirst($this->action),
        };
    }

    private function getPerformerName(): string
    {
        if ($this->performed_by_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($this->performed_by);
            return $manager?->name ?? 'Unknown';
        }

        if ($this->performed_by_type === 'brand_owner') {
            // TODO: Implement BrandOwner model
            return 'Brand Owner';
        }

        return 'System';
    }

    private function getPerformerImage(): ?string
    {
        if ($this->performed_by_type === 'branch_manager') {
            $manager = \Modules\BranchManagers\Models\BranchManager::find($this->performed_by);
            return $manager?->image;
        }

        return null;
    }
}
