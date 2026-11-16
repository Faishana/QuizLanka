<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\MaterialChunk;
use App\Services\OpenAIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiQuestionGenerationService
{
    /**
     * Check if array is a list (indexed array)
     * PHP 8.0 compatibility
     */
    private function isArrayList(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        // Manual check for PHP < 8.1
        $keys = array_keys($array);
        return $keys === range(0, count($array) - 1);
    }

    /**
     * Clean text content before processing
     */
    private function cleanText(string $text): string
    {
        // Remove control characters
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Skip chunks that contain only book metadata/front matter.
     */
    private function shouldSkipChunk(string $text): bool
    {
        $text = mb_strtolower($text);

        $patterns = [
            'isbn',
            'educational publications department',
            'ministry of education',
            'first edition',
            'published by',
            'printed by',
            'all rights reserved',
            'copyright',
            'preface',
            'acknowledgements',
            'acknowledgments',
            'table of contents',
            'contents',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        // Skip very small chunks
        if (mb_strlen(trim($text)) < 300) {
            return true;
        }

        return false;
    }

    /**
     * Validate and normalize options array
     */
    private function normalizeOptions(array $options): ?array
    {
        if (empty($options) || !is_array($options)) {
            return null;
        }

        // If options are already in A/B/C/D format
        if (isset($options['A']) && isset($options['B']) &&
            isset($options['C']) && isset($options['D'])) {
            return [
                'A' => $this->cleanText($options['A']),
                'B' => $this->cleanText($options['B']),
                'C' => $this->cleanText($options['C']),
                'D' => $this->cleanText($options['D'])
            ];
        }

        // If options are indexed array [0,1,2,3]
        if ($this->isArrayList($options) && count($options) === 4) {
            return [
                'A' => $this->cleanText($options[0] ?? ''),
                'B' => $this->cleanText($options[1] ?? ''),
                'C' => $this->cleanText($options[2] ?? ''),
                'D' => $this->cleanText($options[3] ?? '')
            ];
        }

        // Try to extract from any format
        $keys = array_keys($options);
        if (count($keys) === 4) {
            $normalized = [];
            $i = 0;
            foreach (['A', 'B', 'C', 'D'] as $letter) {
                $normalized[$letter] = $this->cleanText(
                    $options[$keys[$i]] ?? ''
                );
                $i++;
            }
            return $normalized;
        }

        return null;
    }

    /**
     * Check for duplicate questions using similarity
     */
    private function isDuplicate(string $questionText, array $existingQuestions): bool
    {
        foreach ($existingQuestions as $existing) {
            // Exact match check
            if ($questionText === $existing) {
                return true;
            }

            // Similarity check (85% threshold)
            similar_text($questionText, $existing, $percent);
            if ($percent >= 85) {
                return true;
            }

            // Levenshtein distance check
            $distance = levenshtein($questionText, $existing);
            $maxLength = max(mb_strlen($questionText), mb_strlen($existing));
            if ($maxLength > 0 && ($distance / $maxLength) < 0.15) {
                return true;
            }
        }

        return false;
    }

    /**
     * REMOVED: generate() method - obsolete in new architecture
     * Questions are now generated per chunk via generateFromChunk()
     */

    /**
     * Generate questions from a single chunk
     * This is the main method used in the new architecture
     */
    public function generateFromChunk($chunk, int $questionCount = 5)
{
    try {

        $openAI = new OpenAIService();

        /*
        |--------------------------------------------------------------------------
        | Load Material
        |--------------------------------------------------------------------------
        */

        $material = $chunk->material()
            ->with(['subject', 'grade'])
            ->first();

        if (!$material) {

            Log::error('Material not found for chunk', [
                'chunk_id' => $chunk->id,
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize Medium
        |--------------------------------------------------------------------------
        |
        | Material.medium should normally be:
        |
        | si = Sinhala
        | en = English
        | ta = Tamil
        |
        | But we normalize here to prevent unexpected values.
        |
        */

        $medium = match (strtolower(trim($material->medium ?? 'si'))) {

            'si',
            'sinhala' => 'si',

            'en',
            'english' => 'en',

            'ta',
            'tamil' => 'ta',

            default => 'si',

        };

        /*
        |--------------------------------------------------------------------------
        | Subject & Grade
        |--------------------------------------------------------------------------
        */

        $subjectName = $material->subject?->name ?? '';
        $gradeName   = $material->grade?->name ?? '';

        /*
        |--------------------------------------------------------------------------
        | Validate Subject
        |--------------------------------------------------------------------------
        */

        if (empty($subjectName)) {

            Log::error('Subject missing for material', [
                'material_id' => $material->id,
                'subject_id' => $material->subject_id,
                'chunk_id' => $chunk->id,
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Grade
        |--------------------------------------------------------------------------
        */

        if (empty($gradeName)) {

            Log::error('Grade missing for material', [
                'material_id' => $material->id,
                'grade_id' => $material->grade_id,
                'chunk_id' => $chunk->id,
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Log Language
        |--------------------------------------------------------------------------
        */

        Log::info('Generating Questions From Chunk', [
            'chunk_id' => $chunk->id,
            'material_id' => $material->id,
            'subject' => $subjectName,
            'grade' => $gradeName,
            'material_medium' => $material->medium,
            'normalized_medium' => $medium,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Clean Chunk Text
        |--------------------------------------------------------------------------
        */

        $chunkText = $this->cleanText($chunk->content);

        /*
        |--------------------------------------------------------------------------
        | Skip Front Matter / Metadata
        |--------------------------------------------------------------------------
        */

        if ($this->shouldSkipChunk($chunkText)) {

            Log::info('Skipped metadata chunk', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Questions With OpenAI
        |--------------------------------------------------------------------------
        */

        try {

            $response = $openAI->generateQuestions(
                $chunkText,
                $questionCount,
                $medium,
                $subjectName,
                $gradeName
            );

            Log::info('After OpenAI API Call', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
                'response_length' => strlen($response),
            ]);

        } catch (\Throwable $e) {

            Log::error('OpenAI API Exception', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | Clean & Decode AI Response
        |--------------------------------------------------------------------------
        */

        $response = $this->cleanText($response);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {

            Log::error('JSON Decode Error', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
                'error' => json_last_error_msg(),
                'response_preview' => substr($response, 0, 500),
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate AI Response Structure
        |--------------------------------------------------------------------------
        */

        if (
            !isset($decoded['questions']) ||
            !is_array($decoded['questions'])
        ) {

            Log::error('Invalid AI Response', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
                'response_preview' => substr($response, 0, 500),
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        $questions = $decoded['questions'];

        /*
        |--------------------------------------------------------------------------
        | No Questions
        |--------------------------------------------------------------------------
        */

        if (empty($questions)) {

            Log::warning('No questions extracted from chunk', [
                'chunk_id' => $chunk->id,
                'material_id' => $material->id,
                'subject' => $subjectName,
                'grade' => $gradeName,
                'medium' => $medium,
            ]);

            $chunk->update([
                'status' => 'failed',
            ]);

            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | Batch Subject Validation
        |--------------------------------------------------------------------------
        */

        $validQuestions = [];

        try {

            $validations = $openAI->validateQuestionsBatch(
                $questions,
                $subjectName,
                $gradeName
            );

            foreach ($questions as $index => $question) {

                if (
                    isset($validations[$index]) &&
                    ($validations[$index]['is_valid'] ?? false) === true
                ) {

                    $validQuestions[] = $question;

                } else {

                    Log::warning(
                        'Question rejected by subject validation',
                        [
                            'chunk_id' => $chunk->id,
                            'material_id' => $material->id,
                            'subject' => $subjectName,
                            'grade' => $gradeName,
                            'medium' => $medium,
                            'question' => mb_substr(
                                $question['question'] ?? '',
                                0,
                                100
                            ),
                        ]
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | All Questions Rejected
            |--------------------------------------------------------------------------
            */

            if (empty($validQuestions)) {

                Log::warning(
                    'All questions rejected by subject validation',
                    [
                        'chunk_id' => $chunk->id,
                        'material_id' => $material->id,
                        'subject' => $subjectName,
                        'grade' => $gradeName,
                        'medium' => $medium,
                        'total_questions' => count($questions),
                    ]
                );

                $chunk->update([
                    'status' => 'failed',
                ]);

                return false;
            }

            $questions = $validQuestions;

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Validation Failure
            |--------------------------------------------------------------------------
            |
            | If validation itself fails, continue with the generated
            | questions instead of losing the entire chunk.
            |
            */

            Log::warning(
                'Batch validation failed, proceeding without validation',
                [
                    'chunk_id' => $chunk->id,
                    'material_id' => $material->id,
                    'subject' => $subjectName,
                    'grade' => $gradeName,
                    'medium' => $medium,
                    'error' => $e->getMessage(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save Questions
        |--------------------------------------------------------------------------
        */

        DB::beginTransaction();

        try {

            $existingQuestions = Question::where(
                'material_id',
                $material->id
            )
                ->pluck('question_text')
                ->map(function ($text) {

                    return $this->cleanText($text);

                })
                ->toArray();

            $savedCount = 0;

            foreach ($questions as $item) {

                /*
                |--------------------------------------------------------------------------
                | Question Text
                |--------------------------------------------------------------------------
                */

                $questionText = $this->cleanText(
                    $item['question'] ?? ''
                );

                if (empty($questionText)) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Options
                |--------------------------------------------------------------------------
                */

                $options = $this->normalizeOptions(
                    $item['options'] ?? []
                );

                if (!$options) {

                    Log::warning('Invalid options format', [
                        'question' => $questionText,
                        'material_id' => $material->id,
                        'medium' => $medium,
                    ]);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Correct Answer
                |--------------------------------------------------------------------------
                */

                $correctAnswer = strtoupper(
                    $this->cleanText(
                        $item['correct_answer'] ?? ''
                    )
                );

                if (!in_array(
                    $correctAnswer,
                    ['A', 'B', 'C', 'D']
                )) {

                    Log::warning('Invalid correct answer', [
                        'question' => $questionText,
                        'correct_answer' => $correctAnswer,
                        'material_id' => $material->id,
                        'medium' => $medium,
                    ]);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Duplicate Check
                |--------------------------------------------------------------------------
                */

                if (
                    $this->isDuplicate(
                        $questionText,
                        $existingQuestions
                    )
                ) {

                    Log::info('Duplicate question skipped', [
                        'question' =>
                            substr($questionText, 0, 50) . '...',
                        'material_id' => $material->id,
                        'medium' => $medium,
                    ]);

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Create Question
                |--------------------------------------------------------------------------
                |
                | ⭐ IMPORTANT FIX
                |
                | Save the material language into quiz_language.
                |
                */

                Log::info('QUESTION LANGUAGE DEBUG', [
                    'material_id' => $material->id,
                    'material_medium_raw' => $material->medium,
                    'normalized_medium' => $medium,
                    'question_language_before_save' => $medium,
                ]);

                $question = Question::create([

                    'material_id' => $material->id,
                    'grade_id' => $material->grade_id,
                    'subject_id' => $material->subject_id,
                    'created_by' => $material->uploaded_by,

                    'quiz_language' => $medium, // ✅ IMPORTANT

                    'question_text' => $questionText,
                    'question_type' => 'mcq',
                    'difficulty' => strtolower($item['difficulty'] ?? 'medium'),
                    'correct_answer' => $correctAnswer,
                    'explanation' => $this->cleanText($item['explanation'] ?? ''),
                    'is_ai_generated' => true,
                    'source_type' => 'lesson_material',
                    'paper_name' => null,
                    'paper_year' => null,
                    'status' => 'published',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Save Options
                |--------------------------------------------------------------------------
                */

                foreach ($options as $key => $value) {

                    QuestionOption::create([

                        'question_id' => $question->id,

                        'option_key' => strtoupper($key),

                        'option_text' => $value,

                        'is_correct' =>
                            strtoupper($key) === $correctAnswer,

                    ]);
                }

                $savedCount++;

                /*
                |--------------------------------------------------------------------------
                | Add To Existing Questions
                |--------------------------------------------------------------------------
                */

                $existingQuestions[] = $questionText;
            }

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Mark Chunk Processed
            |--------------------------------------------------------------------------
            */

            $chunk->update([
                'status' => 'processed',
                'processed_at' => now(),
            ]);

            Log::info('Chunk Generation Success', [

                'chunk_id' => $chunk->id,

                'material_id' => $material->id,

                'subject' => $subjectName,

                'grade' => $gradeName,

                'medium' => $medium,

                'saved_count' => $savedCount,

                'total_questions' => count($questions),

            ]);

            /*
            |--------------------------------------------------------------------------
            | Check Material Processing Status
            |--------------------------------------------------------------------------
            */

            $total = MaterialChunk::where(
                'material_id',
                $material->id
            )->count();

            $processed = MaterialChunk::where(
                'material_id',
                $material->id
            )
                ->where('status', 'processed')
                ->count();

            $failed = MaterialChunk::where(
                'material_id',
                $material->id
            )
                ->where('status', 'failed')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | All Chunks Finished
            |--------------------------------------------------------------------------
            */

            if (($processed + $failed) === $total) {

                if ($processed > 0) {

                    $material->update([

                        'processing_status' => 'completed',

                        'processed_at' => now(),

                        'error_message' => null,

                    ]);

                } else {

                    $material->update([

                        'processing_status' => 'failed',

                        'processed_at' => now(),

                        'error_message' =>
                            'No questions could be generated.',

                    ]);
                }

                Log::info(
                    'Material processing completed',
                    [
                        'material_id' => $material->id,
                        'subject' => $subjectName,
                        'grade' => $gradeName,
                        'medium' => $medium,
                        'processed_chunks' => $processed,
                        'failed_chunks' => $failed,
                        'total_chunks' => $total,
                    ]
                );
            }

            return true;

        } catch (\Throwable $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }

    } catch (\Throwable $e) {

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        Log::error('Chunk Generation Failed', [

            'chunk_id' => $chunk->id ?? null,

            'material_id' => $material->id ?? null,

            'subject' => $subjectName ?? null,

            'grade' => $gradeName ?? null,

            'medium' => $medium ?? null,

            'error' => $e->getMessage(),

            'trace' => $e->getTraceAsString(),

        ]);

        if (isset($chunk)) {

            $chunk->update([
                'status' => 'failed',
            ]);
        }

        return false;
    }
}
}
