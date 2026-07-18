<?php

declare(strict_types=1);

/*
 * This file is part of SolidInvoice project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidInvoice\UserBundle\Enum;

use function in_array;

/**
 * The portal access levels an admin can assign to a user. Each maps to a
 * Symfony security role; the role hierarchy in security.php makes higher levels
 * inherit the access of the lower ones (Admin > Manager > Accountant > Staff).
 */
enum PortalRole: string
{
    case Admin = 'ROLE_ADMIN';
    case Manager = 'ROLE_MANAGER';
    case Accountant = 'ROLE_ACCOUNTANT';
    case Staff = 'ROLE_STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::Accountant => 'Accountant',
            self::Staff => 'Staff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full access, including users, settings and company configuration.',
            self::Manager => 'All day-to-day work — invoices, quotes, clients, purchases, refunds, payments and reports. No user management or settings.',
            self::Accountant => 'Financials — payments, daily ledger, reports and expenses, plus invoices and purchases to reconcile. No client management or settings.',
            self::Staff => 'Data entry only — create and edit invoices and purchases (and view stock).',
        };
    }

    /**
     * Tabler badge colour used to show the role in the users list.
     */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'purple',
            self::Manager => 'blue',
            self::Accountant => 'teal',
            self::Staff => 'secondary',
        };
    }

    /**
     * The assigned portal role for a set of stored security roles. Users carry a
     * single portal role; ROLE_USER (always present) is ignored. Returns null
     * when no portal role has been assigned yet.
     *
     * @param list<string> $roles
     */
    public static function fromRoles(array $roles): ?self
    {
        foreach (self::cases() as $role) {
            if (in_array($role->value, $roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
