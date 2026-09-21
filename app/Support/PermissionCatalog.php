<?php

namespace App\Support;

use App\Enums\RoleName;

/**
 * What each permission means, and the limits on who may hold it. This is the rulebook the
 * Roles & Permissions screen enforces on the server; the screen only draws it.
 *
 *  - Ceiling: the most a role may ever be given. A customer can never hold `ticket.view_all`
 *    (that would show them everyone's tickets), whoever edits the role.
 *  - Floor: what a role must keep, or its people can no longer do their basic job.
 *  - Reserved: no feature checks this permission yet, so granting it would change nothing
 *    while looking like it did.
 *
 * Names and default assignments stay in Permissions; a test keeps the two in step.
 */
final class PermissionCatalog
{
    /** @var array<string, array{label: string, permissions: array<string, array{label: string, description: string, sensitive?: bool, reserved?: bool}>}> */
    public const GROUPS = [
        'tickets' => [
            'label' => 'Tickets',
            'permissions' => [
                'ticket.create' => ['label' => 'Open tickets', 'description' => 'Submit a new support request.'],
                'ticket.view' => ['label' => 'View tickets', 'description' => 'See tickets they opened or are assigned to.'],
                'ticket.view_all' => ['label' => 'View every ticket', 'description' => 'See all tickets, whoever opened them or handles them.', 'sensitive' => true],
                'ticket.reply' => ['label' => 'Reply to tickets', 'description' => 'Write public replies the customer can read.'],
                'ticket.internal_note' => ['label' => 'Write and read internal notes', 'description' => 'Staff-only notes that customers never see.', 'sensitive' => true],
                'ticket.assign' => ['label' => 'Assign tickets', 'description' => 'Choose which agent handles a ticket.', 'sensitive' => true],
                'ticket.update_status' => ['label' => 'Change ticket status', 'description' => 'Move tickets between open, in progress, resolved and closed.'],
                'ticket.update_priority' => ['label' => 'Change ticket priority', 'description' => 'Set a ticket to low, medium, high or urgent.'],
                'ticket.close' => ['label' => 'Close or reopen own tickets', 'description' => 'Customers closing or reopening their own resolved tickets.'],
                'ticket.update' => ['label' => 'Edit ticket details', 'description' => 'Not used by any feature yet.', 'reserved' => true],
            ],
        ],
        'users' => [
            'label' => 'Users',
            'permissions' => [
                'user.view' => ['label' => 'View users', 'description' => 'See the list of accounts.', 'sensitive' => true],
                'user.create' => ['label' => 'Create users', 'description' => 'Add accounts and email them a link to set a password.', 'sensitive' => true],
                'user.update' => ['label' => 'Edit users', 'description' => "Change a person's name or email.", 'sensitive' => true],
                'user.activate' => ['label' => 'Activate users', 'description' => 'Let a deactivated person sign in again.', 'sensitive' => true],
                'user.deactivate' => ['label' => 'Deactivate users', 'description' => 'Block a person from signing in.', 'sensitive' => true],
                'user.assign_role' => ['label' => 'Change users\' roles', 'description' => 'Promote or demote people between roles.', 'sensitive' => true],
                'user.delete' => ['label' => 'Delete users', 'description' => 'Not used by any feature yet.', 'reserved' => true],
            ],
        ],
        'departments' => [
            'label' => 'Departments',
            'permissions' => [
                'department.view' => ['label' => 'View departments', 'description' => 'See departments and their members.'],
                'department.create' => ['label' => 'Create departments', 'description' => 'Add a department.'],
                'department.update' => ['label' => 'Edit departments', 'description' => 'Rename, deactivate, and change members.'],
                'department.delete' => ['label' => 'Delete departments', 'description' => 'Remove an empty department.'],
            ],
        ],
        'reports' => [
            'label' => 'Reports and activity',
            'permissions' => [
                'report.view' => ['label' => 'View reports', 'description' => 'Ticket statistics and agent performance.', 'sensitive' => true],
                'activity.view' => ['label' => 'View sign-in activity', 'description' => "Everyone's sign-ins, with IP addresses and devices.", 'sensitive' => true],
            ],
        ],
        'access' => [
            'label' => 'Roles and permissions',
            'permissions' => [
                'role.view' => ['label' => 'View roles and permissions', 'description' => 'Open this screen in read-only mode.', 'sensitive' => true],
                'permission.assign' => ['label' => 'Change role permissions', 'description' => 'Change what the roles below their own can do.', 'sensitive' => true],
                'permission.view' => ['label' => 'View permission list', 'description' => 'Not used by any feature yet.', 'reserved' => true],
                'role.create' => ['label' => 'Create roles', 'description' => 'Not used: the roles are fixed.', 'reserved' => true],
                'role.update' => ['label' => 'Edit roles', 'description' => 'Not used: the roles are fixed.', 'reserved' => true],
                'role.delete' => ['label' => 'Delete roles', 'description' => 'Not used: the roles are fixed.', 'reserved' => true],
            ],
        ],
        'system' => [
            'label' => 'System',
            'permissions' => [
                'settings.manage' => ['label' => 'Manage settings', 'description' => 'Not used by any feature yet.', 'reserved' => true],
            ],
        ],
    ];

    private const TICKET_STAFF = [
        'ticket.view', 'ticket.view_all', 'ticket.create', 'ticket.assign', 'ticket.update_status',
        'ticket.update_priority', 'ticket.reply', 'ticket.internal_note', 'ticket.close',
    ];

    /** The roles whose permissions can be edited. Super Admin is fixed. */
    public static function editableRoles(): array
    {
        return [RoleName::Admin, RoleName::Agent, RoleName::Customer];
    }

    /** @return array<string, array<string, mixed>> permission name => details, with its group */
    public static function flat(): array
    {
        $flat = [];

        foreach (self::GROUPS as $key => $group) {
            foreach ($group['permissions'] as $name => $details) {
                $flat[$name] = $details + ['group' => $key, 'sensitive' => false, 'reserved' => false];
            }
        }

        return $flat;
    }

    /** @return list<string> the most this role may ever hold */
    public static function ceiling(RoleName $role): array
    {
        $agent = [...self::TICKET_STAFF, 'department.view', 'report.view'];

        return match ($role) {
            RoleName::Customer => ['ticket.create', 'ticket.view', 'ticket.reply', 'ticket.close'],
            RoleName::Agent => $agent,
            RoleName::Admin => [
                ...$agent,
                'user.view', 'user.create', 'user.update', 'user.activate', 'user.deactivate', 'user.assign_role',
                'department.create', 'department.update', 'department.delete',
                'activity.view', 'role.view', 'permission.assign',
            ],
            RoleName::SuperAdmin => array_keys(self::flat()),
        };
    }

    /** @return list<string> what this role must always keep */
    public static function floor(RoleName $role): array
    {
        return match ($role) {
            RoleName::Customer => ['ticket.create', 'ticket.view'],
            RoleName::Agent => ['ticket.view'],
            RoleName::Admin => ['user.view', 'ticket.view', 'ticket.view_all'],
            RoleName::SuperAdmin => [],
        };
    }

    /** @return list<string> */
    public static function defaults(RoleName $role): array
    {
        return Permissions::DEFAULTS[$role->value] ?? [];
    }
}
