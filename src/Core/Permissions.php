<?php
namespace App\Core;

/// Central definition of what each role may do.
/// Keeping it in one place means the rules are auditable rather than
/// scattered through the controllers.
class Permissions {
    public const ROLES = ['admin', 'finance', 'stock', 'commercial', 'employee', 'auditor'];

    /// Permission keys used across the app.
    private const MATRIX = [
        'admin' => ['*'],

        'finance' => [
            'view_dashboard', 'view_reports', 'view_audit',
            'view_customers', 'manage_customers',
            'view_suppliers', 'manage_suppliers',
            'view_sales', 'manage_sales',
            'view_purchases', 'manage_purchases',
            'view_payments', 'manage_payments',
            'view_products', 'view_warehouses',
            'use_ai', 'view_insights',
        ],

        'stock' => [
            'view_dashboard',
            'view_products', 'manage_products',
            'view_warehouses', 'manage_warehouses',
            'view_stock', 'manage_stock',
            'view_suppliers',
            'view_purchases', 'manage_purchases',
            'scan_documents',
            'use_ai', 'view_insights',
        ],

        'commercial' => [
            'view_dashboard',
            'view_customers', 'manage_customers',
            'view_sales', 'manage_sales',
            'view_products', 'view_stock',
            'view_payments', 'manage_payments',
            'use_ai', 'view_insights',
        ],

        'employee' => [
            'view_dashboard',
            'view_products', 'view_stock',
            'view_customers', 'view_sales',
            'scan_documents',
        ],

        'auditor' => [
            'view_dashboard', 'view_audit', 'view_reports',
            'view_products', 'view_stock', 'view_warehouses',
            'view_customers', 'view_suppliers',
            'view_sales', 'view_purchases', 'view_payments',
            'view_insights',
        ],
    ];

    public static function can(string $role, string $permission): bool {
        $allowed = self::MATRIX[$role] ?? [];
        return in_array('*', $allowed, true) || in_array($permission, $allowed, true);
    }

    /// Everything a role may do — sent to the app so the UI can hide
    /// what the user cannot use, instead of letting them hit a wall.
    public static function forRole(string $role): array {
        $allowed = self::MATRIX[$role] ?? [];
        if (in_array('*', $allowed, true)) {
            $all = [];
            foreach (self::MATRIX as $perms) {
                foreach ($perms as $p) {
                    if ($p !== '*') $all[] = $p;
                }
            }
            return array_values(array_unique($all));
        }
        return $allowed;
    }
}