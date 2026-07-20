<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\Log;

class PastPaperAnswerService
{
    protected OpenAIService $openAI;

    public function __construct(OpenAIService $openAI)
    {
        $this->openAI = $openAI;
    }

    /**
     * Generate correct answer for a past paper question.
     */
    public function generateAnswer(Question $question): ?array
    {
        if (empty($question->question_image)) {

            Log::warning('Question image missing', [
                'question_id' => $question->id
            ]);

            return null;
        }

        try {

            return $this->openAI->determineCorrectAnswer(
                $question->question_image
            );

        } catch (\Exception $e) {

            Log::error('Answer generation failed', [
                'question_id' => $question->id,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}
