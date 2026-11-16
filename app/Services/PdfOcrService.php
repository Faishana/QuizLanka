<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PdfOcrService
{
    public function extract(
        string $pdfPath,
        string $medium = 'si'
    ): string {

        $medium = $this->normalizeMedium(
            $medium
        );

        $languages = $this->getTesseractLanguage(
            $medium
        );

        $tempDir = storage_path(
            'app/temp/' . uniqid('ocr_', true)
        );

        File::makeDirectory(
            $tempDir,
            0755,
            true
        );

        $outputPrefix =
            $tempDir . '/page';

        $pdftoppm = env(
            'POPPLER_IMAGE_PATH',
            '/usr/bin/pdftoppm'
        );

        /*
        |--------------------------------------------------------------------------
        | Render PDF at 300 DPI
        |--------------------------------------------------------------------------
        */

        $command = sprintf(
            '%s -png -r 300 %s %s 2>&1',
            escapeshellarg($pdftoppm),
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix)
        );

        exec(
            $command,
            $output,
            $resultCode
        );

        if (
            $resultCode !== 0
        ) {

            Log::error(
                'PDF to image conversion failed',
                [
                    'medium' => $medium,
                    'exit_code' => $resultCode,
                    'output' => $output,
                ]
            );

            File::deleteDirectory(
                $tempDir
            );

            return '';
        }

        $text = '';

        foreach (
            glob(
                $tempDir . '/page-*.png'
            ) as $image
        ) {

            try {

                /*
                |--------------------------------------------------------------------------
                | Tesseract command
                |--------------------------------------------------------------------------
                */

                $tesseract =
                    env(
                        'TESSERACT_PATH',
                        '/usr/bin/tesseract'
                    );

                $command = sprintf(
                    '%s %s stdout -l %s --psm 6 2>/dev/null',
                    escapeshellarg($tesseract),
                    escapeshellarg($image),
                    escapeshellarg($languages)
                );

                $ocr = shell_exec(
                    $command
                );

                if (
                    !empty(trim($ocr ?? ''))
                ) {

                    $text .=
                        trim($ocr) .
                        PHP_EOL .
                        PHP_EOL;
                }

            } catch (\Throwable $e) {

                Log::warning(
                    'PDF OCR page failed',
                    [
                        'medium' => $medium,
                        'image' => $image,
                        'error' => $e->getMessage(),
                    ]
                );
            }
        }

        File::deleteDirectory(
            $tempDir
        );

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

        Log::info(
            'PDF OCR Extraction Completed',
            [
                'medium' => $medium,
                'languages' => $languages,
                'characters' => mb_strlen($text),
            ]
        );

        return trim($text);
    }

    private function getTesseractLanguage(
        string $medium
    ): string {

        return match ($medium) {

            'si' => 'sin+eng',

            'ta' => 'tam+eng',

            'en' => 'eng',

            default => 'eng',
        };
    }

    private function normalizeMedium(
        string $medium
    ): string {

        return match (
            strtolower(trim($medium))
        ) {

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
