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

class ProcessMaterialOCR implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $material;

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

            if (file_exists($tempFile)) {

                unlink($tempFile);
            }

            if (empty($text)) {

                throw new \Exception(
                    'No text extracted from material.'
                );
            }

            \Log::info('Text extracted', [
                'material_id' => $this->material->id,
                'characters' => mb_strlen($text),
            ]);

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

            \Log::info('Cleaned Text Size', [
                'chars' => mb_strlen($text)
            ]);

            /*
            |--------------------------------------------------------------------------
            | Save OCR Result
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'extracted_text' => $text,
                'processing_status' => 'completed',
                'processed_at' => now(),
            ]);

            $this->material->refresh();

            /*
            |--------------------------------------------------------------------------
            | Create Chunks
            |--------------------------------------------------------------------------
            */

            app(\App\Services\MaterialChunkingService::class)
                ->createChunks($this->material);

            \Log::info('Chunks Created', [
                'material_id' => $this->material->id,
                'count' => $this->material->chunks()->count()
            ]);

            /*
            |--------------------------------------------------------------------------
            | Dispatch Question Generation
            |--------------------------------------------------------------------------
            */

            GenerateQuestionsFromChunksJob::dispatch(
                $this->material
            );

        } catch (\Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Cleanup Temp File
            |--------------------------------------------------------------------------
            */

            if (
                $tempFile &&
                file_exists($tempFile)
            ) {
                unlink($tempFile);
            }

            \Log::error('OCR Queue Failed', [
                'material_id' => $this->material->id,
                'error' => $e->getMessage(),
            ]);

            $this->material->update([
                'processing_status' => 'failed',
            ]);
        }
    }
}
