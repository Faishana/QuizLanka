<?php

namespace App\Jobs;

use App\Models\MaterialChunk;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\AiQuestionGenerationService;

class GenerateQuestionsFromChunksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public MaterialChunk $chunk;

    public function __construct(MaterialChunk $chunk)
    {
        $this->chunk = $chunk;
    }

    public function handle(): void
    {
        $generator = app(
            AiQuestionGenerationService::class
        );

        $generator->generateFromChunk(
            $this->chunk
        );
    }
}
