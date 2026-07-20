<?php

namespace App\Services;

use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;

class PdfExtractionService
{
    protected LegacySinhalaConverterService $legacyDetector;
    protected PdfOcrService $ocrService;

    public function __construct(
        LegacySinhalaConverterService $legacyDetector,
        PdfOcrService $ocrService
    ) {
        $this->legacyDetector = $legacyDetector;
        $this->ocrService = $ocrService;
    }

    public function extract(string $pdfPath): string
    {
        try {

            $text = Pdf::getText(
                $pdfPath,
                env('POPPLER_TEXT_PATH')
            );

            if (empty(trim($text))) {

                Log::info('pdftotext returned empty. Switching to OCR.');

                return $this->ocrService->extract($pdfPath);
            }

            if ($this->legacyDetector->isLegacy($text)) {

                Log::info('Legacy Sinhala detected. Switching to OCR.');

                return $this->ocrService->extract($pdfPath);
            }

            Log::info('Unicode PDF detected. Using pdftotext.');

            return $text;

        } catch (\Throwable $e) {

            Log::error('PDF Extraction Failed', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
