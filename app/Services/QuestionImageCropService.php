<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class QuestionImageCropService
{
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = new ImageManager(
            new Driver()
        );
    }

    /**
     * Crop a question from a page image and upload to R2.
     *
     * @param array $page
     * [
     *     page => 1,
     *     local_path => ".../page_001.jpg",
     *     r2_key => "pastpapers/material_25/pages/page_001.jpg"
     * ]
     *
     * @param array $boundingBox
     * [
     *     x => 120,
     *     y => 450,
     *     width => 920,
     *     height => 340
     * ]
     *
     * @return string|null
     */
    public function cropQuestion(
        array $page,
        array $boundingBox,
        int $materialId,
        int $questionNumber
    ): ?string {

        try {

            if (!file_exists($page['local_path'])) {

                Log::warning('Page image not found', [
                    'path' => $page['local_path']
                ]);

                return null;
            }

            $image = $this->imageManager->read(
                $page['local_path']
            );

            $image->crop(
                $boundingBox['width'],
                $boundingBox['height'],
                $boundingBox['x'],
                $boundingBox['y']
            );

            $tempFile = storage_path(
                'app/temp/question_' .
                uniqid() .
                '.jpg'
            );

            $image->save($tempFile);

            $r2Key = sprintf(
                'pastpapers/material_%d/questions/q%03d.jpg',
                $materialId,
                $questionNumber
            );

            Storage::disk('s3')->put(
                $r2Key,
                file_get_contents($tempFile)
            );

            unlink($tempFile);

            Log::info('Question Cropped', [
                'question_number' => $questionNumber,
                'r2_key' => $r2Key,
            ]);

            return $r2Key;

        } catch (\Exception $e) {

            Log::error('Question Crop Failed', [
                'question_number' => $questionNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
