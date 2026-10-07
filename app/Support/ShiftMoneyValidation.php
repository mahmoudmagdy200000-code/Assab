<?php

namespace App\Support;

/** Validation for legacy SAR inputs; limits follow the existing Shift columns. */
final class ShiftMoneyValidation
{
    // cashier_shifts, shift_sales_breakdown, variance details and manager totals: DECIMAL(12,2).
    public const SAR = 'numeric|regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/|min:0|max:9999999999.99';

    public const SIGNED_SAR = 'numeric|regex:/\A-?[0-9]+(?:\.[0-9]{1,2})?\z/|min:-9999999999.99|max:9999999999.99';

    // branch_manager_shifts.handover_amount is DECIMAL(10,2), unlike cashier handovers.
    public const MANAGER_HANDOVER_SAR = 'numeric|regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/|min:0|max:99999999.99';
}
