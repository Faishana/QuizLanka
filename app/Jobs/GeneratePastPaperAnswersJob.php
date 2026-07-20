<?php

namespace App\Jobs;

use App\Models\Material;
use App\Models\Question;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\PastPaperAnswerService;

class GeneratePastPaperAnswersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Material $material;

    public $tries = 3;

    public function __construct(Material $material)
    {
        $this->material = $material;
    }

    public function handle(): void
    {
        Log::info('Answer Generation Started', [
            'material_id' => $this->material->id,
        ]);

        $service = app(PastPaperAnswerService::class);

        $questions = Question::where('material_id', $this->material->id)
            ->whereNull('correct_answer')
            ->get();

        foreach ($questions as $question) {

            try {

                $answer = $service->generateAnswer($question);

                if (!$answer) {
                    continue;
                }

                $question->update([
                    'correct_answer'      => $answer['correct_answer'],
                    'ai_confidence'       => $answer['confidence'],
                    'verification_status' => 'ai_verified',
                ]);

                Log::info('Answer Generated', [
                    'question_id' => $question->id,
                    'answer'      => $answer['correct_answer'],
                    'confidence'  => $answer['confidence'],
                ]);

            } catch (\Exception $e) {

                Log::error('Answer Generation Failed', [
                    'question_id' => $question->id,
                    'error' => $e->getMessage(),
                ]);

            }

        }

        Log::info('Answer Generation Completed', [
            'material_id' => $this->material->id,
        ]);
    }
}
