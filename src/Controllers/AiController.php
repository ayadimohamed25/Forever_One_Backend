<?php
namespace App\Controllers;

use App\Repositories\AiContextRepository;
use App\Repositories\AiConversationRepository;
use App\Services\AuditService;
use App\Services\GeminiService;

class AiController extends BaseController {
    public function chat(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('use_ai');
        $data = $this->getJsonBody();

        $question = trim($data['question'] ?? '');
        if ($question === '') {
            http_response_code(422);
            echo json_encode(['error' => 'question is required']);
            return;
        }

        $locale = in_array($data['locale'] ?? 'en', ['en', 'fr'], true) ? $data['locale'] : 'en';

        // Rebuilt on every question from the same repositories as the screens,
        // so the answer always reflects the data as it is right now.
        $context = (new AiContextRepository())->buildContext($claims['tenant_id'], $locale);
        $answer = (new GeminiService())->ask($context, $question);

        $conversation = (new AiConversationRepository())->create(
            $claims['tenant_id'], $claims['user_id'], $question, $answer
        );
        $conversation['created_at'] = date('Y-m-d H:i:s');

        AuditService::log(
            $claims['tenant_id'], $claims['user_id'], 'ai_query',
            'ai_conversation', $conversation['id'], ['question' => $question]
        );

        echo json_encode($conversation);
    }

    public function history(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('use_ai');
        echo json_encode((new AiConversationRepository())->findAllByTenant($claims['tenant_id']));
    }

    /// Returns exactly what the AI receives. Useful to check the numbers,
    /// and to show that the model only ever sees this tenant's data.
    public function context(): void {
        $claims = $this->authorize('use_ai');
        $locale = in_array($_GET['locale'] ?? 'en', ['en', 'fr'], true) ? $_GET['locale'] : 'en';

        header('Content-Type: text/plain; charset=utf-8');
        echo (new AiContextRepository())->buildContext($claims['tenant_id'], $locale);
    }
}