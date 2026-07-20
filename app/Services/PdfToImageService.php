<?php

namespace App\Services;

use App\Models\Material;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToImage\Pdf;

class PdfToImageService
{
    public function convert(Material $material, string $pdfPath): array
    {
        $outputDir = storage_path(
            'app/temp/pdf_pages/' . uniqid()
        );

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $pdf = new Pdf($pdfPath);

        $totalPages = $pdf->pageCount();

        Log::info('PDF Page Count', [
            'pages' => $totalPages,
        ]);

        $pages = [];

        for ($page = 1; $page <= $totalPages; $page++) {

            $savedFiles = $pdf
                ->selectPage($page)
                ->save($outputDir);

            foreach ($savedFiles as $localFile) {

                if (!file_exists($localFile)) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Upload Page To R2
                |--------------------------------------------------------------------------
                */

                $r2Key = sprintf(
                    'pastpapers/material_%d/pages/page_%03d.jpg',
                    $material->id,
                    $page
                );

                Storage::disk('s3')->put(
                    $r2Key,
                    file_get_contents($localFile)
                );

               Log::info('Page Uploaded To R2', [
                    'page' => $page,
                    'local_file' => $localFile,
                    'r2_key' => $r2Key,
                ]);

                $pages[] = [
                    'page'       => $page,
                    'local_path' => $localFile,
                    'r2_key'     => $r2Key,
                ];

                Log::info('Page Uploaded', [
                    'page' => $page,
                    'r2_key' => $r2Key,
                ]);
            }
        }

        Log::info('PDF Conversion Completed', [
                    'total_pages' => count($pages),
                ]);

        return $pages;
    }
}
