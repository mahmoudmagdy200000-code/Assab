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
        // No supplierId = a supplier that works with ASAB rather than with one
        // company. There is no commercial record to pick from, so this creates
        // it — which is the whole point: the admin adds the supplier's account
        // directly instead of first adding it to some company's directory.
        $supplier = isset($data['supplierId'])
            ? $this->tenantSupplier($user, $data['supplierId'])
            : $this->createPlatformSupplier($user);

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
     * Mint the commercial record for a platform supplier from the login itself.
     *
     * Refused for a login that carries a company: that supplier belongs to a
     * tenant, and picking which existing record it owns is exactly what
     * `supplierId` is for — inventing a second record would leave the company
     * with a duplicate directory entry nobody ordered from.
     */
    private function createPlatformSupplier(AsabUser $user): AsabSupplier
    {
        if ($user->company_id !== null) {
            throw new AsabException(
                'SUPPLIER_ID_REQUIRED',
                'A company supplier login must name the supplier it owns',
                'يجب اختيار المورد التابع للشركة',
                422,
                ['supplierId' => ['يجب اختيار المورد التابع للشركة']],
            );
        }

        $supplier = AsabSupplier::create([
            'name' => $user->name,
            'contact_name' => $user->name,
            // The portal authenticates on this address, and
            // assertLoginEmailMatches compares it to the login — seeding it from
            // the user is what makes the pair consistent by construction.
            'contact_email' => $user->email,
            'contact_phone' => $user->phone,
            'status' => 'active',
        ]);

        // BelongsToTenant stamps company_id from the ACTOR's tenant whenever it
        // is blank. That is right for a company adding its own supplier and
        // wrong here — a platform supplier belongs to no company — and the hook
        // cannot tell "unset" from "deliberately null". Undo it explicitly.
        if ($supplier->company_id !== null) {
            $supplier->forceFill(['company_id' => null])->save();
        }

        return $supplier;
    }

    /**
     * Two shapes are legitimate now that a supplier may contract with ASAB
     * rather than with one company:
     *
     *  - PLATFORM supplier (`company_id === null`) — claimable by a login that
     *    also carries no company. Both being null is the match, not an accident.
     *  - COMPANY supplier — the login must belong to the same company, exactly
     *    as before.
     *
     * A cross pairing stays a 422: a companyless login must not claim a
     * company's private supplier, and a company's login must not claim a
     * platform supplier and quietly pull it into that tenant.
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
