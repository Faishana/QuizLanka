<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MaterialTextExtractionService
{
    protected PdfExtractionService $pdfExtractionService;
    protected OcrExtractionService $ocrExtractionService;

    public function __construct(
        PdfExtractionService $pdfExtractionService,
        OcrExtractionService $ocrExtractionService
    ) {
        $this->pdfExtractionService = $pdfExtractionService;
        $this->ocrExtractionService = $ocrExtractionService;
    }

    /**
     * Extract text from PDF according to the material medium.
     *
     * $medium:
     * si = Sinhala
     * ta = Tamil
     * en = English
     */
    public function extract(
        string $pdfPath,
        string $medium = 'si'
    ): string {

        $medium = $this->normalizeMedium($medium);

        Log::info('Material text extraction started', [
            'medium' => $medium,
            'pdf' => basename($pdfPath),
        ]);

        /*
        |--------------------------------------------------------------------------
        | First attempt: PDF text extraction
        |--------------------------------------------------------------------------
        */

        $result = $this->pdfExtractionService->extract(
            $pdfPath,
            $medium
        );

        /*
        |--------------------------------------------------------------------------
        | If PDF extraction returns good Unicode text
        |--------------------------------------------------------------------------
        */

        if (
            !empty(trim($result['text'] ?? '')) &&
            ($result['valid'] ?? false)
        ) {

            Log::info('Using PDF extracted Unicode text', [
                'medium' => $medium,
                'characters' => mb_strlen($result['text']),
                'method' => 'pdftotext',
            ]);

            return $this->cleanText(
                $result['text']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PDF text is empty / legacy / corrupted
        |--------------------------------------------------------------------------
        */

        Log::info(
            'PDF text unsuitable. Switching to language-specific OCR.',
            [
                'medium' => $medium,
                'reason' => $result['reason'] ?? 'unknown',
            ]
        );

        $ocrText = $this->ocrExtractionService->extract(
            $pdfPath,
            $medium
        );

        if (empty(trim($ocrText))) {

            Log::warning(
                'OCR returned no usable text.',
                [
                    'medium' => $medium,
                ]
            );

            return '';
        }

        return $this->cleanText($ocrText);
    }

    /**
     * Normalize medium values.
     */
    private function normalizeMedium(string $medium): string
    {
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

    /**
     * Basic safe cleanup.
     *
     * Do NOT translate or modify Sinhala/Tamil/English characters.
     */
    private function cleanText(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize line endings
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            "/\r\n|\r/u",
            "\n",
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove invalid UTF-8
        |--------------------------------------------------------------------------
        */

        $text = mb_convert_encoding(
            $text,
            'UTF-8',
            'UTF-8'
        );

        $text = iconv(
            'UTF-8',
            'UTF-8//IGNORE',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize spaces
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/[ \t]+/u',
            ' ',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Normalize excessive blank lines
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            "/\n{3,}/u",
            "\n\n",
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove standalone page numbers
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^\s*\d+\s*$/m',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Roman page numbers
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^\s*(i|ii|iii|iv|v|vi|vii|viii|ix|x)\s*$/mi',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Common publisher/header cleanup
        |--------------------------------------------------------------------------
        */

        $headers = [
            'Published by',
            'Printed by',
            'ISBN',
            'අධ්‍යාපන ප්‍රකාශන දෙපාර්තමේන්තුව',
            'கல்வி வெளியீட்டுத் திணைக்களம்',
        ];

        foreach ($headers as $header) {

            $text = str_ireplace(
                $header,
                '',
                $text
            );
        }

        return trim($text);
    }
}
