<?php

namespace Modules\Aggregator\Observers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Aggregator\Models\Aggregator;

class AggregatorObserver
{
    public function creating(Aggregator $aggregator): void
    {
        // Set defaults
        if (!isset($aggregator->is_active)) {
            $aggregator->is_active = true;
        }

        if (!isset($aggregator->commission_rate)) {
            $aggregator->commission_rate = 0;
        }
    }

    public function created(Aggregator $aggregator): void
    {
        Log::info('Aggregator created', [
            'aggregator_id' => $aggregator->id,
            'name' => $aggregator->name,
            'code' => $aggregator->code,
        ]);
    }

    public function updating(Aggregator $aggregator): void
    {
        // Log activation/deactivation
        if ($aggregator->isDirty('is_active')) {
            $oldActive = $aggregator->getOriginal('is_active');
            $newActive = $aggregator->is_active;

            if ($oldActive && !$newActive) {
                Log::warning('Aggregator deactivated', [
                    'aggregator_id' => $aggregator->id,
                    'name' => $aggregator->name,
                ]);
            } elseif (!$oldActive && $newActive) {
                Log::info('Aggregator activated', [
                    'aggregator_id' => $aggregator->id,
                    'name' => $aggregator->name,
                ]);
            }
        }
    }

    public function updated(Aggregator $aggregator): void
    {
        Log::info('Aggregator updated', [
            'aggregator_id' => $aggregator->id,
            'changes' => $aggregator->getChanges(),
        ]);
    }

    public function deleted(Aggregator $aggregator): void
    {
        Log::warning('Aggregator deleted', [
            'aggregator_id' => $aggregator->id,
            'name' => $aggregator->name,
        ]);

        // Delete logo
        if ($aggregator->logo) {
            Storage::disk('public')->delete($aggregator->logo);
        }
    }

    public function restored(Aggregator $aggregator): void
    {
        Log::info('Aggregator restored', [
            'aggregator_id' => $aggregator->id,
            'name' => $aggregator->name,
        ]);
    }
}
