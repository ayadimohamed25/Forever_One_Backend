<?php
namespace App\Services;

class OcrService {
    private string $tesseractPath = 'C:\Program Files\Tesseract-OCR\tesseract.exe';

    public function extractText(string $imagePath): string {
        $outputBase = $imagePath . '_out';
        $cmd = '"' . $this->tesseractPath . '" "' . $imagePath . '" "' . $outputBase . '" 2>&1';
        exec($cmd, $output, $returnCode);

        $txtFile = $outputBase . '.txt';
        if (!file_exists($txtFile)) {
            return '';
        }
        $text = file_get_contents($txtFile);
        unlink($txtFile);
        return $text;
    }

    public function extractAmount(string $text): ?float {
        // Looks for patterns like "TOTAL 123.45" or "123,45 DT" — common invoice formats
        if (preg_match_all('/(\d{1,6}[.,]\d{2})/', $text, $matches)) {
            $amounts = array_map(fn($m) => (float) str_replace(',', '.', $m), $matches[1]);
            return !empty($amounts) ? max($amounts) : null;
        }
        return null;
    }

    public function extractDate(string $text): ?string {
        if (preg_match('/(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})/', $text, $match)) {
            return $match[1];
        }
        return null;
    }

    public function calculateConfidence(string $text, ?float $amount, ?string $date): int {
        $score = 0;
        if (strlen(trim($text)) > 20) $score += 40;
        if ($amount !== null) $score += 35;
        if ($date !== null) $score += 25;
        return $score;
    }
}