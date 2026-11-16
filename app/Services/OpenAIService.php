<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OpenAIService
{
    protected \OpenAI\Client $client;
    protected string $model;
    protected string $visionModel;

    public function __construct()
    {
        $this->client = OpenAI::client(
            config('services.openai.api_key')
        );

        $this->model = config(
            'services.openai.model',
            'gpt-4o-mini'
        );

        $this->visionModel = config(
            'services.openai.vision_model',
            'gpt-4o'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | LESSON MATERIAL: Generate MCQs from Text (GPT-4o-mini)
    |--------------------------------------------------------------------------
    */

    public function generateQuestions(
        string $text,
        int $questionCount = 7,
        string $medium = 'si',
        string $subject = '',
        string $grade = ''
    ): string {

        // ✅ Validate required parameters
        if (empty(trim($subject))) {
            throw new \InvalidArgumentException(
                'Subject is required for question generation.'
            );
        }

        if (empty(trim($grade))) {
            throw new \InvalidArgumentException(
                'Grade is required for question generation.'
            );
        }

        // ✅ Language mapping
        $language = match ($medium) {
            'si' => 'Sinhala',
            'en' => 'English',
            'ta' => 'Tamil',
            default => 'Sinhala',
        };

        // ✅ Subject-Locked Prompt with Grade and Medium
        $prompt = <<<PROMPT
Generate exactly {$questionCount} high-quality multiple-choice questions.

============================================================
EDUCATIONAL CONTEXT
============================================================

Subject: {$subject}
Grade: {$grade}
Medium: {$language}

============================================================
ABSOLUTE SUBJECT LOCK
============================================================

Generate questions ONLY for:

SUBJECT: {$subject}

Every question must test knowledge that belongs to {$subject}.

NEVER generate a question from another school subject.

Do not use unrelated outside knowledge.

If the supplied lesson content does not contain enough valid
{$subject} content, return fewer questions.

============================================================
SOURCE LOCK
============================================================

Use ONLY the supplied lesson content.

Do not invent educational facts.

Do not use outside information to fill missing content.

============================================================
LANGUAGE
============================================================

Question: {$language}
Options: {$language}
Explanation: {$language}

============================================================
QUALITY
============================================================

- Sri Lankan {$grade} level
- Four options A/B/C/D
- One correct answer
- Realistic distractors
- Easy/medium/hard
- Understanding/application/reasoning
- Different concepts
- No duplicate concepts
- No metadata questions
- No cover/ISBN/publisher/table-of-contents questions

============================================================
FINAL CHECK
============================================================

Before returning each question verify:

1. It belongs to {$subject}.
2. It is supported by the supplied lesson.
3. It is suitable for {$grade}.
4. It is written in {$language}.

If any condition fails, omit the question.

============================================================
OUTPUT
============================================================

Return ONLY valid JSON.

Lesson:

{$text}
PROMPT;

        try {

            $response = $this->client->chat()->create([
                'model' => $this->model,

                'messages' => [
                    [
                        'role' => 'system',
                        'content' => <<<SYSTEM
You are a strict Sri Lankan school examination question generator.

SUBJECT:
{$subject}

GRADE:
{$grade}

LANGUAGE:
{$language}

The subject is a HARD constraint.

Generate questions ONLY from the requested subject.

Never generate questions from another school subject.

Questions must be based ONLY on the supplied lesson content.

If the content is insufficient, return fewer questions.

Never use unrelated outside knowledge.

Question, options and explanation must be written in {$language}.

Return ONLY valid JSON using the supplied schema.
SYSTEM
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],

                'temperature' => 0,

                'max_completion_tokens' => 1200,

                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'mcq_questions',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'questions' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'question' => ['type' => 'string'],
                                            'options' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'A' => ['type' => 'string'],
                                                    'B' => ['type' => 'string'],
                                                    'C' => ['type' => 'string'],
                                                    'D' => ['type' => 'string'],
                                                ],
                                                'required' => ['A', 'B', 'C', 'D'],
                                                'additionalProperties' => false
                                            ],
                                            'correct_answer' => ['type' => 'string', 'enum' => ['A', 'B', 'C', 'D']],
                                            'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
                                            'explanation' => ['type' => 'string'],
                                        ],
                                        'required' => ['question', 'options', 'correct_answer', 'difficulty', 'explanation'],
                                        'additionalProperties' => false
                                    ]
                                ]
                            ],
                            'required' => ['questions'],
                            'additionalProperties' => false
                        ]
                    ]
                ]
            ]);

            $content = $response
                ->choices[0]
                ->message
                ->content;

            return $content;

        } catch (\Exception $e) {
            Log::error('OpenAI Lesson Generation Failed', [
                'model' => $this->model,
                'text_length' => mb_strlen($text),
                'question_count' => $questionCount,
                'medium' => $medium,
                'language' => $language,
                'subject' => $subject,
                'grade' => $grade,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SUBJECT VALIDATOR: Validate generated questions
    |--------------------------------------------------------------------------
    */

    public function validateQuestionSubject(
        string $question,
        array $options,
        string $subject,
        string $grade
    ): bool {

        $optionsJson = json_encode(
            $options,
            JSON_UNESCAPED_UNICODE
        );

        $prompt = <<<PROMPT
Determine whether the following multiple-choice question belongs
to the requested school subject.

REQUESTED SUBJECT:
{$subject}

TARGET GRADE:
{$grade}

QUESTION:
{$question}

OPTIONS:
{$optionsJson}

Rules:

- Return true ONLY if the question clearly belongs to {$subject}.
- Return false if it belongs to another school subject.
- Do not judge based only on the language.
- Judge based on the actual educational concept being tested.
- If uncertain, return false.

Return ONLY JSON.
PROMPT;

        try {

            $response = $this->client->chat()->create([
                'model' => $this->model,

                'messages' => [
                    [
                        'role' => 'system',
                        'content' =>
                            'You are a strict educational subject classifier. '
                            . 'Return true only when the question belongs to '
                            . 'the requested subject.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],

                'temperature' => 0,

                'max_completion_tokens' => 100,

                'response_format' => [
                    'type' => 'json_schema',

                    'json_schema' => [
                        'name' => 'subject_validation',

                        'strict' => true,

                        'schema' => [
                            'type' => 'object',

                            'properties' => [
                                'is_valid' => [
                                    'type' => 'boolean'
                                ]
                            ],

                            'required' => [
                                'is_valid'
                            ],

                            'additionalProperties' => false
                        ]
                    ]
                ]
            ]);

            $content =
                $response
                    ->choices[0]
                    ->message
                    ->content;

            $decoded =
                json_decode(
                    $content,
                    true
                );

            return
                ($decoded['is_valid'] ?? false) === true;

        } catch (\Throwable $e) {

            Log::error(
                'Question Subject Validation Failed',
                [
                    'subject' => $subject,
                    'grade' => $grade,
                    'question' => mb_substr(
                        $question,
                        0,
                        300
                    ),
                    'error' => $e->getMessage(),
                ]
            );

            // SAFE DEFAULT: reject if validation fails
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BATCH VALIDATOR: Validate multiple questions at once
    |--------------------------------------------------------------------------
    */

    public function validateQuestionsBatch(
        array $questions,
        string $subject,
        string $grade
    ): array {

        if (empty($questions)) {
            return [];
        }

        $questionsJson = json_encode(
            $questions,
            JSON_UNESCAPED_UNICODE
        );

        $prompt = <<<PROMPT
Validate whether each of the following multiple-choice questions belongs
to the requested school subject.

REQUESTED SUBJECT:
{$subject}

TARGET GRADE:
{$grade}

QUESTIONS:
{$questionsJson}

For each question, determine if it clearly belongs to {$subject}.

Return an array of validation results in the same order as the input questions.

Rules:

- is_valid = true ONLY if the question clearly belongs to {$subject}
- is_valid = false if it belongs to another school subject
- Do not judge based only on the language
- Judge based on the actual educational concept being tested
- If uncertain, set is_valid = false

Return ONLY valid JSON.
PROMPT;

        try {

            $response = $this->client->chat()->create([
                'model' => $this->model,

                'messages' => [
                    [
                        'role' => 'system',
                        'content' =>
                            'You are a strict educational subject classifier. '
                            . 'Validate each question against the requested subject.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],

                'temperature' => 0,

                'max_completion_tokens' => 800,

                'response_format' => [
                    'type' => 'json_schema',

                    'json_schema' => [
                        'name' => 'batch_subject_validation',

                        'strict' => true,

                        'schema' => [
                            'type' => 'object',

                            'properties' => [
                                'validations' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'is_valid' => [
                                                'type' => 'boolean'
                                            ]
                                        ],
                                        'required' => ['is_valid'],
                                        'additionalProperties' => false
                                    ]
                                ]
                            ],

                            'required' => ['validations'],
                            'additionalProperties' => false
                        ]
                    ]
                ]
            ]);

            $content =
                $response
                    ->choices[0]
                    ->message
                    ->content;

            $decoded =
                json_decode(
                    $content,
                    true
                );

            $validations =
                $decoded['validations'] ?? [];

            // Ensure we return the same number of results
            while (count($validations) < count($questions)) {
                $validations[] = ['is_valid' => false];
            }

            return $validations;

        } catch (\Throwable $e) {

            Log::error(
                'Batch Subject Validation Failed',
                [
                    'subject' => $subject,
                    'grade' => $grade,
                    'question_count' => count($questions),
                    'error' => $e->getMessage(),
                ]
            );

            // SAFE DEFAULT: reject all questions
            return array_fill(
                0,
                count($questions),
                ['is_valid' => false]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAST PAPER: Extract Exact MCQs From Page Images
    | GPT-4o Vision ONLY
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | This method is ONLY for Past Papers.
    |
    | It does NOT:
    | - generate new questions
    | - solve questions
    | - determine correct answers
    | - generate explanations
    | - translate questions
    | - rewrite question text
    |
    | It extracts the existing question exactly as visible
    | in the original examination paper.
    |
    */

    public function extractPastPaperQuestionsFromImages(array $images): array
    {
        if (empty($images)) {

            Log::warning(
                'No images provided for Past Paper extraction'
            );

            return [];
        }

        $allQuestions = [];

        /*
        |--------------------------------------------------------------------------
        | ONE PAGE = ONE VISION REQUEST
        |--------------------------------------------------------------------------
        */

        foreach ($images as $pageIndex => $pageData) {

            $imagePath = $pageData['local_path'] ?? null;

            $actualPage = isset($pageData['page'])
                ? (int) $pageData['page']
                : ($pageIndex + 1);

            Log::info(
                'Past Paper Vision Page Started',
                [
                    'page' => $actualPage,
                    'index' => $pageIndex + 1,
                    'total_pages' => count($images),
                ]
            );

            if (
                empty($imagePath) ||
                !file_exists($imagePath)
            ) {

                Log::warning(
                    'Past Paper page image not found',
                    [
                        'page' => $actualPage,
                        'path' => $imagePath,
                    ]
                );

                continue;
            }

            try {

                /*
                |--------------------------------------------------------------------------
                | MIME
                |--------------------------------------------------------------------------
                */

                $mimeType = mime_content_type(
                    $imagePath
                );

                if (!$mimeType) {
                    $mimeType = 'image/jpeg';
                }

                /*
                |--------------------------------------------------------------------------
                | Base64
                |--------------------------------------------------------------------------
                */

                $fileContents = file_get_contents(
                    $imagePath
                );

                if ($fileContents === false) {
                    continue;
                }

                $base64 = base64_encode(
                    $fileContents
                );

                /*
                |--------------------------------------------------------------------------
                | Prompt
                |--------------------------------------------------------------------------
                */

                $prompt = <<<PROMPT
You are an expert Sri Lankan examination paper OCR and MCQ extraction system.

You are given ONE PAGE of a real examination past paper.

Your job is ONLY to extract genuine Multiple Choice Questions that are visibly present on this page.

PAGE NUMBER: {$actualPage}

============================================================
ABSOLUTE RULE — NEVER INVENT
============================================================

NEVER generate text that is not visible in the image.

Every character in the question and every answer option must come from the supplied image.

NEVER invent:

- questions
- answers
- options
- words
- numbers
- Sinhala text
- English text
- symbols
- diagrams
- labels

NEVER use placeholders.

NEVER write:

"පිළිතුර A"
"පිළිතුර B"
"පිළිතුර C"
"පිළිතුර D"

unless those exact words are actually visible in the image.

NEVER write:

"Option A"
"Option B"
"Option C"
"Option D"

unless actually visible.

If any part of an MCQ is unreadable or uncertain, DO NOT return that MCQ.

Missing a question is better than inventing one.

============================================================
EXTRACT ONLY REAL MCQs
============================================================

A question is an MCQ only when the page visibly shows:

- a question number
- question text
- selectable answer choices

Normally four choices are present.

Do NOT assume every numbered item is an MCQ.

Do NOT extract:

- essays
- structured questions
- short answers
- written-answer questions
- calculations requiring written solutions
- matching questions
- fill-in-the-blanks
- instructions
- headings
- page information

============================================================
PRESERVE ORIGINAL TEXT
============================================================

Copy the visible text exactly.

Do NOT:

- paraphrase
- summarize
- translate
- correct spelling
- correct grammar
- solve the question
- determine the correct answer

Preserve:

- Sinhala
- English
- numbers
- mathematical symbols
- scientific notation
- punctuation
- brackets
- Roman numerals

============================================================
QUESTION NUMBER
============================================================

Use the actual question number visible in the paper.

Do NOT renumber questions.

============================================================
OPTIONS
============================================================

Return exactly A, B, C and D when all four are clearly visible.

If one option is missing or unreadable:

DO NOT return the question.

============================================================
VISUAL MEDIA
============================================================

If a genuine MCQ contains a visual element needed to answer it,
return its bounding box.

Allowed:

- diagram
- table
- graph
- chart
- map
- figure
- image
- photo
- illustration

The bounding box must contain ONLY the visual element.

Do NOT include question text or options inside it.

If there is no important visual element:

"media": []

============================================================
OUTPUT
============================================================

Return ONLY valid JSON.

{
    "questions": [
        {
            "page": {$actualPage},
            "question_number": 1,
            "question": "EXACT VISIBLE QUESTION",
            "options": {
                "A": "EXACT VISIBLE OPTION A",
                "B": "EXACT VISIBLE OPTION B",
                "C": "EXACT VISIBLE OPTION C",
                "D": "EXACT VISIBLE OPTION D"
            },
            "media": []
        }
    ]
}

If there are no genuine MCQs:

{
    "questions": []
}

Do not include any other fields.
PROMPT;

                /*
                |--------------------------------------------------------------------------
                | Vision content
                |--------------------------------------------------------------------------
                */

                $content = [

                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],

                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' =>
                                'data:' .
                                $mimeType .
                                ';base64,' .
                                $base64,
                        ],
                    ],

                ];

                /*
                |--------------------------------------------------------------------------
                | API
                |--------------------------------------------------------------------------
                */

                $response = $this->client
                    ->chat()
                    ->create([

                        'model' => $this->visionModel,

                        'messages' => [

                            [
                                'role' => 'system',
                                'content' =>
                                    'You are a strict examination '
                                    . 'paper OCR system. '
                                    . 'Never invent text. '
                                    . 'Extract only visible MCQs. '
                                    . 'If uncertain, omit the question.',
                            ],

                            [
                                'role' => 'user',
                                'content' => $content,
                            ],

                        ],

                        'temperature' => 0,

                        'max_completion_tokens' => 8000,

                        'response_format' => [

                            'type' => 'json_schema',

                            'json_schema' => [

                                'name' =>
                                    'past_paper_mcq_extraction',

                                'strict' => true,

                                'schema' => [

                                    'type' => 'object',

                                    'properties' => [

                                        'questions' => [

                                            'type' => 'array',

                                            'items' => [

                                                'type' => 'object',

                                                'properties' => [

                                                    'page' => [
                                                        'type' => 'integer',
                                                    ],

                                                    'question_number' => [
                                                        'type' => 'integer',
                                                    ],

                                                    'question' => [
                                                        'type' => 'string',
                                                    ],

                                                    'options' => [

                                                        'type' => 'object',

                                                        'properties' => [

                                                            'A' => [
                                                                'type' => 'string',
                                                            ],

                                                            'B' => [
                                                                'type' => 'string',
                                                            ],

                                                            'C' => [
                                                                'type' => 'string',
                                                            ],

                                                            'D' => [
                                                                'type' => 'string',
                                                            ],

                                                        ],

                                                        'required' => [
                                                            'A',
                                                            'B',
                                                            'C',
                                                            'D',
                                                        ],

                                                        'additionalProperties' =>
                                                            false,
                                                    ],

                                                    'media' => [

                                                        'type' => 'array',

                                                        'items' => [

                                                            'type' => 'object',

                                                            'properties' => [

                                                                'type' => [

                                                                    'type' => 'string',

                                                                    'enum' => [
                                                                        'diagram',
                                                                        'table',
                                                                        'graph',
                                                                        'map',
                                                                        'figure',
                                                                        'image',
                                                                        'photo',
                                                                        'illustration',
                                                                        'chart',
                                                                    ],
                                                                ],

                                                                'bounding_box' => [

                                                                    'type' => 'object',

                                                                    'properties' => [

                                                                        'x' => [
                                                                            'type' => 'integer',
                                                                        ],

                                                                        'y' => [
                                                                            'type' => 'integer',
                                                                        ],

                                                                        'width' => [
                                                                            'type' => 'integer',
                                                                        ],

                                                                        'height' => [
                                                                            'type' => 'integer',
                                                                        ],

                                                                    ],

                                                                    'required' => [
                                                                        'x',
                                                                        'y',
                                                                        'width',
                                                                        'height',
                                                                    ],

                                                                    'additionalProperties' =>
                                                                        false,
                                                                ],

                                                            ],

                                                            'required' => [
                                                                'type',
                                                                'bounding_box',
                                                            ],

                                                            'additionalProperties' =>
                                                                false,
                                                        ],
                                                    ],

                                                ],

                                                'required' => [
                                                    'page',
                                                    'question_number',
                                                    'question',
                                                    'options',
                                                    'media',
                                                ],

                                                'additionalProperties' =>
                                                    false,
                                            ],
                                        ],
                                    ],

                                    'required' => [
                                        'questions',
                                    ],

                                    'additionalProperties' =>
                                        false,
                                ],
                            ],
                        ],
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Response
                |--------------------------------------------------------------------------
                */

                $json =
                    $response
                        ->choices[0]
                        ->message
                        ->content;

                Log::info(
                    'Past Paper Vision Raw Response',
                    [
                        'page' => $actualPage,
                        'response' => $json,
                    ]
                );

                if (
                    !is_string($json) ||
                    trim($json) === ''
                ) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | JSON
                |--------------------------------------------------------------------------
                */

                $decoded = json_decode(
                    $json,
                    true
                );

                if (
                    !is_array($decoded) ||
                    !isset($decoded['questions']) ||
                    !is_array($decoded['questions'])
                ) {

                    Log::warning(
                        'Invalid Past Paper Vision JSON',
                        [
                            'page' => $actualPage,
                        ]
                    );

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Validate each question
                |--------------------------------------------------------------------------
                */

                foreach (
                    $decoded['questions']
                    as $question
                ) {

                    if (!is_array($question)) {
                        continue;
                    }

                    $questionNumber =
                        (int) (
                            $question['question_number']
                            ?? 0
                        );

                    $questionText =
                        trim(
                            (string) (
                                $question['question']
                                ?? ''
                            )
                        );

                    $options =
                        $question['options']
                        ?? [];

                    /*
                    |--------------------------------------------------------------------------
                    | Basic validation
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $questionNumber <= 0 ||
                        $questionText === '' ||
                        !is_array($options)
                    ) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Require A-D
                    |--------------------------------------------------------------------------
                    */

                    $valid = true;

                    foreach (
                        ['A', 'B', 'C', 'D']
                        as $key
                    ) {

                        if (
                            !isset($options[$key]) ||
                            trim(
                                (string)
                                    $options[$key]
                            ) === ''
                        ) {

                            $valid = false;
                            break;
                        }
                    }

                    if (!$valid) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Reject placeholders
                    |--------------------------------------------------------------------------
                    */

                    $placeholderFound = false;

                    foreach (
                        ['A', 'B', 'C', 'D']
                        as $key
                    ) {

                        $option =
                            mb_strtolower(
                                trim(
                                    (string)
                                        $options[$key]
                                )
                            );

                        if (
                            in_array(
                                $option,
                                [
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
                                ],
                                true
                            )
                        ) {

                            $placeholderFound = true;
                            break;
                        }
                    }

                    if ($placeholderFound) {

                        Log::warning(
                            'Hallucinated Past Paper MCQ Rejected',
                            [
                                'page' => $actualPage,
                                'question_number' =>
                                    $questionNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Force real page number
                    |--------------------------------------------------------------------------
                    */

                    $question['page'] =
                        $actualPage;

                    /*
                    |--------------------------------------------------------------------------
                    | Normalize
                    |--------------------------------------------------------------------------
                    */

                    $question['question'] =
                        $questionText;

                    $question['options'] = [

                        'A' => trim(
                            (string)
                                $options['A']
                        ),

                        'B' => trim(
                            (string)
                                $options['B']
                        ),

                        'C' => trim(
                            (string)
                                $options['C']
                        ),

                        'D' => trim(
                            (string)
                                $options['D']
                        ),
                    ];

                    $question['media'] =
                        isset($question['media']) &&
                        is_array($question['media'])
                            ? $question['media']
                            : [];

                    /*
                    |--------------------------------------------------------------------------
                    | Never allow question bounding box
                    |--------------------------------------------------------------------------
                    */

                    unset(
                        $question['bounding_box']
                    );

                    $allQuestions[] =
                        $question;
                }

            } catch (\Throwable $e) {

                Log::error(
                    'Past Paper Vision Page Failed',
                    [
                        'page' => $actualPage,
                        'error' => $e->getMessage(),
                    ]
                );

                continue;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Remove duplicates
        |--------------------------------------------------------------------------
        */

        $unique = [];

        foreach ($allQuestions as $question) {

            $page =
                (int) (
                    $question['page']
                    ?? 0
                );

            $number =
                (int) (
                    $question['question_number']
                    ?? 0
                );

            if (
                $page <= 0 ||
                $number <= 0
            ) {
                continue;
            }

            $key =
                $page . '_' . $number;

            if (isset($unique[$key])) {
                continue;
            }

            $unique[$key] =
                $question;
        }

        $questions =
            array_values($unique);

        /*
        |--------------------------------------------------------------------------
        | Sort
        |--------------------------------------------------------------------------
        */

        usort(
            $questions,
            function ($a, $b) {

                $page =
                    ($a['page'] ?? 0)
                    <=>
                    ($b['page'] ?? 0);

                if ($page !== 0) {
                    return $page;
                }

                return
                    ($a['question_number'] ?? 0)
                    <=>
                    ($b['question_number'] ?? 0);
            }
        );

        Log::info(
            'Past Paper Vision Extraction Completed',
            [
                'total_pages' =>
                    count($images),

                'total_questions' =>
                    count($questions),

                'media_questions' =>
                    collect($questions)
                        ->filter(
                            fn ($q) =>
                                !empty($q['media'])
                        )
                        ->count(),
            ]
        );

        return $questions;
    }

    /**
     * Normalize Past Paper visual media.
     */
    protected function normalizePastPaperMedia(
        array $media
    ): array {

        $normalized = [];

        $allowedTypes = [

            'diagram',
            'table',
            'graph',
            'map',
            'figure',
            'image',
            'photo',
            'illustration',
            'chart',

        ];

        foreach ($media as $item) {

            if (!is_array($item)) {
                continue;
            }

            $type = strtolower(
                trim(
                    (string)
                        ($item['type'] ?? '')
                )
            );

            if (
                !in_array(
                    $type,
                    $allowedTypes,
                    true
                )
            ) {
                continue;
            }

            $box =
                $item['bounding_box'] ?? null;

            if (!is_array($box)) {
                continue;
            }

            $x =
                isset($box['x'])
                    ? (int) $box['x']
                    : null;

            $y =
                isset($box['y'])
                    ? (int) $box['y']
                    : null;

            $width =
                isset($box['width'])
                    ? (int) $box['width']
                    : null;

            $height =
                isset($box['height'])
                    ? (int) $box['height']
                    : null;

            if (
                $x === null ||
                $y === null ||
                $width === null ||
                $height === null
            ) {
                continue;
            }

            if (
                $x < 0 ||
                $y < 0 ||
                $width <= 0 ||
                $height <= 0
            ) {
                continue;
            }

            $normalized[] = [

                'type' => $type,

                'bounding_box' => [

                    'x' => $x,

                    'y' => $y,

                    'width' => $width,

                    'height' => $height,

                ],

            ];
        }

        return $normalized;
    }

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL: Translate Questions to Sinhala (If needed)
    |--------------------------------------------------------------------------
    */

    public function translateQuestionsToSinhala(array $questions): array
    {
        if (empty($questions)) {
            return [];
        }

        $json = json_encode(
            ['questions' => $questions],
            JSON_UNESCAPED_UNICODE
        );

        $prompt = <<<PROMPT
Translate the following MCQ questions to Sinhala.

Rules:
- Question must be in Sinhala
- Options must be in Sinhala
- Explanation must be in Sinhala
- Keep option keys A, B, C, D unchanged
- Keep correct_answer unchanged
- Return ONLY valid JSON

{$json}
PROMPT;

        try {
            $response = $this->client->chat()->create([
                'model' => $this->model,

                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional Sinhala translator. Return JSON only with schema: {"questions":[{"question":"","options":{"A":"","B":"","C":"","D":""},"correct_answer":"","difficulty":"","explanation":""}]}'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],

                'temperature' => 0,

                'response_format' => [
                    'type' => 'json_object'
                ]
            ]);

            $decoded = json_decode(
                $response->choices[0]->message->content,
                true
            );

            return $decoded['questions'] ?? [];

        } catch (\Exception $e) {
            Log::error('Translation Failed', [
                'error' => $e->getMessage()
            ]);
            return $questions; // Return original if translation fails
        }
    }

    public function determineCorrectAnswer(string $questionImage): array
    {
        // Download image from R2
        $image = Storage::disk('s3')->get($questionImage);

        $base64 = base64_encode($image);

        $response = $this->client->chat()->create([
            'model' => $this->visionModel,

            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => <<<PROMPT
You are marking a Sri Lankan school past paper.

Look ONLY at this question image.

Rules:

- Read the complete question carefully.
- Read all four options.
- Determine the single correct answer.
- Do NOT guess if the image is unreadable.
- Return ONLY valid JSON.

PROMPT
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:image/jpeg;base64,' . $base64,
                            ]
                        ]
                    ]
                ]
            ],

            'temperature' => 0,

            'max_completion_tokens' => 300,

            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'correct_answer',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'correct_answer' => [
                                'type' => 'string',
                                'enum' => ['A','B','C','D']
                            ],
                            'confidence' => [
                                'type' => 'number'
                            ]
                        ],
                        'required' => [
                            'correct_answer',
                            'confidence'
                        ],
                        'additionalProperties' => false
                    ]
                ]
            ]
        ]);

        $json = json_decode(
            $response->choices[0]->message->content,
            true
        );

        Log::info('AI Answer Generated', [
            'image' => $questionImage,
            'answer' => $json['correct_answer'],
            'confidence' => $json['confidence']
        ]);

        return $json;
    }
}
