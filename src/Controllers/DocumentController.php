<?php
namespace App\Controllers;
use App\Repositories\DocumentRepository;
use App\Services\OcrService;
use App\Services\AuditService;

class DocumentController extends BaseController {
    public function scan(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();

        if (empty($_FILES['image'])) {
            http_response_code(422);
            echo json_encode(['error' => 'No image uploaded']);
            return;
        }

        $uploadDir = __DIR__ . '/../../uploads/';
        $filename = uniqid('doc_') . '_' . basename($_FILES['image']['name']);
        $targetPath = $uploadDir . $filename;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save uploaded image']);
            return;
        }

        $ocr = new OcrService();
        $text = $ocr->extractText($targetPath);
        $amount = $ocr->extractAmount($text);
        $date = $ocr->extractDate($text);
        $confidence = $ocr->calculateConfidence($text, $amount, $date);

        $document = (new DocumentRepository())->create($claims['tenant_id'], [
            'image_path' => $filename,
            'raw_text' => $text,
            'extracted_amount' => $amount,
            'extracted_date' => $date,
            'confidence' => $confidence,
        ]);

        echo json_encode($document);
    }

    public function confirm(): void {
        
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['id']) || !isset($data['amount'])) {
            http_response_code(422);
            echo json_encode(['error' => 'id and amount are required']);
            return;
        }

        (new DocumentRepository())->confirm($claims['tenant_id'], $data['id'], $data['amount'], $data['date'] ?? null);
                (new DocumentRepository())->confirm($claims['tenant_id'], $data['id'], $data['amount'], $data['date'] ?? null);

        AuditService::log($claims['tenant_id'], $claims['user_id'], 'confirm_document', 'document', $data['id'], ['amount' => $data['amount']]);

        echo json_encode(['status' => 'confirmed']);
        
    }

    public function index(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new DocumentRepository())->findAllByTenant($claims['tenant_id']));
    }
}