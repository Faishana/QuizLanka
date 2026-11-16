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
     * Crop ONLY visual media.
     *
     * Never crop the complete question.
     */
    public function cropMedia(
        array $page,
        array $boundingBox,
        int $materialId,
        int $questionId,
        int $mediaIndex = 1,
        string $mediaType = 'image'
    ): ?string {

        $tempFile = null;

        try {

            /*
            |--------------------------------------------------------------------------
            | Page image
            |--------------------------------------------------------------------------
            */

            $localPath = $page['local_path'] ?? null;

            if (
                empty($localPath) ||
                !file_exists($localPath)
            ) {

                Log::warning(
                    'Past Paper Media Crop Page Image Not Found',
                    [
                        'material_id' => $materialId,
                        'question_id' => $questionId,
                        'page' => $page['page'] ?? null,
                        'path' => $localPath,
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Bounding box
            |--------------------------------------------------------------------------
            */

            foreach (
                ['x', 'y', 'width', 'height']
                as $key
            ) {

                if (!isset($boundingBox[$key])) {

                    Log::warning(
                        'Invalid Past Paper Media Bounding Box',
                        [
                            'material_id' => $materialId,
                            'question_id' => $questionId,
                            'media_type' => $mediaType,
                            'bounding_box' => $boundingBox,
                        ]
                    );

                    return null;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Read image
            |--------------------------------------------------------------------------
            */

            $image = $this->imageManager->read(
                $localPath
            );

            $imageWidth = $image->width();
            $imageHeight = $image->height();

            /*
            |--------------------------------------------------------------------------
            | Coordinates
            |--------------------------------------------------------------------------
            */

            $x = (int) round(
                (float) $boundingBox['x']
            );

            $y = (int) round(
                (float) $boundingBox['y']
            );

            $width = (int) round(
                (float) $boundingBox['width']
            );

            $height = (int) round(
                (float) $boundingBox['height']
            );

            if (
                $width <= 0 ||
                $height <= 0
            ) {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Clamp start
            |--------------------------------------------------------------------------
            */

            $x = max(0, $x);
            $y = max(0, $y);

            if (
                $x >= $imageWidth ||
                $y >= $imageHeight
            ) {

                Log::warning(
                    'Past Paper Media Bounding Box Outside Page',
                    [
                        'material_id' => $materialId,
                        'question_id' => $questionId,
                        'media_type' => $mediaType,
                        'x' => $x,
                        'y' => $y,
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Clamp dimensions
            |--------------------------------------------------------------------------
            */

            $width = min(
                $width,
                $imageWidth - $x
            );

            $height = min(
                $height,
                $imageHeight - $y
            );

            if (
                $width <= 0 ||
                $height <= 0
            ) {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Crop media
            |--------------------------------------------------------------------------
            */

            $image->crop(
                $width,
                $height,
                $x,
                $y
            );

            /*
            |--------------------------------------------------------------------------
            | Temp directory
            |--------------------------------------------------------------------------
            */

            $tempDirectory = storage_path(
                'app/temp'
            );

            if (!is_dir($tempDirectory)) {

                mkdir(
                    $tempDirectory,
                    0775,
                    true
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Temp file
            |--------------------------------------------------------------------------
            */

            $tempFile =
                $tempDirectory .
                DIRECTORY_SEPARATOR .
                'media_' .
                uniqid('', true) .
                '.jpg';

            /*
            |--------------------------------------------------------------------------
            | Save
            |--------------------------------------------------------------------------
            */

            $image->save(
                $tempFile,
                quality: 95
            );

            /*
            |--------------------------------------------------------------------------
            | Sanitize media type
            |--------------------------------------------------------------------------
            */

            $safeMediaType = strtolower(
                trim($mediaType)
            );

            $safeMediaType = preg_replace(
                '/[^a-z0-9_-]/',
                '',
                $safeMediaType
            );

            if (empty($safeMediaType)) {
                $safeMediaType = 'image';
            }

            $mediaIndex = max(
                1,
                (int) $mediaIndex
            );

            /*
            |--------------------------------------------------------------------------
            | R2 key
            |--------------------------------------------------------------------------
            */

            $r2Key = sprintf(
                'pastpapers/material_%d/questions/q_%d/media_%03d_%s.jpg',
                $materialId,
                $questionId,
                $mediaIndex,
                $safeMediaType
            );

            /*
            |--------------------------------------------------------------------------
            | Read
            |--------------------------------------------------------------------------
            */

            $contents = file_get_contents(
                $tempFile
            );

            if ($contents === false) {

                throw new \RuntimeException(
                    'Unable to read cropped media file.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Upload
            |--------------------------------------------------------------------------
            */

            Storage::disk('s3')->put(
                $r2Key,
                $contents
            );

            /*
            |--------------------------------------------------------------------------
            | Cleanup
            |--------------------------------------------------------------------------
            */

            if (file_exists($tempFile)) {

                unlink($tempFile);

                $tempFile = null;
            }

            Log::info(
                'Past Paper Media Cropped',
                [
                    'material_id' => $materialId,
                    'question_id' => $questionId,
                    'page' => $page['page'] ?? null,
                    'media_index' => $mediaIndex,
                    'media_type' => $safeMediaType,
                    'r2_key' => $r2Key,
                    'bounding_box' => [
                        'x' => $x,
                        'y' => $y,
                        'width' => $width,
                        'height' => $height,
                    ],
                ]
            );

            return $r2Key;

        } catch (\Throwable $e) {

            Log::error(
                'Past Paper Media Crop Failed',
                [
                    'material_id' => $materialId,
                    'question_id' => $questionId,
                    'page' => $page['page'] ?? null,
                    'media_type' => $mediaType,
                    'error' => $e->getMessage(),
                ]
            );

            if (
                $tempFile &&
                file_exists($tempFile)
            ) {
                @unlink($tempFile);
            }

            return null;
        }
    }
}
