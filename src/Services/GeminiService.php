<?php
namespace App\Services;

/// Talks to Google Gemini. Returns a structured result instead of a canned
/// sentence, so the app can tell the user what actually went wrong — in their
/// own language — and offer a retry instead of a dead end.
class GeminiService {
    /// Tried in order after the configured one. A retired model answers 404,
    /// which used to look exactly like "the service is down".
     private const FALLBACK_MODELS = [
        'gemini-3.6-flash',
        'gemini-flash-latest',
        'gemini-pro-latest',
    ];

    private string $apiKey;
    private string $model;

    public function __construct() {
        $this->apiKey = trim($_ENV['GEMINI_API_KEY'] ?? '');
        $this->model = trim($_ENV['GEMINI_MODEL'] ?? '') ?: self::FALLBACK_MODELS[0];
    }

    /// ['ok' => true, 'text' => …] or ['ok' => false, 'code' => …, 'detail' => …]
        /// ['ok' => true, 'text' => …] or ['ok' => false, 'code' => …, 'detail' => …]
    public function ask(string $systemContext, string $question): array {
        if ($this->apiKey === '') {
            return $this->failure('AI_NO_KEY', 'GEMINI_API_KEY is empty in .env');
        }

        $models = array_values(array_unique(array_merge([$this->model], self::FALLBACK_MODELS)));
        $last = null;

        foreach ($models as $model) {
            $result = $this->call($model, $systemContext, $question);
            if ($result['ok']) return $result;

            // Keep the real reason: the first failure is the one that matters.
            $last ??= $result;

            // A retired or busy model is worth another candidate; a bad key,
            // a quota or a blocked prompt will not improve by retrying.
            if (!in_array($result['code'], ['AI_MODEL_NOT_FOUND', 'AI_UNAVAILABLE'], true)) {
                break;
            }
        }

        return $last ?? $this->failure('AI_UNAVAILABLE', 'no model candidates configured');
    }


    /// Diagnosis for /ai/health: is the key set, which model answers, what status.
    public function health(): array {
        $out = [
            'key_present' => $this->apiKey !== '',
            'key_length' => strlen($this->apiKey),
            'configured_model' => $this->model,
            'attempts' => [],
        ];

        if ($this->apiKey === '') {
            $out['status'] = 'AI_NO_KEY';
            return $out;
        }

        $models = array_values(array_unique(array_merge([$this->model], self::FALLBACK_MODELS)));
        foreach ($models as $model) {
            $result = $this->call($model, 'You are a test.', 'Reply with the single word OK.');
            $out['attempts'][] = [
                'model' => $model,
                'ok' => $result['ok'],
                'code' => $result['ok'] ? 'OK' : $result['code'],
                'detail' => $result['ok'] ? substr($result['text'], 0, 40) : $result['detail'],
            ];
            if ($result['ok']) {
                $out['status'] = 'OK';
                $out['working_model'] = $model;
                return $out;
            }
        }

        $out['status'] = 'FAILED';
        return $out;
    }

    private function call(string $model, string $context, string $question): array {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . urlencode($this->apiKey);

        $payload = [
            'contents' => [[
                'parts' => [['text' => $context . "\n\n=== QUESTION ===\n" . $question]],
            ]],
            // Low temperature: factual answers grounded in the data.
            'generationConfig' => ['temperature' => 0.2],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return $this->failure('AI_NETWORK', "curl: $curlError");
        }

        $data = json_decode($response, true);
        $apiMessage = $data['error']['message'] ?? substr((string) $response, 0, 300);

        if ($status !== 200) {
            $code = match (true) {
                $status === 404 => 'AI_MODEL_NOT_FOUND',
                $status === 429 => 'AI_QUOTA',
                $status === 401, $status === 403 => 'AI_NO_KEY',
                // A 400 mentioning the key is a bad key, not a bad request.
                $status === 400 && stripos($apiMessage, 'api key') !== false => 'AI_NO_KEY',
                default => 'AI_UNAVAILABLE',
            };
            return $this->failure($code, "HTTP $status ($model): $apiMessage");
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (trim($text) === '') {
            $blocked = $data['promptFeedback']['blockReason'] ?? null;
            return $blocked
                ? $this->failure('AI_BLOCKED', "blocked: $blocked")
                : $this->failure('AI_UNAVAILABLE', 'empty answer from the model');
        }

        return ['ok' => true, 'text' => $text, 'model' => $model];
    }

    private function failure(string $code, string $detail): array {
        $this->log("$code — $detail");
        return ['ok' => false, 'code' => $code, 'detail' => $detail];
    }

    /// Keeps the real reason on disk; the app only ever sees the code.
    private function log(string $line): void {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents(
            $dir . '/ai.log',
            date('Y-m-d H:i:s') . ' ' . $line . PHP_EOL,
            FILE_APPEND
        );
    }
}