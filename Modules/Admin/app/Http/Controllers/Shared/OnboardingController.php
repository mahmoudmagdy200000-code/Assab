<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\OnboardingState;

/**
 * Per-user onboarding tour state (FE completion request §2.2). Step IDs are
 * FE-defined (welcome, dashboard-tour, first-approval, …) — the backend only
 * records which were completed plus skip/complete flags.
 */
class OnboardingController extends AsabController
{
    /** GET /users/me/onboarding-state */
    public function show(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present($this->stateFor($request->user()->id))));
    }

    /** PATCH /users/me/onboarding-state */
    public function update(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'stepCompleted' => 'sometimes|string|max:64',
                'skip' => 'sometimes|boolean',
                'reset' => 'sometimes|boolean',
            ]);

            $state = $this->stateFor($request->user()->id);

            if (! empty($data['reset'])) {
                $state->fill(['completed_steps' => [], 'skipped' => false, 'completed_at' => null]);
            }
            if (! empty($data['stepCompleted'])) {
                $steps = $state->completed_steps ?? [];
                if (! in_array($data['stepCompleted'], $steps, true)) {
                    $steps[] = $data['stepCompleted'];
                }
                $state->completed_steps = array_values($steps);
            }
            if (array_key_exists('skip', $data)) {
                $state->skipped = (bool) $data['skip'];
                if ($data['skip']) {
                    $state->completed_at = $state->completed_at ?? now();
                }
            }
            $state->save();

            return $this->ok($this->present($state));
        });
    }

    private function stateFor(string $userId): OnboardingState
    {
        return OnboardingState::firstOrNew(['user_id' => $userId]);
    }

    /** @return array<string, mixed> */
    private function present(OnboardingState $s): array
    {
        return [
            'completedSteps' => $s->completed_steps ?? [],
            'skipped' => (bool) $s->skipped,
            'completedAt' => optional($s->completed_at)->toIso8601String(),
        ];
    }
}
