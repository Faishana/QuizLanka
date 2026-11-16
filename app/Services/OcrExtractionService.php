<?php

namespace App\Services;

use thiagoalessio\TesseractOCR\TesseractOCR;
use Illuminate\Support\Facades\Log;

class OcrExtractionService
{
    /**
     * Extract OCR text using the language selected
     * for the material.
     *
     * si = sin + eng
     * ta = tam + eng
     * en = eng
     */
    public function extract(
        string $pdfPath,
        string $medium = 'si'
    ): ?string {

        $medium = $this->normalizeMedium($medium);

        $languages = $this->getLanguages($medium);

        $tesseractPath = env(
            'TESSERACT_PATH',
            '/usr/bin/tesseract'
        );

        $pdftoppm = env(
            'POPPLER_IMAGE_PATH',
            '/usr/bin/pdftoppm'
        );

        Log::info(
            'OCR extraction started',
            [
                'medium' => $medium,
                'languages' => $languages,
                'pdf' => basename($pdfPath),
                'tesseract' => $tesseractPath,
                'pdftoppm' => $pdftoppm,
                'psm' => 3,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Validate required binaries
        |--------------------------------------------------------------------------
        */

        if (!is_executable($tesseractPath)) {

            Log::error(
                'Tesseract executable not found or not executable.',
                [
                    'path' => $tesseractPath,
                ]
            );

            return null;
        }

        if (!is_executable($pdftoppm)) {

            Log::error(
                'pdftoppm executable not found or not executable.',
                [
                    'path' => $pdftoppm,
                ]
            );

            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Temporary directory
        |--------------------------------------------------------------------------
        */

        $outputDir = storage_path(
            'app/ocr-temp/' . uniqid('material_', true)
        );

        if (!is_dir($outputDir)) {

            mkdir(
                $outputDir,
                0777,
                true
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Get page count
        |--------------------------------------------------------------------------
        */

        $pageCount = $this->getPageCount(
            $pdfPath
        );

        if ($pageCount <= 0) {

            Log::error(
                'OCR could not determine PDF page count.',
                [
                    'pdf' => basename($pdfPath),
                ]
            );

            $this->removeDirectory(
                $outputDir
            );

            return null;
        }

        Log::info(
            'OCR PDF page count',
            [
                'pages' => $pageCount,
                'medium' => $medium,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | OCR all pages
        |--------------------------------------------------------------------------
        */

        $text = '';

        for (
            $page = 1;
            $page <= $pageCount;
            $page++
        ) {

            Log::info(
                "OCR processing page {$page}/{$pageCount}",
                [
                    'medium' => $medium,
                    'languages' => $languages,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Render current page to JPEG
            |--------------------------------------------------------------------------
            |
            | pdftoppm generates:
            |
            | page-001.jpg
            | page-002.jpg
            | page-003.jpg
            |
            | depending on the output prefix.
            |
            */

            $imagePrefix =
                $outputDir . '/page';

            $command = sprintf(
                '%s -f %d -l %d -jpeg -r 300 %s %s 2>&1',
                escapeshellarg($pdftoppm),
                $page,
                $page,
                escapeshellarg($pdfPath),
                escapeshellarg($imagePrefix)
            );

            $output = [];

            $resultCode = 0;

            exec(
                $command,
                $output,
                $resultCode
            );

            if ($resultCode !== 0) {

                Log::warning(
                    "pdftoppm failed for page {$page}",
                    [
                        'exit_code' => $resultCode,
                        'output' => implode(
                            "\n",
                            $output
                        ),
                    ]
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Locate generated image
            |--------------------------------------------------------------------------
            |
            | First try the exact filename generated by pdftoppm.
            |
            */

            $image = $outputDir .
                '/page-' .
                str_pad(
                    (string) $page,
                    3,
                    '0',
                    STR_PAD_LEFT
                ) .
                '.jpg';

            /*
            |--------------------------------------------------------------------------
            | Fallback image detection
            |--------------------------------------------------------------------------
            |
            | Some Poppler versions/configurations can produce a slightly
            | different filename. If the exact filename is not found,
            | inspect generated files and select the file corresponding
            | to the current page.
            |
            */

            if (!file_exists($image)) {

                $pageFiles = glob(
                    $outputDir . '/page-*.jpg'
                );

                $matchedImage = null;

                foreach ($pageFiles as $candidate) {

                    $filename = basename($candidate);

                    /*
                    | Example:
                    | page-001.jpg
                    */

                    if (
                        preg_match(
                            '/page-(\d+)\.jpg$/i',
                            $filename,
                            $matches
                        )
                    ) {

                        if (
                            (int) $matches[1] === $page
                        ) {

                            $matchedImage = $candidate;

                            break;
                        }
                    }
                }

                if ($matchedImage) {

                    $image = $matchedImage;

                } else {

                    Log::warning(
                        "No image generated for page {$page}",
                        [
                            'output_dir' => $outputDir,
                            'files' => $pageFiles,
                        ]
                    );

                    continue;
                }
            }

            Log::info(
                "OCR image generated for page {$page}",
                [
                    'image' => $image,
                    'size' => filesize($image),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Tesseract OCR
            |--------------------------------------------------------------------------
            */

            try {

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT:
                | Use PSM 3.
                |
                | Manual server test:
                |
                | tesseract image.jpg stdout -l tam+eng --psm 3
                |
                | produced correct Tamil Unicode.
                |--------------------------------------------------------------------------
                */

                $ocr = (new TesseractOCR($image))
                    ->executable(
                        $tesseractPath
                    )
                    ->lang(...$languages)
                    ->psm(3)
                    ->run();

                /*
                |--------------------------------------------------------------------------
                | Normalize UTF-8
                |--------------------------------------------------------------------------
                */

                $ocr = $this->normalizeUtf8(
                    $ocr
                );

                $ocr = trim(
                    $ocr
                );

                $length = mb_strlen(
                    $ocr
                );

                /*
                |--------------------------------------------------------------------------
                | Log OCR result
                |--------------------------------------------------------------------------
                */

                Log::info(
                    "Tesseract finished page {$page}",
                    [
                        'medium' => $medium,
                        'languages' => $languages,
                        'characters' => $length,
                        'preview' => mb_substr(
                            $ocr,
                            0,
                            500
                        ),
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Skip almost-empty pages
                |--------------------------------------------------------------------------
                */

                if ($length < 30) {

                    Log::info(
                        "Skipped page {$page} - OCR too short",
                        [
                            'characters' => $length,
                        ]
                    );

                    @unlink($image);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Validate OCR against expected language
                |--------------------------------------------------------------------------
                */

                $quality = $this->analyzeOcr(
                    $ocr,
                    $medium
                );

                Log::info(
                    "OCR language analysis page {$page}",
                    [
                        'medium' => $medium,
                        'script_count' => $quality['script_count'],
                        'latin_count' => $quality['latin_count'],
                        'valid' => $quality['valid'],
                    ]
                );

                /*
                |--------------------------------------------------------------------------
                | Handle weak language detection
                |--------------------------------------------------------------------------
                */

                if (!$quality['valid']) {

                    Log::warning(
                        "OCR page {$page} did not strongly match expected language.",
                        [
                            'medium' => $medium,
                            'script_count' => $quality['script_count'],
                            'latin_count' => $quality['latin_count'],
                            'preview' => mb_substr(
                                $ocr,
                                0,
                                500
                            ),
                        ]
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Do not discard pages containing useful mixed content.
                    |--------------------------------------------------------------------------
                    |
                    | Example:
                    |
                    | Tamil page + English heading + numbers
                    |
                    | Only discard if there is almost no expected-language
                    | or Latin content at all.
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $quality['script_count'] < 10 &&
                        $quality['latin_count'] < 10
                    ) {

                        @unlink($image);

                        continue;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Add page OCR text
                |--------------------------------------------------------------------------
                */

                $text .=
                    "\n\n" .
                    $ocr;

                /*
                |--------------------------------------------------------------------------
                | Remove processed image
                |--------------------------------------------------------------------------
                */

                @unlink($image);

            } catch (\Throwable $e) {

                Log::warning(
                    "Tesseract failed on page {$page}",
                    [
                        'medium' => $medium,
                        'languages' => $languages,
                        'image' => $image,
                        'error' => $e->getMessage(),
                    ]
                );

                if (
                    file_exists($image)
                ) {

                    @unlink($image);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Cleanup temporary directory
        |--------------------------------------------------------------------------
        */

        $this->removeDirectory(
            $outputDir
        );

        /*
        |--------------------------------------------------------------------------
        | Final UTF-8 normalization
        |--------------------------------------------------------------------------
        */

        $text = $this->normalizeUtf8(
            $text
        );

        $text = trim(
            $text
        );

        /*
        |--------------------------------------------------------------------------
        | No usable OCR text
        |--------------------------------------------------------------------------
        */

        if (
            empty($text)
        ) {

            Log::warning(
                'OCR completed but returned no usable text.',
                [
                    'medium' => $medium,
                ]
            );

            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | OCR completed
        |--------------------------------------------------------------------------
        */

        Log::info(
            'OCR extraction completed',
            [
                'medium' => $medium,
                'characters' => mb_strlen($text),
            ]
        );

        return $text;
    }

    /**
     * Get Tesseract languages.
     */
    private function getLanguages(
        string $medium
    ): array {

        return match ($medium) {

            'si' => [
                'sin',
                'eng',
            ],

            'ta' => [
                'tam',
                'eng',
            ],

            'en' => [
                'eng',
            ],

            default => [
                'eng',
            ],
        };
    }

    /**
     * Analyze OCR language quality.
     */
    private function analyzeOcr(
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
        | Sinhala Unicode range
        |--------------------------------------------------------------------------
        */

        $sinhalaCount = preg_match_all(
            '/[\x{0D80}-\x{0DFF}]/u',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Tamil Unicode range
        |--------------------------------------------------------------------------
        */

        $tamilCount = preg_match_all(
            '/[\x{0B80}-\x{0BFF}]/u',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Latin characters
        |--------------------------------------------------------------------------
        */

        $latinCount = preg_match_all(
            '/[A-Za-z]/',
            $sample
        ) ?: 0;

        /*
        |--------------------------------------------------------------------------
        | Expected script
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
        | English
        |--------------------------------------------------------------------------
        */

        if ($medium === 'en') {

            return [
                'valid' => $latinCount >= 20,
                'script_count' => $latinCount,
                'latin_count' => $latinCount,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Sinhala / Tamil
        |--------------------------------------------------------------------------
        */

        return [
            'valid' => $scriptCount >= 20,
            'script_count' => $scriptCount,
            'latin_count' => $latinCount,
        ];
    }

    /**
     * Get PDF page count.
     */
    private function getPageCount(
        string $pdfPath
    ): int {

        $pdfinfo = env(
            'PDFINFO_PATH',
            '/usr/bin/pdfinfo'
        );

        $command =
            escapeshellarg($pdfinfo) .
            ' ' .
            escapeshellarg($pdfPath);

        $output = [];

        $resultCode = 0;

        exec(
            $command,
            $output,
            $resultCode
        );

        if (
            $resultCode !== 0
        ) {

            Log::warning(
                'pdfinfo failed.',
                [
                    'exit_code' => $resultCode,
                    'output' => implode(
                        "\n",
                        $output
                    ),
                ]
            );

            return 0;
        }

        foreach ($output as $line) {

            if (
                preg_match(
                    '/Pages:\s+(\d+)/i',
                    $line,
                    $matches
                )
            ) {

                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * Normalize UTF-8.
     */
    private function normalizeUtf8(
        ?string $text
    ): string {

        if (!$text) {
            return '';
        }

        /*
        |--------------------------------------------------------------------------
        | Validate UTF-8
        |--------------------------------------------------------------------------
        */

        if (
            !mb_check_encoding(
                $text,
                'UTF-8'
            )
        ) {

            $text = mb_convert_encoding(
                $text,
                'UTF-8',
                'auto'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Remove invalid UTF-8 bytes only.
        |--------------------------------------------------------------------------
        |
        | This does NOT translate or remove Tamil/Sinhala Unicode.
        |--------------------------------------------------------------------------
        */

        $cleaned = iconv(
            'UTF-8',
            'UTF-8//IGNORE',
            $text
        );

        return $cleaned !== false
            ? $cleaned
            : $text;
    }

    /**
     * Normalize medium.
     */
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

    /**
     * Remove temporary directory.
     */
    private function removeDirectory(
        string $directory
    ): void {

        if (
            !is_dir($directory)
        ) {
            return;
        }

        $files = glob(
            $directory . '/*'
        );

        foreach ($files as $file) {

            if (
                is_file($file)
            ) {

                @unlink($file);
            }
        }

        @rmdir($directory);
    }
}
