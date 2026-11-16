<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LegacySinhalaConverterService
{
    /**
     * Determine whether extracted PDF text contains
     * common legacy/corrupted font indicators.
     *
     * This does NOT mean the document is Sinhala.
     */
    public function isLegacy(
        string $text
    ): bool {

        if (
            empty(trim($text))
        ) {
            return false;
        }

        $sample = mb_substr(
            $text,
            0,
            10000
        );

        $legacyCount = preg_match_all(
            '/[;=%{}\[\]\\\\]|[úöÿðþ]/u',
            $sample
        ) ?: 0;

        return $legacyCount >= 10;
    }

    /**
     * Legacy Sinhala conversion is intentionally not performed here.
     *
     * Legacy PDFs are rendered as images and processed using
     * language-specific Tesseract OCR.
     */
    public function convert(
        string $text
    ): string {

        Log::warning(
            'Legacy Sinhala conversion requested. ' .
            'Use language-specific OCR instead.'
        );

        return $text;
    }
}
