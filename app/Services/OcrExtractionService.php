<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;

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
        | Convert PDF To Images Using Poppler
        |--------------------------------------------------------------------------
        */

        $pdftoppm = 'C:\\poppler\\Library\\bin\\pdftoppm.exe';

        $command =
            '"' . $pdftoppm . '" ' .
            '-jpeg -r 300 ' .
            '"' . $pdfPath . '" "' .
            $outputDir . '\\page"';

        exec(
            $command,
            $output,
            $resultCode
        );

        if ($resultCode !== 0) {

            \Log::error(
                'Poppler conversion failed.'
            );

            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Get Images
        |--------------------------------------------------------------------------
        */

        $images = glob(
            $outputDir . '/page-*.jpg'
        );

        sort($images);

        if (empty($images)) {

            \Log::warning(
                'No OCR images generated.'
            );

            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | OCR Process
        |--------------------------------------------------------------------------
        */

        $text = '';

        foreach ($images as $image) {

            try {

                $ocr = (new TesseractOCR($image))
                    ->executable(
                        'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
                    )
                    ->lang('sin', 'eng')
                    ->run();

                if (!empty(trim($ocr))) {

                    $text .= "\n\n" . $ocr;

                } else {
                }

            } catch (\Exception $e) {

                if (
                    str_contains(
                        $e->getMessage(),
                        'Empty page'
                    )
                ) {

                    \Log::info(
                        "Skipped empty page: {$image}"
                    );

                    continue;
                }

                \Log::warning(
                    "OCR failed for image {$image}: " .
                    $e->getMessage()
                );

                continue;
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
        | Cleanup Temp Images
        |--------------------------------------------------------------------------
        */

        foreach ($images as $image) {

            if (file_exists($image)) {

                unlink($image);
            }
        }

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
}
