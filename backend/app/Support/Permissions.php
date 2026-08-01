<?php

namespace App\Support;

/**
 * The permission catalogue and the seven system roles of a club.
 * Wildcards are supported: "members.*" covers every members.x permission.
 */
class Permissions
{
    public const GROUPS = [
        'dashboard', 'members', 'memberships', 'attendance', 'coaches', 'classes',
        'bookings', 'workouts', 'nutrition', 'measurements', 'finance', 'invoices',
        'payments', 'wallet', 'shop', 'inventory', 'reports', 'accounting', 'crm',
        'staff', 'roles', 'settings', 'ai', 'chat',
    ];

    public const ACTIONS = ['view', 'create', 'update', 'delete'];

    /** @return array<int, string> */
    public static function all(): array
    {
        $permissions = [];

        foreach (self::GROUPS as $group) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = "{$group}.{$action}";
            }
        }

        return array_merge($permissions, [
            'attendance.checkin',
            'attendance.checkout',
            'memberships.freeze',
            'finance.close_register',
            'reports.export',
            'ai.assistant',
            'audit.view',
        ]);
    }

    /**
     * @return array<string, array{name: array<string,string>, permissions: array<int,string>}>
     */
    public static function defaultRoles(): array
    {
        return [
            'owner' => [
                'name' => ['fa' => 'مالک', 'tr' => 'Sahip', 'en' => 'Owner'],
                'permissions' => ['*'],
            ],
            'manager' => [
                'name' => ['fa' => 'مدیر', 'tr' => 'Yönetici', 'en' => 'Manager'],
                'permissions' => [
                    'dashboard.*', 'members.*', 'memberships.*', 'attendance.*', 'coaches.*',
                    'classes.*', 'bookings.*', 'workouts.*', 'nutrition.*', 'measurements.*',
                    'finance.*', 'invoices.*', 'payments.*', 'wallet.*', 'shop.*', 'inventory.*',
                    'reports.*', 'accounting.*', 'crm.*', 'staff.*', 'settings.*', 'ai.*', 'chat.*',
                    'audit.view',
                ],
            ],
            'reception' => [
                'name' => ['fa' => 'پذیرش', 'tr' => 'Resepsiyon', 'en' => 'Reception'],
                'permissions' => [
                    'dashboard.view', 'members.view', 'members.create', 'members.update',
                    'memberships.view', 'memberships.create', 'attendance.*', 'classes.view',
                    'bookings.*', 'invoices.view', 'invoices.create', 'payments.create',
                    'shop.view', 'chat.view',
                ],
            ],
            'coach' => [
                'name' => ['fa' => 'مربی', 'tr' => 'Antrenör', 'en' => 'Coach'],
                'permissions' => [
                    'dashboard.view', 'members.view', 'attendance.view', 'classes.view',
                    'bookings.view', 'bookings.update', 'workouts.*', 'nutrition.*',
                    'measurements.*', 'chat.*', 'ai.assistant',
                ],
            ],
            'cashier' => [
                'name' => ['fa' => 'صندوق‌دار', 'tr' => 'Kasiyer', 'en' => 'Cashier'],
                'permissions' => [
                    'dashboard.view', 'members.view', 'memberships.view', 'memberships.create',
                    'invoices.*', 'payments.*', 'wallet.*', 'shop.*', 'finance.view',
                    'finance.close_register', 'inventory.view',
                ],
            ],
            'accountant' => [
                'name' => ['fa' => 'حسابدار', 'tr' => 'Muhasebeci', 'en' => 'Accountant'],
                'permissions' => [
                    'dashboard.view', 'finance.*', 'invoices.view', 'payments.view',
                    'accounting.*', 'reports.*', 'inventory.view', 'shop.view',
                ],
            ],
            'member' => [
                'name' => ['fa' => 'عضو', 'tr' => 'Üye', 'en' => 'Member'],
                'permissions' => [
                    'bookings.view', 'bookings.create', 'workouts.view', 'nutrition.view',
                    'measurements.view', 'wallet.view', 'chat.*',
                ],
            ],
        ];
    }
}
