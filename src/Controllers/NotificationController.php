<?php
namespace App\Controllers;

use App\Core\Permissions;
use App\Repositories\NotificationRepository;

/// GET /notifications: the active alerts the signed-in user is allowed to
/// see. In-app only (the bell badge and the Notifications screen).
class NotificationController extends BaseController {
    /// Which permission unlocks each notification type.
    private const TYPE_PERMISSION = [
        'out_of_stock' => 'view_products',
        'low_stock' => 'view_products',
        'overdue_payment' => 'view_sales',
        'credit_exceeded' => 'view_customers',
        'supplier_payment_due' => 'view_purchases',
        'pending_delivery' => 'view_purchases',
    ];

    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $role = $claims['role'] ?? '';

        $allowed = [];
        foreach (self::TYPE_PERMISSION as $type => $permission) {
            if (Permissions::can($role, $permission)) $allowed[] = $type;
        }

        echo json_encode((new NotificationRepository())->forTenant($claims['tenant_id'], $allowed));
    }
}
