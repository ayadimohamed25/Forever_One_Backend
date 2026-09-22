<?php
namespace App\Controllers;
use App\Services\LocalAnswerService;

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
        $context = (new AiContextRepository())->buildContext($claims['tenant_id'], $locale);

        // AI_FORCE_FALLBACK=1 in .env exercises the offline path without
        // waiting for a real quota error. Leave it off outside testing.
        $forced = filter_var($_ENV['AI_FORCE_FALLBACK'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $result = $forced
            ? ['ok' => false, 'code' => 'AI_QUOTA', 'detail' => 'forced by AI_FORCE_FALLBACK']
            : (new GeminiService())->ask($context, $question);

        $source = 'ai';

        if (!$result['ok']) {
                       // Any transient failure — quota, timeout, busy or retired model —
            // can still be answered from our own data. A bad key or a blocked
            // prompt cannot, so those keep showing the banner.
            $transient = ['AI_QUOTA', 'AI_NETWORK', 'AI_UNAVAILABLE', 'AI_MODEL_NOT_FOUND'];
            $local = in_array($result['code'], $transient, true)
                ? (new LocalAnswerService())->answer($claims['tenant_id'], $question, $locale)
                : null;
            if ($local === null) {
                http_response_code(503);
                echo json_encode(['error' => $result['code']]);
                return;
            }

            $result = ['ok' => true, 'text' => $local];
            $source = 'local';
        }

        $conversation = (new AiConversationRepository())->create(
            $claims['tenant_id'], $claims['user_id'], $question, $result['text']
        );
        $conversation['created_at'] = date('Y-m-d H:i:s');

        AuditService::log(
            $claims['tenant_id'], $claims['user_id'], 'ai_query',
            'ai_conversation', $conversation['id'],
            ['question' => $question, 'source' => $source]
        );

        echo json_encode($conversation);
    }

    public function history(): void {
        header('Content-Type: application/json');
        $claims = $this->authorize('use_ai');
        echo json_encode((new AiConversationRepository())->findAllByTenant($claims['tenant_id']));
    }

    /// Exactly what the AI receives — to verify the figures.
    public function context(): void {
        $claims = $this->authorize('use_ai');
        $locale = in_array($_GET['locale'] ?? 'en', ['en', 'fr'], true) ? $_GET['locale'] : 'en';

        header('Content-Type: text/plain; charset=utf-8');
        echo (new AiContextRepository())->buildContext($claims['tenant_id'], $locale);
    }

    /// Why the AI is failing: key, model, HTTP status.
    public function health(): void {
        header('Content-Type: application/json');
        $this->authorize('use_ai');
        echo json_encode((new GeminiService())->health(), JSON_PRETTY_PRINT);
    }
}