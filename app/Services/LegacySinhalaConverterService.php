<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class LegacySinhalaConverterService
{
    /**
     * Detect whether extracted text appears to be
     * legacy Sinhala encoding (FMAbhaya, DL, etc.).
     */
    public function isLegacy(string $text): bool
    {
        if (empty(trim($text))) {
            return false;
        }

        $sample = mb_substr($text, 0, 5000);

        /*
        |--------------------------------------------------------------------------
        | Detect Common Legacy Characters
        |--------------------------------------------------------------------------
        */

        $legacyCount = preg_match_all(
            '/[;=%{}\[\]\\\\]|[úöÿðþ]/u',
            $sample
        );

        return $legacyCount > 10;
    }

    /**
     * Convert legacy Sinhala to Unicode.
     *
     * NOTE:
     * This is currently a placeholder.
     * In the next phase we'll integrate a proper
     * legacy Sinhala conversion engine here.
     */
    public function convert(string $text): string
    {
        Log::warning(
            'Legacy Sinhala converter not implemented yet. Returning original extracted text.'
        );

        return $text;
    }
}
