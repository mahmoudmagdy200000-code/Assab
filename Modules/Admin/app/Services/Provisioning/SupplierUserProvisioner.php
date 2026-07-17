<?php

namespace Modules\Admin\Services\Provisioning;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\IdentityMapService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Supplier\Models\Supplier as LegacySupplier;

/**
 * Gives a role=supplier dashboard user the matching mobile login, so the
 * password store() emails opens both the `asab` guard (asab_users) and the
 * `supplier` guard (suppliers).
 *
 * AsabSupplier deliberately has no password column — the dashboard credential
 * is the AsabUser, and `supplierId` names which commercial record it owns.
 * Two relationships fall out of that, and they are NOT the same shape:
 *  - asab_suppliers.user_id — many records may share one owning user;
 *  - identity map ENTITY_SUPPLIER_USER — exactly one legacy login per user.
 */
class SupplierUserProvisioner implements LegacyProvisioner
{
    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly ProcurementCatalogBridgeService $catalog,
    ) {}

    public function roleKey(): string
    {
        return 'supplier';
    }

    public function provision(AsabUser $user, array $data, string $temporaryPassword): void
    {
        $supplier = $this->tenantSupplier($user, $data['supplierId']);
        $this->assertLoginEmailMatches($user, $supplier);
        $legacy = $this->ensureLegacyRow($supplier);

        $this->assertUnclaimed($user, $legacy);
        $this->applyCredential($legacy, $temporaryPassword);

        // The line that makes the supplier portal meaningful: SupplierController
        // resolves a supplier's own orders through asab_suppliers.user_id, which
        // no production path has ever written.
        $supplier->forceFill(['user_id' => $user->id])->save();

        $this->identity->linkSupplierUser($user->id, $legacy->id, $user->company_id, $user->email);
    }

    /**
     * asab_suppliers.company_id is NOT NULL, so a user carrying no company can
     * never match — a companyless supplier login fails closed rather than
     * reaching across tenants.
     */
    private function tenantSupplier(AsabUser $user, string $supplierId): AsabSupplier
    {
        // withoutGlobalScope: an admin bypasses the tenant scope entirely, so the
        // ownership check has to be explicit rather than implied by the query.
        $supplier = AsabSupplier::withoutGlobalScope('tenant')->find($supplierId);

        if ($supplier === null || $supplier->company_id !== $user->company_id) {
            throw new AsabException(
                'SUPPLIER_NOT_IN_COMPANY',
                'Supplier does not belong to this user\'s company',
                'المورد لا ينتمي إلى شركة هذا المستخدم',
                422,
            );
        }

        return $supplier;
    }

    /**
     * The mobile app authenticates suppliers by the legacy row's email, which
     * the catalog bridge copies from contact_email. A login whose address
     * differs would leave the emailed password opening the dashboard only — the
     * exact half-delivery this feature exists to remove — so fail closed and
     * tell the admin rather than promise a login that is false.
     *
     * A null contact_email is the same failure: the legacy row would have no
     * address to authenticate against. (The catalog bridge still tolerates one,
     * because approveSupplierRequest legitimately creates order-flow-only
     * suppliers with no email; only a LOGIN needs one.)
     */
    private function assertLoginEmailMatches(AsabUser $user, AsabSupplier $supplier): void
    {
        if ($supplier->contact_email === null || strcasecmp($supplier->contact_email, (string) $user->email) !== 0) {
            throw new AsabException(
                'SUPPLIER_EMAIL_MISMATCH',
                'The login email must match the supplier\'s contact email',
                'يجب أن يطابق بريد الدخول البريد الإلكتروني للمورد',
                422,
            );
        }
    }

    private function ensureLegacyRow(AsabSupplier $supplier): LegacySupplier
    {
        if (! $supplier->legacy_supplier_id) {
            $this->catalog->provisionSupplier($supplier);
        }

        $legacy = LegacySupplier::withTrashed()->find($supplier->legacy_supplier_id);

        if ($legacy === null) {
            throw new AsabException(
                'SUPPLIER_LEGACY_MISSING',
                'Supplier has no mobile account to link',
                'لا يوجد حساب موبايل مرتبط بهذا المورد',
                422,
            );
        }

        return $legacy;
    }

    /**
     * unique(entity_type, legacy_id) allows exactly one login per legacy
     * supplier; a second would surface the index as a 500, and silently
     * repointing the link would leave the first user's password tracking
     * nothing. An honest 422 beats either.
     */
    private function assertUnclaimed(AsabUser $user, LegacySupplier $legacy): void
    {
        if ($this->identity->legacyClaimedByOther(AsabIdentityMap::ENTITY_SUPPLIER_USER, $legacy->id, $user->id)) {
            throw new AsabException(
                'SUPPLIER_LOGIN_AMBIGUOUS',
                'Another user already owns this supplier\'s mobile login',
                'مستخدم آخر يملك بالفعل حساب الدخول لهذا المورد',
                422,
            );
        }
    }

    /**
     * The legacy row takes the emailed password outright. store() has already
     * minted it onto the (always new) AsabUser and will email it, so copying a
     * surviving legacy hash instead — the brand-owner rule — would email a
     * password that opens the dashboard only. In the ordinary case nothing is
     * lost: the row's existing secret is the Str::password(12) the catalog
     * bridge generates and discards, which nobody has ever known.
     */
    private function applyCredential(LegacySupplier $legacy, string $temporaryPassword): void
    {
        if ($legacy->trashed()) {
            $legacy->restore();
        }

        $legacy->forceFill([
            'password' => $temporaryPassword, // hashed by the model's 'hashed' cast
            'is_first_login' => true,
            'is_active' => true,
        ])->save();

        $legacy->tokens()->delete();
    }
}
