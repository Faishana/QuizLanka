<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PdfOcrService
{
    public function extract(string $pdfPath): string
    {
        $tempDir = storage_path('app/temp/' . uniqid('ocr_'));

        File::makeDirectory($tempDir, 0755, true);

        $outputPrefix = $tempDir . '/page';

        $pdftoppm = env('POPPLER_IMAGE_PATH', '/usr/bin/pdftoppm');

        $command = sprintf(
            '%s -png %s %s',
            escapeshellcmd($pdftoppm),
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix)
        );

        exec($command);

        $text = '';

        foreach (glob($tempDir . '/page-*.png') as $image) {

            $ocr = sprintf(
                '%s %s stdout -l sin',
                escapeshellcmd(env('TESSERACT_PATH', '/usr/bin/tesseract')),
                escapeshellarg($image)
            );

            $text .= shell_exec($ocr);

            $text .= PHP_EOL . PHP_EOL;
        }

        File::deleteDirectory($tempDir);

        Log::info('OCR Extraction Completed', [
            'characters' => strlen($text),
        ]);

        return trim($text);
    }
}
