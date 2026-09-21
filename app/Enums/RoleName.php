<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Agent = 'agent';
    case Customer = 'customer';

    /** Higher outranks lower. A person may change the permissions of roles below their own only. */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdmin => 4,
            self::Admin => 3,
            self::Agent => 2,
            self::Customer => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Agent => 'Agent',
            self::Customer => 'Customer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Owns the system. Always has every permission; this cannot be changed.',
            self::Admin => 'Runs the helpdesk: people, departments, and every ticket.',
            self::Agent => 'Works the tickets assigned to them.',
            self::Customer => 'Opens tickets and follows their own.',
        };
    }
}
