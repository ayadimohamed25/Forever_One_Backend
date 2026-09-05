<?php
namespace App\Services;

class GeminiService {
    private string $apiKey;
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';

    public function __construct() {
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
    }

    public function ask(string $systemContext, string $question): string {
        $payload = [
            'contents' => [[
                'parts' => [[
                    'text' => $systemContext . "\n\nQuestion de l'utilisateur : " . $question
                ]]
            ]]
        ];

        $ch = curl_init($this->endpoint . '?key=' . $this->apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return "Désolé, impossible de contacter le service IA. Vérifiez votre connexion.";
        }

        if ($httpCode !== 200) {
            return "Désolé, le service IA est indisponible pour le moment.";
        }

        $data = json_decode($response, true);
        return $data['candidates'][0]['content']['parts'][0]['text']
            ?? "Je n'ai pas pu générer de réponse.";
    }
}