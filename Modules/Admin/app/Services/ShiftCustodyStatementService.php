<?php

namespace Modules\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;

/**
 * Service providing the Shift Custody Statement (P2 / G2).
 * Strictly read-only (zero writes, zero locks, zero mutations).
 */
class ShiftCustodyStatementService
{
    public function __construct(
        private readonly IdentityMapService $identityMap,
    ) {}

    /**
     * Compute and format the shift custody statement for an employee.
     *
     * @return array<string, mixed>
     */
    public function getStatement(
        Employee $employee,
        ?string $from = null,
        ?string $to = null,
        int $page = 1,
        int $pageSize = 20,
    ): array {
        $pageSize = max(1, min($pageSize, 100));
        $page = max(1, $page);

        $branchName = null;
        if ($employee->branch_id) {
            $branchName = Branch::withoutGlobalScopes()->where('id', $employee->branch_id)->value('name');
        }

        // 1. Resolve identity links
        $identity = $this->resolveIdentity($employee);
        if (! $identity['is_linked']) {
            return [
                'unit' => 'halala',
                'identity_link_state' => 'unlinked',
                'identity' => [
                    'employee_id' => (string) $employee->id,
                    'name' => $employee->name,
                    'role' => $employee->role,
                    'linked_type' => null,
                    'linked_id' => null,
                    'branch_id' => $employee->branch_id ? (string) $employee->branch_id : null,
                    'branch_name' => $branchName,
                ],
                'period' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'balances' => [
                    'is_available' => false,
                    'opening_balance' => null,
                    'closing_balance' => null,
                    'total_cash_in' => null,
                    'total_cash_out' => null,
                    'net_period_change' => null,
                ],
                'entries' => [],
                'pending' => [],
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => 0,
                    'totalPages' => 0,
                ],
            ];
        }

        $cashierId = $identity['cashier_id'];
        $managerId = $identity['manager_id'];
        $linkedType = $identity['linked_type'];
        $linkedId = $identity['linked_id'];

        // 2. Query confirmed custody transactions
        $rawEntries = [];

        if ($cashierId) {
            $cashierTxns = CashierCustodyTransaction::query()
                ->where('cashier_id', $cashierId)
                ->whereNotIn('transaction_type', ['Variance', 'Shortage'])
                ->with(['receipt.handover.cashierShift.shift', 'receipt.managerTransfer.sourceWorkday', 'receipt.managerTransfer.destinationCashierShift'])
                ->get();

            foreach ($cashierTxns as $tx) {
                $rawEntries[] = $this->mapCashierTransaction($tx, $employee, $branchName);
            }
        }

        if ($managerId) {
            $managerTxns = PersonalLedgerTransaction::query()
                ->where('branch_manager_id', $managerId)
                ->whereNotIn('transaction_type', ['Variance from Cashier', 'Manager Variance', 'Variance'])
                ->with(['receipt.handover.cashierShift.shift', 'receipt.managerTransfer.sourceWorkday', 'receipt.managerTransfer.destinationCashierShift'])
                ->get();

            foreach ($managerTxns as $tx) {
                $rawEntries[] = $this->mapManagerTransaction($tx, $employee, $branchName);
            }
        }

        // 3. Query pending transfer requests (outside balances)
        $pending = $this->queryPendingRequests($cashierId, $managerId);

        // 4. Stable Chronological Ordering (MT-17)
        usort($rawEntries, function (array $a, array $b) {
            $tA = strtotime($a['event_at']);
            $tB = strtotime($b['event_at']);
            if ($tA !== $tB) {
                return $tA <=> $tB;
            }
            if ($a['source_type'] !== $b['source_type']) {
                return strcmp($a['source_type'], $b['source_type']);
            }

            return strcmp((string) $a['source_id'], (string) $b['source_id']);
        });

        // 5. Partition by period and calculate opening, closing, and running balances
        $fromTs = $from ? strtotime($from.' 00:00:00') : null;
        $toTs = $to ? strtotime($to.' 23:59:59') : null;

        $openingBalance = 0;
        $inPeriodEntries = [];

        foreach ($rawEntries as $entry) {
            $eTs = strtotime($entry['event_at']);
            if ($fromTs !== null && $eTs < $fromTs) {
                $openingBalance += ($entry['cash_in'] - $entry['cash_out']);
            } elseif ($toTs !== null && $eTs > $toTs) {
                continue;
            } else {
                $inPeriodEntries[] = $entry;
            }
        }

        $running = $openingBalance;
        $totalCashIn = 0;
        $totalCashOut = 0;

        foreach ($inPeriodEntries as &$entry) {
            $running += ($entry['cash_in'] - $entry['cash_out']);
            $entry['running_balance'] = $running;
            $totalCashIn += $entry['cash_in'];
            $totalCashOut += $entry['cash_out'];
        }
        unset($entry);

        $closingBalance = $running;
        $netPeriodChange = $totalCashIn - $totalCashOut;

        // 6. Pagination slice
        $total = count($inPeriodEntries);
        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
        $offset = ($page - 1) * $pageSize;
        $pagedEntries = array_slice($inPeriodEntries, $offset, $pageSize);

        return [
            'unit' => 'halala',
            'identity_link_state' => 'linked',
            'identity' => [
                'employee_id' => (string) $employee->id,
                'name' => $employee->name,
                'role' => $employee->role,
                'linked_type' => $linkedType,
                'linked_id' => $linkedId,
                'branch_id' => $employee->branch_id ? (string) $employee->branch_id : null,
                'branch_name' => $branchName,
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
            'balances' => [
                'is_available' => true,
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'total_cash_in' => $totalCashIn,
                'total_cash_out' => $totalCashOut,
                'net_period_change' => $netPeriodChange,
            ],
            'entries' => $pagedEntries,
            'pending' => $pending,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ];
    }

    /**
     * Resolve identity mapping through IdentityMapService and legacy linkage.
     *
     * @return array{is_linked: bool, cashier_id: ?string, manager_id: ?string, linked_type: ?string, linked_id: ?string}
     */
    private function resolveIdentity(Employee $employee): array
    {
        $cashierId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_CASHIER)
            ->where('dashboard_id', $employee->id)
            ->value('legacy_id');

        if (! $cashierId && $employee->legacy_cashier_id) {
            $cashierId = (string) $employee->legacy_cashier_id;
        }

        // Validate cashier belongs to branch and company if present
        if ($cashierId) {
            $cashier = Cashier::withTrashed()->where('id', $cashierId)->first();
            if (! $cashier) {
                $cashierId = null;
            } elseif ($employee->branch_id && (string) $cashier->branch_id !== (string) $employee->branch_id) {
                $cashierId = null;
            } elseif ($employee->company_id && $cashier->branch_id) {
                $branchBelongsToCompany = Branch::withoutGlobalScopes()
                    ->where('id', $cashier->branch_id)
                    ->where(function ($q) use ($employee) {
                        $q->where('asab_company_id', $employee->company_id);
                        if (Schema::hasColumn('branches', 'company_id')) {
                            $q->orWhere('company_id', $employee->company_id);
                        }
                    })
                    ->exists();
                if (! $branchBelongsToCompany) {
                    $cashierId = null;
                }
            }
        }

        $managerId = null;
        if ($employee->asab_user_id) {
            $managerId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRANCH_MANAGER)
                ->where('dashboard_id', $employee->asab_user_id)
                ->value('legacy_id');
        }

        if (! $managerId) {
            $managerId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRANCH_MANAGER)
                ->where('dashboard_id', $employee->id)
                ->value('legacy_id');
        }

        // Validate manager belongs to employee branch and company
        if ($managerId) {
            $manager = BranchManager::withTrashed()->where('id', $managerId)->first();
            if (! $manager) {
                $managerId = null;
            } elseif ($employee->branch_id && (string) $manager->branch_id !== (string) $employee->branch_id) {
                $managerId = null;
            } elseif ($employee->company_id && $manager->branch_id) {
                $branchBelongsToCompany = Branch::withoutGlobalScopes()
                    ->where('id', $manager->branch_id)
                    ->where(function ($q) use ($employee) {
                        $q->where('asab_company_id', $employee->company_id);
                        if (Schema::hasColumn('branches', 'company_id')) {
                            $q->orWhere('company_id', $employee->company_id);
                        }
                    })
                    ->exists();
                if (! $branchBelongsToCompany) {
                    $managerId = null;
                }
            }
        }

        if (! $cashierId && ! $managerId) {
            return [
                'is_linked' => false,
                'cashier_id' => null,
                'manager_id' => null,
                'linked_type' => null,
                'linked_id' => null,
            ];
        }

        $linkedType = 'cashier';
        $linkedId = $cashierId;

        if ($employee->role === 'branch_manager' && $managerId) {
            $linkedType = 'branch_manager';
            $linkedId = $managerId;
        } elseif (! $cashierId && $managerId) {
            $linkedType = 'branch_manager';
            $linkedId = $managerId;
        }

        return [
            'is_linked' => true,
            'cashier_id' => $cashierId ? (string) $cashierId : null,
            'manager_id' => $managerId ? (string) $managerId : null,
            'linked_type' => $linkedType,
            'linked_id' => $linkedId ? (string) $linkedId : null,
        ];
    }

    /**
     * Map CashierCustodyTransaction to statement entry.
     */
    private function mapCashierTransaction(CashierCustodyTransaction $tx, Employee $employee, ?string $branchName): array
    {
        $amountHalalas = (int) round((float) $tx->amount * 100);
        $isCashIn = (bool) $tx->is_cash_in;

        $eventAt = $tx->transaction_date
            ? Carbon::parse($tx->transaction_date)->toIso8601String()
            : $tx->created_at->toIso8601String();

        $businessDate = null;
        if ($tx->receipt?->handover?->cashierShift?->shift_date) {
            $businessDate = Carbon::parse($tx->receipt->handover->cashierShift->shift_date)->toDateString();
        } elseif ($tx->related_shift_id) {
            $shiftDate = CashierShift::where('id', $tx->related_shift_id)->value('shift_date');
            if ($shiftDate) {
                $businessDate = Carbon::parse($shiftDate)->toDateString();
            }
        }
        if (! $businessDate) {
            $businessDate = $tx->transaction_date
                ? Carbon::parse($tx->transaction_date)->toDateString()
                : $tx->created_at->toDateString();
        }

        // Classification & labels
        $classification = 'other';
        $labelAr = $tx->transaction_type;
        $labelEn = $tx->transaction_type;

        if ($tx->debt_repayment_id || str_contains($tx->transaction_type, 'Debt Repayment')) {
            $classification = 'debt_repayment';
            $labelAr = 'سداد مديونية';
            $labelEn = 'Debt repayment';
        } elseif ($tx->transaction_type === 'Handover Sent') {
            $classification = 'handover_sent';
            $labelAr = 'تسليم عهدة';
            $labelEn = 'Handover sent';
        } elseif ($tx->transaction_type === 'Handover Received') {
            $classification = 'handover_received';
            $labelAr = 'استلام عهدة';
            $labelEn = 'Handover received';
        } elseif ($tx->transaction_type === 'Total Sales') {
            $classification = 'sales_collection';
            $labelAr = 'تحصيل مبيعات';
            $labelEn = 'Sales collection';
        } elseif ($tx->transaction_type === 'Opening Cash' || $tx->transaction_type === 'Cash In') {
            $classification = 'opening_cash';
            $labelAr = 'رصيد افتتاحي';
            $labelEn = 'Opening cash';
        } elseif (str_contains($tx->transaction_type, 'Reversal')) {
            $classification = 'reversal';
            $labelAr = 'عكس تسليم';
            $labelEn = 'Handover reversal';
        }

        // Shifts resolution
        // Source shift is sender's shift:
        // 1. If from managerTransfer, sender is manager: managerTransfer->branch_manager_shift_id
        // 2. If from handover, sender is cashier: handover->cashier_shift_id
        // 3. Otherwise related_shift_id
        $sourceShiftId = null;
        if ($tx->receipt?->managerTransfer?->branch_manager_shift_id) {
            $sourceShiftId = (string) $tx->receipt->managerTransfer->branch_manager_shift_id;
        } elseif ($tx->receipt?->handover?->cashier_shift_id) {
            $sourceShiftId = (string) $tx->receipt->handover->cashier_shift_id;
        } elseif ($tx->related_shift_id) {
            $sourceShiftId = (string) $tx->related_shift_id;
        }

        // Receiving shift is recipient's shift:
        // 1. From receipt receiving_cashier_shift_id or receiving_branch_manager_shift_id
        // 2. From managerTransfer destination_cashier_shift_id
        $receivingShiftId = null;
        if ($tx->receipt) {
            $receivingShiftId = $tx->receipt->receiving_cashier_shift_id
                ? (string) $tx->receipt->receiving_cashier_shift_id
                : ($tx->receipt->receiving_branch_manager_shift_id
                    ? (string) $tx->receipt->receiving_branch_manager_shift_id
                    : ($tx->receipt->managerTransfer?->destination_cashier_shift_id
                        ? (string) $tx->receipt->managerTransfer->destination_cashier_shift_id
                        : null));
        }

        // Counterparty
        $counterpartyType = null;
        $counterpartyId = null;
        $counterpartyName = $tx->counterpart_name;

        if ($tx->receipt) {
            $receipt = $tx->receipt;
            if ($tx->transaction_type === 'Handover Sent') {
                if ($receipt->handover) {
                    $counterpartyType = $receipt->handover->handover_to_type;
                    $counterpartyId = (string) $receipt->handover->handover_to_id;
                } elseif ($receipt->receiving_cashier_id) {
                    $counterpartyType = 'cashier';
                    $counterpartyId = (string) $receipt->receiving_cashier_id;
                } elseif ($receipt->receiving_branch_manager_id) {
                    $counterpartyType = 'branch_manager';
                    $counterpartyId = (string) $receipt->receiving_branch_manager_id;
                }
            } elseif ($tx->transaction_type === 'Handover Received') {
                if ($receipt->handover?->cashierShift?->cashier_id) {
                    $counterpartyType = 'cashier';
                    $counterpartyId = (string) $receipt->handover->cashierShift->cashier_id;
                } elseif ($receipt->managerTransfer?->sourceWorkday?->branch_manager_id) {
                    $counterpartyType = 'branch_manager';
                    $counterpartyId = (string) $receipt->managerTransfer->sourceWorkday->branch_manager_id;
                } elseif ($receipt->sender_id) {
                    $counterpartyType = $receipt->sender_type ?? 'cashier';
                    $counterpartyId = (string) $receipt->sender_id;
                }
            }
        }

        // Fallback for sender cashier if not resolved yet
        if (! $counterpartyId && $tx->transaction_type === 'Handover Received' && $tx->related_shift_id) {
            $senderCashierId = CashierShift::where('id', $tx->related_shift_id)->value('cashier_id');
            if ($senderCashierId) {
                $counterpartyType = 'cashier';
                $counterpartyId = (string) $senderCashierId;
            }
        }

        if (! $counterpartyName && $counterpartyType && $counterpartyId) {
            $counterpartyName = $this->resolveCounterpartyName($counterpartyType, $counterpartyId);
        }

        return [
            'source_type' => 'cashier_custody',
            'source_id' => (string) $tx->id,
            'event_at' => $eventAt,
            'business_date' => $businessDate,
            'branch' => [
                'id' => $employee->branch_id ? (string) $employee->branch_id : null,
                'name' => $branchName,
            ],
            'transaction_type' => $tx->transaction_type,
            'classification' => $classification,
            'label_ar' => $labelAr,
            'label_en' => $labelEn,
            'source_shift_id' => $sourceShiftId,
            'receiving_shift_id' => $receivingShiftId,
            'receipt_id' => $tx->receipt_id ? (string) $tx->receipt_id : null,
            'counterparty' => [
                'type' => $counterpartyType,
                'id' => $counterpartyId,
                'name' => $counterpartyName,
            ],
            'cash_in' => $isCashIn ? $amountHalalas : 0,
            'cash_out' => ! $isCashIn ? $amountHalalas : 0,
            'running_balance' => 0, // calculated in stage 5
            'confirmation_state' => 'confirmed',
            'lineage_key' => $tx->lineage_key,
            'reversal_id' => $tx->reversal_id ? (string) $tx->reversal_id : null,
        ];
    }

    /**
     * Map PersonalLedgerTransaction to statement entry.
     */
    private function mapManagerTransaction(PersonalLedgerTransaction $tx, Employee $employee, ?string $branchName): array
    {
        $amountHalalas = (int) round((float) $tx->amount * 100);
        $isCashIn = (bool) $tx->is_cash_in;

        $eventAt = $tx->transaction_date
            ? Carbon::parse($tx->transaction_date)->toIso8601String()
            : $tx->created_at->toIso8601String();

        $businessDate = null;
        if ($tx->receipt?->handover?->cashierShift?->shift_date) {
            $businessDate = Carbon::parse($tx->receipt->handover->cashierShift->shift_date)->toDateString();
        } elseif ($tx->related_shift_id) {
            $managerShiftDate = BranchManagerShift::where('id', $tx->related_shift_id)->value('shift_date');
            if ($managerShiftDate) {
                $businessDate = Carbon::parse($managerShiftDate)->toDateString();
            }
        }
        if (! $businessDate) {
            $businessDate = $tx->transaction_date
                ? Carbon::parse($tx->transaction_date)->toDateString()
                : $tx->created_at->toDateString();
        }

        // Classification & labels
        $classification = 'other';
        $labelAr = $tx->transaction_type;
        $labelEn = $tx->transaction_type;

        if ($tx->debt_repayment_id) {
            $classification = 'debt_repayment';
            $labelAr = 'سداد مديونية';
            $labelEn = 'Debt repayment';
        } elseif ($tx->transaction_type === 'Total Sales' && $tx->receipt_id) {
            // MTG-STMT-01..03, MT-12, MT-18: Manager row "Total Sales" with receipt is "Received from cashier"
            $classification = 'handover_received';
            $labelAr = 'استلام من كاشير';
            $labelEn = 'Received from cashier';
        } elseif ($tx->transaction_type === 'Total Sales') {
            $classification = 'sales_collection';
            $labelAr = 'تحصيل مبيعات';
            $labelEn = 'Sales collection';
        } elseif ($tx->transaction_type === 'Handover to Cashier') {
            $classification = 'handover_sent';
            $labelAr = 'تسليم لكاشير';
            $labelEn = 'Handover to cashier';
        } elseif ($tx->transaction_type === 'Transfer to Custody') {
            $classification = 'transfer_to_custody';
            $labelAr = 'تحويل لعهدة الفرع';
            $labelEn = 'Transfer to custody';
        } elseif ($tx->transaction_type === 'Handover to Brand Owner') {
            $classification = 'handover_sent';
            $labelAr = 'تسليم لمالك العلامة';
            $labelEn = 'Handover to brand owner';
        }

        // Shifts resolution
        // Source shift is sender's shift, receiving shift is recipient's shift
        $sourceShiftId = null;
        $receivingShiftId = null;

        if ($classification === 'handover_sent' || $tx->transaction_type === 'Handover to Cashier' || $tx->transaction_type === 'Handover to Brand Owner') {
            // Manager is SENDER (source = manager shift, receiving = cashier shift)
            if ($tx->receipt?->managerTransfer?->branch_manager_shift_id) {
                $sourceShiftId = (string) $tx->receipt->managerTransfer->branch_manager_shift_id;
            } elseif ($tx->related_shift_id) {
                $sourceShiftId = (string) $tx->related_shift_id;
            }

            if ($tx->receipt?->receiving_cashier_shift_id) {
                $receivingShiftId = (string) $tx->receipt->receiving_cashier_shift_id;
            } elseif ($tx->receipt?->managerTransfer?->destination_cashier_shift_id) {
                $receivingShiftId = (string) $tx->receipt->managerTransfer->destination_cashier_shift_id;
            }
        } elseif ($classification === 'handover_received' || ($tx->transaction_type === 'Total Sales' && $tx->receipt_id)) {
            // Cashier is SENDER (source = cashier shift, receiving = manager shift)
            if ($tx->receipt?->handover?->cashier_shift_id) {
                $sourceShiftId = (string) $tx->receipt->handover->cashier_shift_id;
            } elseif ($tx->related_shift_id && CashierShift::where('id', $tx->related_shift_id)->exists()) {
                $sourceShiftId = (string) $tx->related_shift_id;
            }

            if ($tx->receipt?->receiving_branch_manager_shift_id) {
                $receivingShiftId = (string) $tx->receipt->receiving_branch_manager_shift_id;
            } elseif ($tx->related_shift_id && BranchManagerShift::where('id', $tx->related_shift_id)->exists()) {
                $receivingShiftId = (string) $tx->related_shift_id;
            }
        } else {
            if ($tx->related_shift_id) {
                $sourceShiftId = (string) $tx->related_shift_id;
            }
        }

        // Counterparty
        $counterpartyType = null;
        $counterpartyId = null;
        $counterpartyName = $tx->cashier_name ?? $tx->brand_owner_name;

        if ($tx->cashier_name) {
            $counterpartyType = 'cashier';
            if ($tx->receipt?->handover?->cashierShift?->cashier_id) {
                $counterpartyId = (string) $tx->receipt->handover->cashierShift->cashier_id;
            } elseif ($tx->receipt?->receiving_cashier_id) {
                $counterpartyId = (string) $tx->receipt->receiving_cashier_id;
            } elseif ($tx->receipt?->confirmed_by_id) {
                $counterpartyId = (string) $tx->receipt->confirmed_by_id;
            }
        } elseif ($tx->brand_owner_name) {
            $counterpartyType = 'brand_owner';
        }

        return [
            'source_type' => 'personal_ledger',
            'source_id' => (string) $tx->id,
            'event_at' => $eventAt,
            'business_date' => $businessDate,
            'branch' => [
                'id' => $employee->branch_id ? (string) $employee->branch_id : null,
                'name' => $branchName,
            ],
            'transaction_type' => $tx->transaction_type,
            'classification' => $classification,
            'label_ar' => $labelAr,
            'label_en' => $labelEn,
            'source_shift_id' => $sourceShiftId,
            'receiving_shift_id' => $receivingShiftId,
            'receipt_id' => $tx->receipt_id ? (string) $tx->receipt_id : null,
            'counterparty' => [
                'type' => $counterpartyType,
                'id' => $counterpartyId,
                'name' => $counterpartyName,
            ],
            'cash_in' => $isCashIn ? $amountHalalas : 0,
            'cash_out' => ! $isCashIn ? $amountHalalas : 0,
            'running_balance' => 0, // calculated in stage 5
            'confirmation_state' => 'confirmed',
            'lineage_key' => $tx->lineage_key,
            'reversal_id' => $tx->reversal_id ? (string) $tx->reversal_id : null,
        ];
    }

    /**
     * Query pending requests for cashier and/or branch manager.
     */
    private function queryPendingRequests(?string $cashierId, ?string $managerId): array
    {
        $pending = [];

        if ($cashierId) {
            // Cashier outgoing handovers pending
            $outgoing = CashierShiftHandover::query()
                ->where('status', 'pending')
                ->whereHas('cashierShift', fn ($q) => $q->where('cashier_id', $cashierId))
                ->with(['cashierShift.cashier'])
                ->get();

            foreach ($outgoing as $h) {
                $pending[] = [
                    'request_type' => 'handover_sent',
                    'request_id' => (string) $h->id,
                    'created_at' => $h->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'business_date' => $h->handover_date ? Carbon::parse($h->handover_date)->toDateString() : null,
                    'amount' => (int) round((float) $h->handover_amount * 100),
                    'counterparty' => [
                        'type' => $h->handover_to_type,
                        'id' => (string) $h->handover_to_id,
                        'name' => $this->resolveCounterpartyName($h->handover_to_type, (string) $h->handover_to_id),
                    ],
                    'source_shift_id' => $h->cashier_shift_id ? (string) $h->cashier_shift_id : null,
                    'status' => 'pending',
                ];
            }

            // Cashier incoming handovers pending
            $incoming = CashierShiftHandover::query()
                ->where('status', 'pending')
                ->where('handover_to_type', 'cashier')
                ->where('handover_to_id', $cashierId)
                ->with(['cashierShift.cashier'])
                ->get();

            foreach ($incoming as $h) {
                $sender = $h->cashierShift?->cashier;
                $pending[] = [
                    'request_type' => 'handover_received',
                    'request_id' => (string) $h->id,
                    'created_at' => $h->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'business_date' => $h->handover_date ? Carbon::parse($h->handover_date)->toDateString() : null,
                    'amount' => (int) round((float) $h->handover_amount * 100),
                    'counterparty' => [
                        'type' => 'cashier',
                        'id' => $sender ? (string) $sender->id : null,
                        'name' => $sender?->name,
                    ],
                    'source_shift_id' => $h->cashier_shift_id ? (string) $h->cashier_shift_id : null,
                    'status' => 'pending',
                ];
            }

            // Manager transfers to this cashier pending
            $transfersIn = BranchManagerCashTransfer::query()
                ->where('status', 'pending')
                ->where('destination_cashier_id', $cashierId)
                ->get();

            foreach ($transfersIn as $t) {
                $pending[] = [
                    'request_type' => 'manager_transfer_received',
                    'request_id' => (string) $t->id,
                    'created_at' => $t->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'business_date' => null,
                    'amount' => (int) round((float) $t->requested_amount * 100),
                    'counterparty' => [
                        'type' => 'branch_manager',
                        'id' => $t->created_by_id ? (string) $t->created_by_id : null,
                        'name' => $t->created_by_id ? BranchManager::find($t->created_by_id)?->name : null,
                    ],
                    'source_shift_id' => $t->branch_manager_shift_id ? (string) $t->branch_manager_shift_id : null,
                    'status' => 'pending',
                ];
            }
        }

        if ($managerId) {
            // Manager incoming handovers pending
            $incomingManager = CashierShiftHandover::query()
                ->where('status', 'pending')
                ->where('handover_to_type', 'branch_manager')
                ->where('handover_to_id', $managerId)
                ->with(['cashierShift.cashier'])
                ->get();

            foreach ($incomingManager as $h) {
                $sender = $h->cashierShift?->cashier;
                $pending[] = [
                    'request_type' => 'handover_received',
                    'request_id' => (string) $h->id,
                    'created_at' => $h->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'business_date' => $h->handover_date ? Carbon::parse($h->handover_date)->toDateString() : null,
                    'amount' => (int) round((float) $h->handover_amount * 100),
                    'counterparty' => [
                        'type' => 'cashier',
                        'id' => $sender ? (string) $sender->id : null,
                        'name' => $sender?->name,
                    ],
                    'source_shift_id' => $h->cashier_shift_id ? (string) $h->cashier_shift_id : null,
                    'status' => 'pending',
                ];
            }

            // Manager transfers out pending
            $transfersOut = BranchManagerCashTransfer::query()
                ->where('status', 'pending')
                ->where('created_by_id', $managerId)
                ->get();

            foreach ($transfersOut as $t) {
                $pending[] = [
                    'request_type' => 'manager_transfer_sent',
                    'request_id' => (string) $t->id,
                    'created_at' => $t->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'business_date' => null,
                    'amount' => (int) round((float) $t->requested_amount * 100),
                    'counterparty' => [
                        'type' => 'cashier',
                        'id' => $t->destination_cashier_id ? (string) $t->destination_cashier_id : null,
                        'name' => $t->destination_cashier_id ? Cashier::find($t->destination_cashier_id)?->name : null,
                    ],
                    'source_shift_id' => $t->branch_manager_shift_id ? (string) $t->branch_manager_shift_id : null,
                    'status' => 'pending',
                ];
            }
        }

        return $pending;
    }

    private function resolveCounterpartyName(?string $type, ?string $id): ?string
    {
        if (! $id) {
            return null;
        }

        return match ($type) {
            'cashier' => Cashier::where('id', $id)->value('name'),
            'branch_manager' => BranchManager::where('id', $id)->value('name'),
            default => null,
        };
    }
}
