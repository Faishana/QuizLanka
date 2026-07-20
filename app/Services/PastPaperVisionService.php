<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PastPaperVisionService
{
    protected $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    public function extractQuestions(array $images): array
    {
        if (empty($images)) {
            Log::warning('No images provided to Vision service');
            return [];
        }

        // Get questions with numbers from AI
        $rawQuestions = $this->openAIService->extractQuestionsFromImages($images);

        // Normalize to remove question_number if needed (or keep for tracking)
        $normalized = [];
        foreach ($rawQuestions as $item) {
            $normalized[] = [
                'page' => $item['page'] ?? null,

                'question_number' => $item['question_number'] ?? null,

                'question' => $item['question'] ?? '',

                'options' => $item['options'] ?? [],

                'bounding_box' => $item['bounding_box'] ?? null,

                'correct_answer' => null,

                'difficulty' => 'medium',

                'explanation' => null,
            ];
        }

        Log::info('Vision extraction completed', [
            'images' => count($images),
            'questions' => count($normalized)
        ]);

        return $normalized;
    }
}
