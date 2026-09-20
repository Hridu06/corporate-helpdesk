<?php

namespace App\Support;

use App\Enums\RoleName;

/**
 * Single source of truth for permission names and default role mappings.
 * Convention: <resource>.<action>
 */
final class Permissions
{
    public const ALL = [
        'user.view', 'user.create', 'user.update', 'user.delete',
        'user.activate', 'user.deactivate', 'user.assign_role',
        'role.view', 'role.create', 'role.update', 'role.delete',
        'permission.view', 'permission.assign',
        'department.view', 'department.create', 'department.update', 'department.delete',
        'ticket.view', 'ticket.view_all', 'ticket.create', 'ticket.update',
        'ticket.assign', 'ticket.update_status', 'ticket.update_priority',
        'ticket.reply', 'ticket.internal_note', 'ticket.close',
        'report.view', 'settings.manage', 'activity.view',
    ];

    /** Super Admin gets everything via Gate::before, so it is not listed here. */
    public const DEFAULTS = [
        'admin' => [
            'user.view', 'user.create', 'user.update',
            'department.view', 'department.update',
            'ticket.view', 'ticket.view_all', 'ticket.assign', 'ticket.update_priority',
            'ticket.update_status', 'ticket.reply', 'ticket.internal_note',
            'report.view',
        ],
        'agent' => [
            'department.view',
            'ticket.view', 'ticket.update_status', 'ticket.reply', 'ticket.internal_note',
        ],
        'customer' => [
            'ticket.create', 'ticket.view', 'ticket.reply', 'ticket.close',
        ],
    ];

    /** @return list<string> */
    public static function roles(): array
    {
        return array_map(fn (RoleName $r) => $r->value, RoleName::cases());
    }
}
