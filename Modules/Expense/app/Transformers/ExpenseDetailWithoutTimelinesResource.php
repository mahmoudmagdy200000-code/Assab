<?php

namespace Modules\Expense\Transformers;

/**
 * Same as ExpenseDetailResource but omits "timelines" from the response.
 * Used for "previous" list endpoints (pre-approval/previous, single-invoice/previous).
 */
class ExpenseDetailWithoutTimelinesResource extends ExpenseDetailResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        unset($data['timelines']);

        return $data;
    }
}
