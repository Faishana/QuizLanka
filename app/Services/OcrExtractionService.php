<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Log;

class OcrExtractionService
{
    public function extract($pdfPath)
    {
        /*
        |--------------------------------------------------------------------------
        | Temp Folder
        |--------------------------------------------------------------------------
        */

        $outputDir = storage_path('app/ocr-temp');

        if (!file_exists($outputDir)) {

            mkdir($outputDir, 0777, true);
        }

        /*
        |--------------------------------------------------------------------------
        | Clean Old Images
        |--------------------------------------------------------------------------
        */

        foreach (glob($outputDir . '/*.jpg') as $oldImage) {

            if (file_exists($oldImage)) {

                unlink($oldImage);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Get PDF Page Count
        |--------------------------------------------------------------------------
        */

        $pageCount = $this->getPageCount($pdfPath);

        if ($pageCount === 0) {

            Log::error('Could not determine PDF page count.');

            return null;
        }

        Log::info("PDF has {$pageCount} pages to process.");

        /*
        |--------------------------------------------------------------------------
        | Get Poppler Path
        |--------------------------------------------------------------------------
        */

        $pdftoppm = env('POPPLER_IMAGE_PATH');

        /*
        |--------------------------------------------------------------------------
        | Process Each Page
        |--------------------------------------------------------------------------
        */

        $text = '';

        for ($page = 1; $page <= $pageCount; $page++) {

            Log::info("Processing page {$page}/{$pageCount}");

            /*
            |--------------------------------------------------------------------------
            | Convert Single Page to Image
            |--------------------------------------------------------------------------
            */

            $imagePrefix = $outputDir . '/page';

            $command = sprintf(
                '%s -f %d -l %d -jpeg -r 150 %s %s',
                escapeshellarg($pdftoppm),
                $page,
                $page,
                escapeshellarg($pdfPath),
                escapeshellarg($imagePrefix)
            );

            exec($command, $output, $resultCode);

            if ($resultCode !== 0) {

                Log::warning("Failed converting page {$page}. Exit code: {$resultCode}");

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Find Generated Image
            |--------------------------------------------------------------------------
            */

            $images = glob($outputDir . '/page-*.jpg');

            if (empty($images)) {

                Log::warning("No image generated for page {$page}.");

                continue;
            }

            // Get the newest image or the one matching our page
            $image = null;

            foreach ($images as $img) {

                // Try to extract page number from filename
                if (preg_match('/page-(\d+)\.jpg$/', $img, $matches)) {

                    $imgPage = (int)$matches[1];

                    if ($imgPage === $page) {

                        $image = $img;

                        break;
                    }
                }
            }

            // If no match found by page number, use the latest generated image
            if ($image === null && !empty($images)) {

                usort($images, function($a, $b) {

                    return filemtime($a) - filemtime($b);
                });

                $image = end($images);
            }

            if ($image === null || !file_exists($image)) {

                Log::warning("No valid image found for page {$page}.");

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | OCR Process
            |--------------------------------------------------------------------------
            */

            try {

                $ocr = (new TesseractOCR($image))
                    ->executable(env('TESSERACT_PATH'))
                    ->lang('sin', 'eng')
                    ->psm(6)
                    ->run();

                /*
                |--------------------------------------------------------------------------
                | Skip Almost-Empty Pages
                |--------------------------------------------------------------------------
                */

                if (mb_strlen(trim($ocr)) < 30) {

                    Log::info("Skipped page {$page} - too short (only " . mb_strlen(trim($ocr)) . " chars)");

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Skip OCR That's Mostly English (Invalid OCR)
                |--------------------------------------------------------------------------
                */

                $sinhalaCount = preg_match_all('/[\x{0D80}-\x{0DFF}]/u', $ocr);

                $englishCount = preg_match_all('/[A-Za-z]/', $ocr);

                if ($englishCount > ($sinhalaCount * 5)) {

                    Log::warning("Page {$page} looks like invalid OCR (English: {$englishCount}, Sinhala: {$sinhalaCount}). Skipping.");

                    continue;
                }

                if (!empty(trim($ocr))) {

                    $text .= "\n\n" . $ocr;

                    Log::info("Successfully extracted text from page {$page}.");
                }

            } catch (\Exception $e) {

                if (str_contains($e->getMessage(), 'Empty page')) {

                    Log::info("Skipped empty page: {$image}");

                } else {

                    Log::warning(
                        "OCR failed for page {$page} ({$image}): " .
                        $e->getMessage()
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Cleanup Current Image
            |--------------------------------------------------------------------------
            */

            if (file_exists($image)) {

                unlink($image);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UTF-8 Cleanup
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
        | No Text Found
        |--------------------------------------------------------------------------
        */

        if (empty(trim($text))) {

            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Return Text
        |--------------------------------------------------------------------------
        */

        return trim($text);
    }

    /**
     * Get the number of pages in a PDF file
     *
     * @param string $pdfPath
     * @return int
     */
    private function getPageCount(string $pdfPath): int
    {
        $output = [];

        $command = 'pdfinfo ' . escapeshellarg($pdfPath);

        exec($command, $output, $resultCode);

        if ($resultCode !== 0) {

            Log::error('Failed to get PDF info. Command: ' . $command);

            return 0;
        }

        foreach ($output as $line) {

            if (preg_match('/Pages:\s+(\d+)/', $line, $matches)) {

                return (int)$matches[1];
            }
        }

        Log::warning('Could not parse page count from pdfinfo output.');

        return 0;
    }
}
