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
        /*
        |--------------------------------------------------------------------------
        | Update Status
        |--------------------------------------------------------------------------
        */

        $this->material->update([

            'processing_status' => 'processing',
        ]);

        /*
        |--------------------------------------------------------------------------
        | OCR Process
        |--------------------------------------------------------------------------
        */

        try {

            $extractor = app(
                MaterialTextExtractionService::class
            );

            $fullPath = storage_path(
                'app/public/' . $this->material->file_path
            );

            $text = $extractor->extract($fullPath);

            if (empty($text)) {

                throw new \Exception(
                    'No text extracted from material.'
                );
            }

            \Log::info('Text extracted', [

                'material_id' => $this->material->id,

                'characters' => strlen($text)
            ]);

            // Extract Text
            $text = $extractor->extract($fullPath);

            if (empty($text)) {
                throw new \Exception(
                    'No text extracted from material.'
                );
            }

            \Log::info('Text extracted', [
                'material_id' => $this->material->id,
                'characters' => mb_strlen($text),
            ]);

            // Clean OCR Text
            $cleaner = new AiTextCleaningService();
            $text = $cleaner->clean($text);

            // UTF-8 Cleanup
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

            $text = preg_replace('/[^\PC\s]/u', '', $text);

            // Save OCR Result
            $this->material->update([
                'extracted_text' => $text,
                'processing_status' => 'completed',
                'processed_at' => now(),
            ]);

            $this->material->refresh();

            // Create Chunks
            app(\App\Services\MaterialChunkingService::class)
                ->createChunks($this->material);

            // Dispatch Question Generation
            GenerateQuestionsFromChunksJob::dispatch(
                $this->material
            );

        } catch (\Exception $e) {

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
