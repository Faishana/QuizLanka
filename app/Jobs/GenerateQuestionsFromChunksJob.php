<?php

namespace App\Jobs;

use App\Models\Material;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiQuestionGenerationService;

class GenerateQuestionsFromChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Material $material;

    public function __construct(Material $material)
    {
        $this->material = $material;
    }

    public function handle(): void
    {
        try {

            $this->material->load('chunks');

            $generator = app(
                AiQuestionGenerationService::class
            );

            $chunkCount = $this->material->chunks->count();

            if ($chunkCount === 0) {

                \Log::warning('No chunks found', [
                    'material_id' => $this->material->id
                ]);

                return;
            }

            $questionsPerChunk = max(
                5,
                ceil(50 / $chunkCount)
            );

            \Log::info('Question Target', [
                'material_id' => $this->material->id,
                'chunks' => $chunkCount,
                'questions_per_chunk' => $questionsPerChunk,
            ]);

            foreach ($this->material->chunks as $chunk) {

                $generator->generateFromChunk(
                    $chunk,
                    $questionsPerChunk
                );
            }

            \Log::info('Question Generation Completed', [
                'material_id' => $this->material->id,
                'chunks_processed' => $this->material->chunks->count(),
            ]);

        } catch (\Exception $e) {

            \Log::error('Question Generation Failed', [
                'material_id' => $this->material->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
