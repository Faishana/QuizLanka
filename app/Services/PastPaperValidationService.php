<?php

namespace App\Services;

class PastPaperValidationService
{
    public function validateBatch(array $questions): array
    {
        return array_values(array_filter($questions, function ($question) {
            return $this->validateQuestion($question);
        }));
    }

    public function validateQuestion(array $question): bool
    {
        if (empty(trim($question['question'] ?? ''))) {
            return false;
        }

        $options = $question['options'] ?? [];

        if (count($options) != 4) {
            return false;
        }

        foreach (['A','B','C','D'] as $key) {

            if (empty($options[$key])) {
                return false;
            }

        }

        return true;
    }
}
