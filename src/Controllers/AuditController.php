<?php
namespace App\Controllers;
use App\Repositories\AuditRepository;

class AuditController extends BaseController {
    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();

        // Only admins can read the audit log (cahier des charges: journal non modifiable
        // par les utilisateurs métier ordinaires)
        if (($claims['role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'Accès réservé aux administrateurs']);
            return;
        }

        echo json_encode((new AuditRepository())->findAllByTenant($claims['tenant_id']));
    }
}