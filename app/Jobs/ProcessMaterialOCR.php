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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Material $material;

    public $tries = 1;

    public $timeout = 7200;

    public $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Material $material)
    {
        $this->material = $material;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tempFile = null;

        /*
        |--------------------------------------------------------------------------
        | Update Status
        |--------------------------------------------------------------------------
        */

        $this->material->update([
            'processing_status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $extractor = app(
                MaterialTextExtractionService::class
            );

            /*
            |--------------------------------------------------------------------------
            | Download File From R2 To Temporary Location
            |--------------------------------------------------------------------------
            */

            $tempFile = storage_path(
                'app/temp/' .
                $this->material->id . '_' .
                time() . '_' .
                basename($this->material->file_path)
            );

            if (!file_exists(dirname($tempFile))) {
                mkdir(dirname($tempFile), 0777, true);
            }

            $fileContents = Storage::disk('s3')->get(
                $this->material->file_path
            );

            file_put_contents(
                $tempFile,
                $fileContents
            );

            /*
            |--------------------------------------------------------------------------
            | Extract Text
            |--------------------------------------------------------------------------
            */

            $text = $extractor->extract($tempFile);

            /*
            |--------------------------------------------------------------------------
            | Delete Temporary File
            |--------------------------------------------------------------------------
            */

            // CHANGE #2: Log temporary file deletion
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);

                Log::info('Temporary PDF deleted.', [
                    'material_id' => $this->material->id,
                    'file' => $tempFile,
                ]);
            }

            if (empty($text)) {
                throw new \Exception(
                    'No text extracted from material.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Clean OCR Text
            |--------------------------------------------------------------------------
            */

            $cleaner = new AiTextCleaningService();

            $text = $cleaner->clean($text);

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

            $text = preg_replace(
                '/[^\PC\s]/u',
                '',
                $text
            );

            // SAVE TEXT BEFORE CHUNKING
            $this->material->update([
                'extracted_text' => $text
            ]);

            $material = Material::findOrFail(
                $this->material->id
            );

            $chunkService = app(
                \App\Services\MaterialChunkingService::class
            );

            $chunksCreated = $chunkService->createChunks(
                $material
            );

            if ($chunksCreated === 0) {
                throw new \Exception('No chunks could be created from extracted text');
            }

            Log::info('Chunks Created', [
                'material_id' => $this->material->id,
                'count' => $chunksCreated
            ]);

            // CHANGE #1: Update status to 'chunked' instead of 'completed'
            $this->material->update([
                'processing_status' => 'chunked',
                'processed_at' => now(),
                'error_message' => null,
            ]);

            // CHANGE #3: Refresh the model before dispatching
            $this->material->refresh();

            /*
            |--------------------------------------------------------------------------
            | Dispatch Question Generation
            |--------------------------------------------------------------------------
            */

            // CHANGED: Dispatch individual chunk jobs instead of a single material job
            foreach ($material->chunks as $chunk) {
                GenerateQuestionsFromChunksJob::dispatch(
                    $chunk
                )->onQueue('question-generation');
            }

            Log::info('OCR Job Completed. Chunk jobs dispatched.', [
                'material_id' => $this->material->id,
                'chunks' => $material->chunks->count(),
            ]);

        } catch (\Exception $e) {
            /*
            |--------------------------------------------------------------------------
            | Cleanup Temp File
            |--------------------------------------------------------------------------
            */

            // CHANGE #2: Log temporary file deletion in catch block
            if ($tempFile && file_exists($tempFile)) {
                unlink($tempFile);

                Log::info('Temporary PDF deleted.', [
                    'material_id' => $this->material->id,
                    'file' => $tempFile,
                ]);
            }

            Log::error('OCR Queue Failed', [
                'material_id' => $this->material->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->material->update([
                'processing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Re-throw for queue retry
            throw $e;
        }
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }
}
