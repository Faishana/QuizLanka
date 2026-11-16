<?php

namespace App\Services;

use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;

class PdfExtractionService
{
    public function extract(
        string $pdfPath,
        string $medium = 'si'
    ): array {

        $medium = $this->normalizeMedium($medium);

        try {

            /*
            |--------------------------------------------------------------------------
            | Run pdftotext
            |--------------------------------------------------------------------------
            */

            $text = Pdf::getText(
                $pdfPath,
                env('POPPLER_TEXT_PATH')
            );

            $text = $this->normalizeUtf8($text);

            /*
            |--------------------------------------------------------------------------
            | Empty PDF
            |--------------------------------------------------------------------------
            */

            if (empty(trim($text))) {

                Log::info(
                    'pdftotext returned empty text.',
                    [
                        'medium' => $medium,
                    ]
                );

                return [
                    'valid' => false,
                    'text' => '',
                    'reason' => 'empty',
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Analyze extracted text
            |--------------------------------------------------------------------------
            */

            $analysis = $this->analyzeText(
                $text,
                $medium
            );

            Log::info(
                'PDF text analysis',
                [
                    'medium' => $medium,
                    'characters' => mb_strlen($text),
                    'script_count' => $analysis['script_count'],
                    'latin_count' => $analysis['latin_count'],
                    'legacy_count' => $analysis['legacy_count'],
                    'valid' => $analysis['valid'],
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Good Unicode text
            |--------------------------------------------------------------------------
            */

            if ($analysis['valid']) {

                return [
                    'valid' => true,
                    'text' => $text,
                    'reason' => 'valid_unicode',
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Bad / legacy / wrong-language text
            |--------------------------------------------------------------------------
            */

            return [
                'valid' => false,
                'text' => $text,
                'reason' => $analysis['reason'],
            ];

        } catch (\Throwable $e) {

            Log::error(
                'PDF text extraction failed',
                [
                    'medium' => $medium,
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'valid' => false,
                'text' => '',
                'reason' => 'exception',
            ];
        }
    }

    /**
     * Analyze whether extracted PDF text is usable
     * for the requested language.
     */
    private function analyzeText(
        string $text,
        string $medium
    ): array {

        $sample = mb_substr(
            $text,
            0,
            10000
        );

        /*
        |--------------------------------------------------------------------------
        | Sinhala
        |--------------------------------------------------------------------------
        */

        $sinhalaCount = preg_match_all(
            '/[\x{0D80}-\x{0DFF}]/u',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Tamil
        |--------------------------------------------------------------------------
        */

        $tamilCount = preg_match_all(
            '/[\x{0B80}-\x{0BFF}]/u',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Latin
        |--------------------------------------------------------------------------
        */

        $latinCount = preg_match_all(
            '/[A-Za-z]/',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Legacy / corrupted PDF glyph indicators
        |--------------------------------------------------------------------------
        */

        $legacyCount = preg_match_all(
            '/[;=%{}\[\]\\\\]|[úöÿðþ]/u',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Determine expected script
        |--------------------------------------------------------------------------
        */

        $scriptCount = match ($medium) {

            'si' => $sinhalaCount,

            'ta' => $tamilCount,

            'en' => $latinCount,

            default => 0,
        };

        /*
        |--------------------------------------------------------------------------
        | English PDF
        |--------------------------------------------------------------------------
        */

        if ($medium === 'en') {

            $valid =
                $latinCount >= 20 &&
                $legacyCount < 10;

            return [
                'valid' => $valid,
                'script_count' => $latinCount,
                'latin_count' => $latinCount,
                'legacy_count' => $legacyCount,
                'reason' => $valid
                    ? 'valid_english'
                    : 'invalid_english',
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Sinhala / Tamil PDF
        |--------------------------------------------------------------------------
        */

        $valid =
            $scriptCount >= 20 &&
            $legacyCount < 10;

        if ($scriptCount < 20) {

            return [
                'valid' => false,
                'script_count' => $scriptCount,
                'latin_count' => $latinCount,
                'legacy_count' => $legacyCount,
                'reason' => 'wrong_or_legacy_script',
            ];
        }

        if ($legacyCount >= 10) {

            return [
                'valid' => false,
                'script_count' => $scriptCount,
                'latin_count' => $latinCount,
                'legacy_count' => $legacyCount,
                'reason' => 'legacy_encoding',
            ];
        }

        return [
            'valid' => true,
            'script_count' => $scriptCount,
            'latin_count' => $latinCount,
            'legacy_count' => $legacyCount,
            'reason' => 'valid_unicode',
        ];
    }

    private function normalizeUtf8(
        ?string $text
    ): string {

        if (!$text) {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {

            $text = mb_convert_encoding(
                $text,
                'UTF-8',
                'auto'
            );
        }

        return iconv(
            'UTF-8',
            'UTF-8//IGNORE',
            $text
        );
    }

    private function normalizeMedium(
        string $medium
    ): string {

        return match (strtolower(trim($medium))) {

            'si',
            'sinhala' => 'si',

            'ta',
            'tamil' => 'ta',

            'en',
            'english' => 'en',

            default => 'si',
        };
    }
}
