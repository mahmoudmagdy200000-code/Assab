<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierActivationService;

/**
 * Provisioning side-effect for dashboard "add employee" (WS2): an employee
 * whose role is cashier also gets a legacy `cashiers` row so the person can
 * log into the cashier mobile app with emailed one-time credentials. Mirrors
 * BrandOwnerProvisioningService (the canonical dashboard->mobile bridge) and
 * the mobile-side CashierService creation defaults (status pending + the
 * activation-link email). Runs inside the caller's DB transaction.
 */
class CashierProvisioningService
{
    /** Free-text `role` values the dashboards use for a cashier (matched case-insensitively). */
    private const CASHIER_ROLES = ['cashier', 'كاشير', 'أمين صندوق', 'امين صندوق'];

    public function __construct(private readonly CashierActivationService $activation) {}

    /** Whether a free-text employee role means "cashier" (EN/AR variants). */
    public function isCashierRole(?string $role): bool
    {
        return $role !== null && in_array(mb_strtolower(trim($role)), self::CASHIER_ROLES, true);
    }

    /**
     * Ensure a mobile cashier login exists for a cashier-role employee.
     * Never fails the employee creation for missing prerequisites — it reports
     * them in the returned payload instead; only a cross-company email clash
     * aborts the request (and, with it, the caller's transaction).
     *
     * @return array{provisioned: bool, cashierId: ?string, reason: ?string, emailSent: bool}
     *
     * @throws AsabException 422 when the email belongs to a cashier in another company's branch.
     */
    public function provision(?string $legacyBranchId, ?string $companyId, string $name, ?string $email, ?string $phone = null): array
    {
        if (! $email) {
            return $this->skipped('EMAIL_REQUIRED');
        }
        if (! $legacyBranchId) {
            return $this->skipped('NO_BRANCH');
        }

        // cashiers.email is globally unique — an existing row is either linked
        // (same company) or a hard tenant conflict (another company).
        $existing = Cashier::withTrashed()->where('email', $email)->first();
        if ($existing) {
            return $this->linkExisting($existing, $legacyBranchId, $companyId);
        }

        // cashiers.created_by is a non-nullable FK to branch_managers; a
        // dashboard AsabUser is not one, so attribute the branch's manager.
        $manager = BranchManager::where('branch_id', $legacyBranchId)->orderByDesc('is_active')->first();
        if (! $manager) {
            return $this->skipped('NO_BRANCH_MANAGER');
        }

        $oneTimePassword = Str::password(12);

        $cashier = Cashier::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
            'branch_id' => $legacyBranchId,
            'status' => 'pending', // mobile-flow default; the activation link flips it to active
            'created_by' => $manager->id,
        ]);

        return [
            'provisioned' => true,
            'cashierId' => $cashier->id,
            'reason' => null,
            'emailSent' => $this->sendActivation($cashier, $oneTimePassword),
        ];
    }

    /**
     * An email already known to the mobile side: link it when it lives in the
     * same company (restoring + re-crediting soft-deleted accounts), refuse it
     * when it belongs to another company (multi-tenancy guard, B-A6 pattern).
     */
    private function linkExisting(Cashier $existing, string $legacyBranchId, ?string $companyId): array
    {
        $existingCompanyId = Branch::find($existing->branch_id)?->asab_company_id;
        if ($companyId === null || $existingCompanyId !== $companyId) {
            throw new AsabException(
                'EMAIL_CONFLICT',
                'Email already belongs to a cashier in another company',
                'البريد الإلكتروني مسجّل مسبقاً لكاشير في شركة أخرى',
                422,
            );
        }

        if (! $existing->trashed()) {
            return [
                'provisioned' => true,
                'cashierId' => $existing->id,
                'reason' => 'LINKED_EXISTING',
                'emailSent' => false,
            ];
        }

        // Soft-deleted account in the same company: restore it into this
        // branch with fresh one-time credentials (mirrors the brand-owner
        // restore path).
        $existing->restore();
        $oneTimePassword = Str::password(12);
        $existing->update([
            'branch_id' => $legacyBranchId,
            'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
            'status' => 'pending',
            'deactivated_at' => null,
        ]);

        return [
            'provisioned' => true,
            'cashierId' => $existing->id,
            'reason' => 'RESTORED_EXISTING',
            'emailSent' => $this->sendActivation($existing, $oneTimePassword),
        ];
    }

    /** Best-effort delivery: a mail-transport outage must not fail employee creation. */
    private function sendActivation(Cashier $cashier, string $oneTimePassword): bool
    {
        try {
            $this->activation->sendActivationLink($cashier, $oneTimePassword);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Cashier activation email failed: '.$e->getMessage());

            return false;
        }
    }

    /** @return array{provisioned: bool, cashierId: ?string, reason: ?string, emailSent: bool} */
    private function skipped(string $reason): array
    {
        return ['provisioned' => false, 'cashierId' => null, 'reason' => $reason, 'emailSent' => false];
    }
}
