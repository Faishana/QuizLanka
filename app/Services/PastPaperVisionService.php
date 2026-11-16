<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PastPaperVisionService
{
    protected OpenAIService $openAIService;

    public function __construct(OpenAIService $openAIService)
    {
        $this->openAIService = $openAIService;
    }

    /**
     * Extract genuine MCQs from past-paper page images.
     *
     * IMPORTANT:
     * - Extract only what is visible.
     * - Never invent text.
     * - Never solve questions.
     * - Never create question-level bounding boxes.
     * - Only visual media gets bounding boxes.
     */
    public function extractQuestions(array $images): array
    {
        if (empty($images)) {
            Log::warning('No images provided to Past Paper Vision service');
            return [];
        }

        $rawQuestions = $this->openAIService
            ->extractPastPaperQuestionsFromImages($images);

        if (!is_array($rawQuestions)) {
            Log::error('Past Paper Vision returned invalid response', [
                'type' => gettype($rawQuestions),
            ]);

            return [];
        }

        $normalized = [];

        foreach ($rawQuestions as $item) {

            if (!is_array($item)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Page
            |--------------------------------------------------------------------------
            */

            $page = isset($item['page'])
                ? (int) $item['page']
                : 0;

            if ($page <= 0) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Question Number
            |--------------------------------------------------------------------------
            */

            $questionNumber = isset($item['question_number'])
                ? (int) $item['question_number']
                : 0;

            if ($questionNumber <= 0) {
                Log::warning(
                    'Past Paper Question Invalid Number',
                    [
                        'page' => $page,
                        'question_number' => $questionNumber,
                    ]
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Question Text
            |--------------------------------------------------------------------------
            */

            $questionText = trim(
                (string) ($item['question'] ?? '')
            );

            if ($questionText === '') {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Options
            |--------------------------------------------------------------------------
            */

            $rawOptions = $item['options'] ?? [];

            if (!is_array($rawOptions)) {
                continue;
            }

            $options = [];

            foreach (['A', 'B', 'C', 'D'] as $key) {

                if (!array_key_exists($key, $rawOptions)) {
                    $options = [];
                    break;
                }

                $value = trim(
                    (string) $rawOptions[$key]
                );

                if ($value === '') {
                    $options = [];
                    break;
                }

                /*
                |--------------------------------------------------------------------------
                | Reject obvious hallucinated placeholder options
                |--------------------------------------------------------------------------
                */

                if ($this->isPlaceholderOption($value, $key)) {

                    Log::warning(
                        'Possible Hallucinated Past Paper Option Rejected',
                        [
                            'page' => $page,
                            'question_number' => $questionNumber,
                            'option' => $key,
                            'value' => $value,
                        ]
                    );

                    $options = [];
                    break;
                }

                $options[$key] = $value;
            }

            /*
            |--------------------------------------------------------------------------
            | Must contain complete A-D MCQ
            |--------------------------------------------------------------------------
            */

            if (count($options) !== 4) {

                Log::warning(
                    'Incomplete Past Paper MCQ Ignored',
                    [
                        'page' => $page,
                        'question_number' => $questionNumber,
                    ]
                );

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Media
            |--------------------------------------------------------------------------
            */

            $media = $this->normalizeMedia(
                $item['media'] ?? []
            );

            /*
            |--------------------------------------------------------------------------
            | Final Question
            |--------------------------------------------------------------------------
            */

            $normalized[] = [

                'page' => $page,

                'question_number' => $questionNumber,

                'question' => $questionText,

                'options' => $options,

                /*
                |--------------------------------------------------------------------------
                | NEVER store question-level bounding box
                |--------------------------------------------------------------------------
                */

                'bounding_box' => null,

                'media' => $media,

                'correct_answer' => null,

                'difficulty' => null,

                'explanation' => null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Remove duplicates
        |--------------------------------------------------------------------------
        */

        $unique = [];

        foreach ($normalized as $question) {

            $key = implode('|', [
                $question['page'],
                $question['question_number'],
                mb_strtolower(
                    trim($question['question'])
                ),
            ]);

            if (isset($unique[$key])) {

                Log::warning(
                    'Duplicate Past Paper Question Removed',
                    [
                        'page' =>
                            $question['page'],

                        'question_number' =>
                            $question['question_number'],
                    ]
                );

                continue;
            }

            $unique[$key] = $question;
        }

        $normalized = array_values($unique);

        /*
        |--------------------------------------------------------------------------
        | Sort
        |--------------------------------------------------------------------------
        */

        usort(
            $normalized,
            function ($a, $b) {

                $pageCompare =
                    ($a['page'] ?? 0)
                    <=>
                    ($b['page'] ?? 0);

                if ($pageCompare !== 0) {
                    return $pageCompare;
                }

                return
                    ($a['question_number'] ?? 0)
                    <=>
                    ($b['question_number'] ?? 0);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Logging
        |--------------------------------------------------------------------------
        */

        $mediaQuestionCount = collect($normalized)
            ->filter(
                fn ($question) =>
                    !empty($question['media'])
            )
            ->count();

        Log::info(
            'Past Paper Vision Extraction Completed',
            [
                'images' =>
                    count($images),

                'questions' =>
                    count($normalized),

                'media_questions' =>
                    $mediaQuestionCount,
            ]
        );

        return $normalized;
    }

    /**
     * Reject obvious placeholder/hallucinated options.
     */
    protected function isPlaceholderOption(
        string $value,
        string $key
    ): bool {

        $normalized = mb_strtolower(
            trim($value)
        );

        $placeholders = [

            'පිළිතුර a',
            'පිළිතුර b',
            'පිළිතුර c',
            'පිළිතුර d',

            'පිලිතුර a',
            'පිලිතුර b',
            'පිලිතුර c',
            'පිලිතුර d',

            'answer a',
            'answer b',
            'answer c',
            'answer d',

            'option a',
            'option b',
            'option c',
            'option d',

            'option a.',
            'option b.',
            'option c.',
            'option d.',
        ];

        if (in_array($normalized, $placeholders, true)) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Do not reject legitimate options such as:
        |
        | A
        | B
        | C
        | D
        |
        | because these can genuinely appear in examination papers.
        |--------------------------------------------------------------------------
        */

        return false;
    }

    /**
     * Normalize visual media.
     */
    protected function normalizeMedia($media): array
    {
        if (!is_array($media)) {
            return [];
        }

        $normalized = [];

        $allowedTypes = [
            'diagram',
            'table',
            'graph',
            'chart',
            'image',
            'photo',
            'map',
            'figure',
            'illustration',
        ];

        foreach ($media as $item) {

            if (!is_array($item)) {
                continue;
            }

            $type = strtolower(
                trim(
                    (string) ($item['type'] ?? '')
                )
            );

            if (!in_array(
                $type,
                $allowedTypes,
                true
            )) {
                continue;
            }

            $boundingBox =
                $this->normalizeMediaBoundingBox(
                    $item['bounding_box'] ?? null
                );

            if (!$boundingBox) {
                continue;
            }

            $normalized[] = [

                'type' => $type,

                'bounding_box' => $boundingBox,

            ];
        }

        return $normalized;
    }

    /**
     * Normalize media bounding box.
     */
    protected function normalizeMediaBoundingBox(
        $box
    ): ?array {

        if (!is_array($box)) {
            return null;
        }

        if (
            !isset($box['x']) ||
            !isset($box['y']) ||
            !isset($box['width']) ||
            !isset($box['height'])
        ) {
            return null;
        }

        $x = (float) $box['x'];
        $y = (float) $box['y'];
        $width = (float) $box['width'];
        $height = (float) $box['height'];

        if (
            $x < 0 ||
            $y < 0 ||
            $width <= 0 ||
            $height <= 0
        ) {
            return null;
        }

        return [
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
        ];
    }
}
