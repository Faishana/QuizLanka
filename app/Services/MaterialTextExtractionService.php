<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class MaterialTextExtractionService
{
    protected $pdfExtractionService;
    protected $ocrExtractionService;

    public function __construct(
        PdfExtractionService $pdfExtractionService,
        OcrExtractionService $ocrExtractionService
    ) {
        $this->pdfExtractionService = $pdfExtractionService;
        $this->ocrExtractionService = $ocrExtractionService;
    }

    public function extract($pdfPath)
    {
        /*
        |--------------------------------------------------------------------------
        | Try PDF Parser First
        |--------------------------------------------------------------------------
        */

        $text = $this->pdfExtractionService->extract(
            $pdfPath
        );

        /*
        |--------------------------------------------------------------------------
        | Empty PDF -> OCR
        |--------------------------------------------------------------------------
        */

        if (empty(trim($text))) {

            Log::info('No extractable text found. Using OCR.');

            $ocrText = $this->ocrExtractionService->extract($pdfPath);

            return $this->cleanText($ocrText ?? '');
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy Sinhala Detection
        |--------------------------------------------------------------------------
        */

        $sample = mb_substr(
            $text,
            0,
            5000
        );

        $legacyCount = preg_match_all(
            '/[;=%{}\[\]\\\\]/',
            $sample
        );

        Log::info('Legacy Detection', [

            'legacy_count' => $legacyCount,
            'sample' => mb_substr($sample, 0, 300)

        ]);

        /*
        |--------------------------------------------------------------------------
        | Unicode PDF
        |--------------------------------------------------------------------------
        */

        if (
            strlen($text) > 1000 &&
            $legacyCount < 10
        ) {

            Log::info('Unicode PDF detected.');

            return $this->cleanText($text);
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy Sinhala PDF
        |--------------------------------------------------------------------------
        */

        Log::info('Legacy Sinhala PDF detected. Using converter.');

        $legacyConverter = app(LegacySinhalaConverterService::class);

        return $this->cleanText(
            $legacyConverter->convert($text)
        );
    }

    // Additional cleanup for OCR text, removing common headers, page numbers, etc.

    private function cleanText(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        /*
        |--------------------------------------------------------------------------
        | Remove Extra Spaces
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/[ \t]+/',
            ' ',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Excess Empty Lines
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Page Numbers
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^\s*\d+\s*$/m',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Roman Page Numbers
        |--------------------------------------------------------------------------
        */

        $text = preg_replace(
            '/^(i|ii|iii|iv|v|vi|vii|viii|ix|x)$/mi',
            '',
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | Remove Common Headers
        |--------------------------------------------------------------------------
        */

        $headers = [

            'Published by',
            'Printed by',
            'ISBN',
            'අධ්‍යාපන ප්‍රකාශන දෙපාර්තමේන්තුව',
            'ශ්‍රී ලංකා ජාතික ගීය',

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
