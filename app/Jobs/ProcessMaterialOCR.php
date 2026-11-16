<?php

namespace App\Jobs;

use App\Models\Material;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiTextCleaningService;
use App\Services\MaterialTextExtractionService;
use App\Jobs\GenerateQuestionsFromChunksJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProcessMaterialOCR implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public Material $material;

    public $tries = 1;

    public $timeout = 7200;

    public $backoff = 60;

    public function __construct(
        Material $material
    ) {
        $this->material = $material;
    }

    public function handle(): void
    {
        $tempFile = null;

        $this->material->update([
            'processing_status' => 'processing',
            'error_message' => null,
        ]);

        try {

            /*
            |--------------------------------------------------------------------------
            | Material information
            |--------------------------------------------------------------------------
            */

            $medium = $this->normalizeMedium(
                $this->material->medium
            );

            Log::info(
                'OCR Job Started',
                [
                    'material_id' => $this->material->id,
                    'file_path' => $this->material->file_path,
                    'medium' => $medium,
                    'subject_id' => $this->material->subject_id,
                    'grade_id' => $this->material->grade_id,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Extraction service
            |--------------------------------------------------------------------------
            */

            $extractor = app(
                MaterialTextExtractionService::class
            );

            /*
            |--------------------------------------------------------------------------
            | Temporary PDF
            |--------------------------------------------------------------------------
            */

            $tempDir = storage_path(
                'app/temp'
            );

            if (
                !is_dir($tempDir)
            ) {

                mkdir(
                    $tempDir,
                    0777,
                    true
                );
            }

            $tempFile =
                $tempDir .
                '/' .
                $this->material->id .
                '_' .
                time() .
                '_' .
                basename(
                    $this->material->file_path
                );

            /*
            |--------------------------------------------------------------------------
            | Download PDF from R2/S3
            |--------------------------------------------------------------------------
            */

            $fileContents = Storage::disk('s3')->get(
                $this->material->file_path
            );

            if (
                empty($fileContents)
            ) {

                throw new \Exception(
                    'Unable to download material file from storage.'
                );
            }

            file_put_contents(
                $tempFile,
                $fileContents
            );

            /*
            |--------------------------------------------------------------------------
            | Extract text using material language
            |--------------------------------------------------------------------------
            */

            $text = $extractor->extract(
                $tempFile,
                $medium
            );

            /*
            |--------------------------------------------------------------------------
            | RAW extraction log
            |--------------------------------------------------------------------------
            */

            Log::info(
                'PDF Text Extraction Result',
                [
                    'material_id' => $this->material->id,
                    'medium' => $medium,
                    'text_length' => mb_strlen($text),
                    'preview' => mb_substr(
                        $text,
                        0,
                        3000
                    ),
                ]
            );

            if (
                empty(trim($text))
            ) {

                throw new \Exception(
                    'No text extracted from material.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Delete temporary PDF
            |--------------------------------------------------------------------------
            */

            if (
                $tempFile &&
                file_exists($tempFile)
            ) {

                unlink($tempFile);

                $tempFile = null;
            }

            /*
            |--------------------------------------------------------------------------
            | AI text cleanup
            |--------------------------------------------------------------------------
            */

            $cleaner = new AiTextCleaningService();

            $text = $cleaner->clean(
                $text
            );

            Log::info(
                'After AI Text Cleaning',
                [
                    'material_id' => $this->material->id,
                    'medium' => $medium,
                    'text_length' => mb_strlen($text),
                    'preview' => mb_substr(
                        $text,
                        0,
                        3000
                    ),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | UTF-8 validation
            |--------------------------------------------------------------------------
            */

            if (
                !mb_check_encoding(
                    $text,
                    'UTF-8'
                )
            ) {

                Log::warning(
                    'Extracted text is not valid UTF-8',
                    [
                        'material_id' => $this->material->id,
                        'medium' => $medium,
                    ]
                );

                $text = mb_convert_encoding(
                    $text,
                    'UTF-8',
                    'auto'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Safe whitespace cleanup
            |--------------------------------------------------------------------------
            */

            $text = preg_replace(
                '/[ \t]+/u',
                ' ',
                $text
            );

            $text = preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $text
            );

            $text = trim(
                $text
            );

            /*
            |--------------------------------------------------------------------------
            | Save extracted text
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'extracted_text' => $text,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Create chunks
            |--------------------------------------------------------------------------
            */

            $material = Material::findOrFail(
                $this->material->id
            );

            $chunkService = app(
                \App\Services\MaterialChunkingService::class
            );

            $chunksCreated =
                $chunkService->createChunks(
                    $material
                );

            if (
                $chunksCreated === 0
            ) {

                throw new \Exception(
                    'No chunks could be created from extracted text.'
                );
            }

            Log::info(
                'Chunks Created',
                [
                    'material_id' => $this->material->id,
                    'count' => $chunksCreated,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Update status
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'processing_status' => 'chunked',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Dispatch question generation
            |--------------------------------------------------------------------------
            */

            $material->refresh();

            foreach (
                $material->chunks as $chunk
            ) {

                GenerateQuestionsFromChunksJob::dispatch(
                    $chunk
                )->onQueue(
                    'question-generation'
                );
            }

            Log::info(
                'OCR Job Completed. Chunk jobs dispatched.',
                [
                    'material_id' => $this->material->id,
                    'medium' => $medium,
                    'chunks' => $material->chunks->count(),
                ]
            );

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Cleanup temporary PDF
            |--------------------------------------------------------------------------
            */

            if (
                $tempFile &&
                file_exists($tempFile)
            ) {

                unlink($tempFile);
            }

            /*
            |--------------------------------------------------------------------------
            | Log failure
            |--------------------------------------------------------------------------
            */

            Log::error(
                'OCR Queue Failed',
                [
                    'material_id' => $this->material->id,
                    'medium' => $this->material->medium ?? null,
                    'subject_id' => $this->material->subject_id ?? null,
                    'grade_id' => $this->material->grade_id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            $this->material->update([
                'processing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function normalizeMedium(
        ?string $medium
    ): string {

        return match (
            strtolower(trim($medium ?? 'si'))
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

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }
}
