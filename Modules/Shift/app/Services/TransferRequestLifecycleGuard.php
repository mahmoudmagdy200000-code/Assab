<?php

namespace Modules\Shift\Services;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TransferRequestLifecycleGuard
{
    /**
     * Asserts that the given transfer request is neither cancelled nor superseded.
     *
     * @throws ConflictHttpException
     */
    public static function assertActive(Model $request): void
    {
        if ((method_exists($request, 'isCancelled') && $request->isCancelled()) || ! empty($request->cancelled_at)) {
            throw new ConflictHttpException('HANDOVER_CANCELLED');
        }

        if ((method_exists($request, 'isSuperseded') && $request->isSuperseded()) || ! empty($request->superseded_at)) {
            throw new ConflictHttpException('HANDOVER_SUPERSEDED');
        }
    }
}
