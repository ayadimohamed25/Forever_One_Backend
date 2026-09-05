<?php
namespace App\Controllers;
use App\Repositories\AiContextRepository;
use App\Repositories\AiConversationRepository;
use App\Services\GeminiService;
use App\Services\AuditService;

class AiController extends BaseController {
    public function chat(): void {
        
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        $data = $this->getJsonBody();

        if (empty($data['question'])) {
            http_response_code(422);
            echo json_encode(['error' => 'question is required']);
            return;
        }

        $context = (new AiContextRepository())->buildContext($claims['tenant_id']);
        $answer = (new GeminiService())->ask($context, $data['question']);

        $conversation = (new AiConversationRepository())->create(
            $claims['tenant_id'], $claims['user_id'], $data['question'], $answer
        );
        AuditService::log($claims['tenant_id'], $claims['user_id'], 'ai_query', 'ai_conversation', $conversation['id'], ['question' => $data['question']]);

        echo json_encode($conversation);
    }

    public function history(): void {
        header('Content-Type: application/json');
        $claims = $this->authenticate();
        echo json_encode((new AiConversationRepository())->findAllByTenant($claims['tenant_id']));
    }
}