<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Facades\DB;

class AiQuestionGenerationService
{
    public function generate($material)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Get Text
            |--------------------------------------------------------------------------
            */

            $text = trim($material->extracted_text ?? '');

            if (empty($text)) {

                \Log::error('AI Generation Failed: Empty text');

                return false;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove Front Matter
            |--------------------------------------------------------------------------
            */

            $text = preg_replace(
                '/ISBN.*?\n/i',
                '',
                $text
            );

            $text = preg_replace(
                '/Educational Publications Department/i',
                '',
                $text
            );

            $text = preg_replace(
                '/Vishwa Graphics.*?\n/i',
                '',
                $text
            );

            /*
            |--------------------------------------------------------------------------
            | Generate Questions From Each Chunk
            |--------------------------------------------------------------------------
            */

            $chunks = [$text];

            $openAI = new OpenAIService();

            $allQuestions = [];

            foreach ($chunks as $chunkIndex => $chunk) {

                try {

                    $response = $openAI->generateQuestions(
                        $chunk->content,
                        3
                    );

                    $decoded = json_decode(
                        trim($response),
                        true
                    );

                    if (
                        isset($decoded['questions']) &&
                        is_array($decoded['questions'])
                    ) {

                        $allQuestions = array_merge(
                            $allQuestions,
                            $decoded['questions']
                        );
                    }

                } catch (\Exception $e) {

                    \Log::error(
                        'Chunk Failed: ' .
                        $e->getMessage()
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Use Combined Questions
            |--------------------------------------------------------------------------
            */

            $questions = $allQuestions;

            if (empty($questions)) {

                \Log::error(
                    'No Questions Returned'
                );

                return false;
            }

            DB::beginTransaction();

            $existingQuestions = Question::where(
                'material_id',
                $material->id
            )
            ->pluck('question_text')
            ->toArray();

            $savedCount = 0;

            /*
            |--------------------------------------------------------------------------
            | Save Questions
            |--------------------------------------------------------------------------
            */


            foreach ($questions as $item) {
                 if (
                    preg_match('/[A-Za-z]{4,}/', $item['question'])
                ) {

                    \Log::warning(
                        'English Question Rejected',
                        $item
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Normalize GPT Response
                |--------------------------------------------------------------------------
                */

                // Convert indexed options array to A/B/C/D format
                if (
                    isset($item['options']) &&
                    is_array($item['options']) &&
                    array_is_list($item['options']) &&
                    count($item['options']) === 4
                ) {

                    $item['options'] = [
                        'A' => trim($item['options'][0]),
                        'B' => trim($item['options'][1]),
                        'C' => trim($item['options'][2]),
                        'D' => trim($item['options'][3]),
                    ];
                }

                // Convert answer text -> correct_answer key
                if (
                    empty($item['correct_answer']) &&
                    !empty($item['answer']) &&
                    !empty($item['options'])
                ) {

                    $answerText = trim($item['answer']);

                    foreach ($item['options'] as $key => $value) {

                        if (
                            mb_strtolower(trim($value)) ===
                            mb_strtolower($answerText)
                        ) {

                            $item['correct_answer'] = strtoupper($key);

                            break;
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Reject English Questions
                |--------------------------------------------------------------------------
                */

                if (
                    preg_match('/[A-Za-z]{4,}/', $item['question'])
                ) {

                    \Log::warning(
                        'English Question Rejected',
                        $item
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Validate Question
                |--------------------------------------------------------------------------
                */

                if (empty($item['question'])) {

                    continue;
                }

                if (
                    !isset($item['options']) ||
                    !is_array($item['options']) ||
                    count($item['options']) !== 4
                ) {

                    continue;
                }

                if (empty($item['correct_answer'])) {

                    continue;
                }

                $correctAnswer = strtoupper(
                    trim($item['correct_answer'])
                );

                if (
                    !in_array(
                        $correctAnswer,
                        ['A', 'B', 'C', 'D']
                    )
                ) {

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Duplicate Check
                |--------------------------------------------------------------------------
                */

               if (
                    in_array(
                        trim($item['question']),
                        $existingQuestions
                    )
                ) {

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Create Question
                |--------------------------------------------------------------------------
                */

                $question = Question::create([

                    'material_id'     => $material->id,
                    'grade_id'        => $material->grade_id,
                    'subject_id'      => $material->subject_id,
                    'lesson_id'       => $material->lesson_id,

                    'question_text'   => trim($item['question']),

                    'question_type'   => 'mcq',
                    'difficulty'      => $item['difficulty'] ?? 'medium',

                    'correct_answer'  => $correctAnswer,

                    'explanation'     => $item['explanation'] ?? '',

                    'is_ai_generated' => true,
                    'source_type'     => 'openai',
                    'status'          => 'published',
                ]);

                /*
                |--------------------------------------------------------------------------
                | Save Options
                |--------------------------------------------------------------------------
                */

                foreach ($item['options'] as $key => $value) {

                    $key = strtoupper(trim($key));

                    QuestionOption::create([

                        'question_id' => $question->id,

                        'option_key'  => $key,

                        'option_text' => trim($value),

                        'is_correct'  => (
                            $key === $correctAnswer
                        ),
                    ]);
                }

                $savedCount++;
            }

            DB::commit();

            return true;

        } catch (\Exception $e) {

            DB::rollBack();

            \Log::error(
                'AI Question Generation Failed'
            );

            \Log::error(
                $e->getMessage()
            );

            \Log::error(
                $e->getTraceAsString()
            );

            return false;
        }
    }

    // This method is for testing with individual chunks without going through the whole process

    public function generateFromChunk($chunk)
    {
        try {

            $openAI = new OpenAIService();

            $response = $openAI->generateQuestions(
                $chunk->content,
                5
            );

            $decoded = json_decode(
                trim($response),
                true
            );

            if (json_last_error() !== JSON_ERROR_NONE) {

                \Log::error(
                    'JSON Decode Error',
                    [
                        'chunk_id' => $chunk->id,
                        'error' => json_last_error_msg()
                    ]
                );

                return false;
            }

            if (
                !isset($decoded['questions']) ||
                !is_array($decoded['questions'])
            ) {

                \Log::error(
                    'Invalid AI Response',
                    [
                        'chunk_id' => $chunk->id,
                        'response' => $response
                    ]
                );

                return false;
            }

            $questions = $decoded['questions'];

            $material = $chunk->material;

            DB::beginTransaction();

            $existingQuestions = Question::where(
                'material_id',
                $material->id
            )
            ->pluck('question_text')
            ->toArray();

            $savedCount = 0;

            foreach ($questions as $item) {

                if (
                    empty($item['question'])
                ) {
                    continue;
                }

                if (
                    !isset($item['options']) ||
                    count($item['options']) !== 4
                ) {
                    continue;
                }

                $correctAnswer = strtoupper(
                    trim(
                        $item['correct_answer'] ?? ''
                    )
                );

                if (
                    !in_array(
                        $correctAnswer,
                        ['A','B','C','D']
                    )
                ) {
                    continue;
                }

                if (
                    in_array(
                        trim($item['question']),
                        $existingQuestions
                    )
                ) {
                    continue;
                }

                $question = Question::create([

                    'material_id' => $material->id,

                    'grade_id' => $material->grade_id,

                    'subject_id' => $material->subject_id,

                    'lesson_id' => $material->lesson_id,

                    'question_text' => trim(
                        $item['question']
                    ),

                    'question_type' => 'mcq',

                    'difficulty' => strtolower(
                        $item['difficulty']
                        ?? 'medium'
                    ),

                    'correct_answer' => $correctAnswer,

                    'explanation' => $item['explanation']
                        ?? '',

                    'is_ai_generated' => true,

                    'source_type' => 'openai',

                    'status' => 'published',
                ]);

                foreach (
                    $item['options']
                    as $key => $value
                ) {

                    QuestionOption::create([

                        'question_id' => $question->id,

                        'option_key' => strtoupper($key),

                        'option_text' => trim($value),

                        'is_correct' =>
                            strtoupper($key)
                            === $correctAnswer,
                    ]);
                }

                $savedCount++;
            }

            DB::commit();

            $chunk->update([
                'status' => 'processed'
            ]);

            return true;

        } catch (\Exception $e) {

            DB::rollBack();

            \Log::error(
                'Chunk Generation Failed',
                [
                    'chunk_id' => $chunk->id,
                    'error' => $e->getMessage()
                ]
            );

            $chunk->update([
                'status' => 'failed'
            ]);

            return false;
        }
    }
}
