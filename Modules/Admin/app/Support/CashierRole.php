<?php

namespace Modules\Admin\Support;

use Modules\Admin\Exceptions\AsabException;

/**
 * Free-text `role` values (EN/AR) that mean "cashier" on the employee forms.
 *
 * Cashier ACCOUNTS are created in the mobile app by the branch manager only —
 * the dashboard neither creates nor provisions them — so this matcher exists to
 * REFUSE cashier-role employee writes on the dashboard and to recognise the
 * mobile rows the branch directory reads through.
 */
final class CashierRole
{
    /** @var string[] */
    private const VALUES = ['cashier', 'كاشير', 'أمين صندوق', 'امين صندوق'];

    public static function matches(?string $role): bool
    {
        return $role !== null && in_array(mb_strtolower(trim($role)), self::VALUES, true);
    }

    /**
     * Refuse a cashier-role employee write on the dashboard: the branch manager
     * creates cashiers in the mobile app, and the dashboard reads them back
     * through the branch directory.
     *
     * @throws AsabException 422 CASHIER_MOBILE_ONLY
     */
    public static function assertNotCashier(?string $role): void
    {
        if (! self::matches($role)) {
            return;
        }

        throw new AsabException(
            'CASHIER_MOBILE_ONLY',
            'Cashiers are added from the mobile app by the branch manager; they cannot be created here.',
            'يتم إضافة الكاشير من تطبيق الموبايل بواسطة مدير الفرع، ولا يمكن إضافته من لوحة التحكم.',
            422,
        );
    }
}
