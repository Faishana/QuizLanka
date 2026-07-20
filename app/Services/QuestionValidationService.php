<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class QuestionValidationService
{
    /**
     * Validate a batch of questions
     */
    public function validateBatch(array $questions): array
    {
        $validated = [];

        foreach ($questions as $index => $question) {
            try {
                if ($this->validateQuestion($question)) {
                    $validated[] = $question;
                } else {
                    Log::warning('Question validation failed', [
                        'index' => $index,
                        'question' => $question['question'] ?? 'No question text'
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error validating question', [
                    'index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $validated;
    }

    /**
     * Validate a single question
     */
    public function validateQuestion(array $question): bool
    {
        if (empty(trim($question['question'] ?? ''))) {
            Log::warning('Validation: Empty question');
            return false;
        }

        if (mb_strlen(trim($question['question'])) < 5) {
            Log::warning('Validation: Question too short');
            return false;
        }

        $type = $question['question_type'] ?? 'mcq';

        if (!in_array($type, ['mcq', 'true_false', 'short_answer', 'essay'])) {
            Log::warning('Validation: Invalid type', [
                'type' => $type
            ]);
            return false;
        }

        if ($type === 'mcq') {

            $options = $question['options'] ?? [];

            if (count($options) !== 4) {
                Log::warning('Validation: Option count failed', [
                    'count' => count($options),
                    'options' => $options
                ]);
                return false;
            }

            foreach ($options as $key => $value) {

                if (!in_array(strtoupper($key), ['A','B','C','D'])) {
                    Log::warning('Validation: Invalid option key', [
                        'key' => $key
                    ]);
                    return false;
                }

                if (empty(trim($value))) {
                    Log::warning('Validation: Empty option', [
                        'key' => $key
                    ]);
                    return false;
                }
            }

            if (empty($question['correct_answer'])) {
                Log::warning('Validation: Missing correct answer');
                return false;
            }

            $correct = strtoupper(trim($question['correct_answer']));

            if (!in_array($correct, ['A','B','C','D'])) {
                Log::warning('Validation: Invalid correct answer', [
                    'correct' => $correct
                ]);
                return false;
            }

            if (!isset($options[$correct])) {
                Log::warning('Validation: Correct answer not found in options', [
                    'correct' => $correct,
                    'options' => $options
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Remove duplicate questions from array based on question text
     */
    public function removeDuplicates(array $questions): array
    {
        $unique = [];
        $seen = [];

        foreach ($questions as $question) {
            $text = trim($question['question'] ?? '');
            if (empty($text)) {
                continue;
            }

            // Use normalized text as key
            $key = mb_strtolower($text);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $question;
            }
        }

        return $unique;
    }

    /**
     * Clean and normalize question data
     */
    public function normalizeQuestion(array $question): array
    {
        $normalized = [];

        // Clean question text
        $normalized['question'] = $this->cleanText($question['question'] ?? '');

        // Normalize options
        $options = $question['options'] ?? [];
        $normalizedOptions = [];
        foreach ($options as $key => $value) {
            $key = strtoupper(trim($key));
            if (in_array($key, ['A', 'B', 'C', 'D'])) {
                $normalizedOptions[$key] = $this->cleanText($value);
            }
        }
        $normalized['options'] = $normalizedOptions;

        // Normalize correct answer
        if (!empty($question['correct_answer'])) {
            $normalized['correct_answer'] = strtoupper(trim($question['correct_answer']));
        }

        // Set question type
        $normalized['question_type'] = $question['question_type'] ?? 'mcq';

        // Set difficulty
        $difficulty = $question['difficulty'] ?? 'medium';
        if (!in_array($difficulty, ['easy', 'medium', 'hard'])) {
            $difficulty = 'medium';
        }
        $normalized['difficulty'] = $difficulty;

        return $normalized;
    }

    /**
     * Clean text
     */
    private function cleanText(string $text): string
    {
        $text = trim($text);
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/[^\PC\s]/u', '', $text);
        return trim($text);
    }
}
