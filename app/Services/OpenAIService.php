<?php

namespace App\Services;

use OpenAI;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OpenAIService
{
    protected $client;
    protected $model;
    protected $visionModel;

    public function __construct()
    {
        $this->client = OpenAI::client(
            env('OPENAI_API_KEY')
        );

        $this->model = env('OPENAI_MODEL', 'gpt-4o-mini');
        $this->visionModel = env('OPENAI_VISION_MODEL', 'gpt-4o');
    }

    /*
    |--------------------------------------------------------------------------
    | LESSON MATERIAL: Generate MCQs from Text (GPT-4o-mini)
    |--------------------------------------------------------------------------
    */

    public function generateQuestions(
        string $text,
        int $questionCount = 7
    ) {

        // ✅ Improved prompt
        $prompt = <<<PROMPT
Generate exactly {$questionCount} high-quality Sinhala medium multiple-choice questions based ONLY on the educational lesson content below.

Requirements:

- Ignore cover pages, ISBN, publisher information, ministry names, acknowledgements, table of contents, page numbers and printing information.
- Generate questions only from educational concepts.
- Cover different concepts. Never ask the same concept twice.
- Questions must be suitable for Sri Lankan Grade students.
- Questions must test understanding, application and reasoning.
- Do not copy sentences directly from the lesson.
- Every question must have exactly four options (A,B,C,D).
- Only one option is correct.
- Wrong answers must be realistic.
- Difficulty must be easy, medium or hard.
- Provide a short Sinhala explanation.
- Return ONLY JSON.

Lesson:

{$text}
PROMPT;

        try {

            $response = $this->client->chat()->create([
                'model' => $this->model,

                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a Sri Lankan education expert creating exam questions. Generate only valid JSON with the schema: {"questions":[{"question":"","options":{"A":"","B":"","C":"","D":""},"correct_answer":"","difficulty":"","explanation":""}]}'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],

                // ✅ Temperature reduced to 0 for consistency
                'temperature' => 0,

                // ✅ Max tokens kept at 1200
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
            // ✅ Improved error logging with trace
            Log::error('OpenAI Lesson Generation Failed', [
                'model' => $this->model,
                'text_length' => mb_strlen($text),
                'question_count' => $questionCount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PAST PAPER: Extract MCQs from Images (GPT-4o Vision)
    |--------------------------------------------------------------------------
    */

    public function extractQuestionsFromImages(array $images): array
    {
        $allQuestions = [];

        // Process images in batches of 5 to prevent token overflow
        $batches = array_chunk($images, 5);

        foreach ($batches as $batchIndex => $batch) {
            Log::info('Processing Vision Batch', [
                'batch' => $batchIndex + 1,
                'total_batches' => count($batches),
                'images' => count($batch)
            ]);

            $content = [];

            // ✅ IMPROVED: Past paper extraction prompt
            $content[] = [
                'type' => 'text',
                'text' => '
You are reading a Sri Lankan school examination paper.

Your task is ONLY to detect standalone Multiple Choice Questions (MCQs).

Return every MCQ exactly once.
Never return duplicate questions.

STRICT RULES

1. Extract ONLY complete MCQs.

    A complete MCQ MUST satisfy ALL of the following:

    ✓ has one question
    ✓ has exactly four options
    ✓ options are A,B,C,D
    ✓ all option text is readable

    If ANY of these conditions are not met,
    DO NOT return that question.
2. Ignore essay questions.
3. Ignore structured questions.
4. Ignore (i), (ii), (iii).
5. Ignore (a), (b), (c).
6. Ignore fill-in-the-blanks.
7. Ignore matching questions.
8. Ignore instructions.
9. Ignore section titles.
10. Ignore page headers and footers.
11. Ignore publisher information.
12. Ignore marks.
13. Ignore answer sheets.
14. Ignore tables that are not part of an MCQ.
15. If a question has no four options, DO NOT return it.
16. If any part of a question cannot be read clearly, skip that question.
17. Do NOT guess missing text.
18. Do NOT invent options.
19. Do NOT merge two different questions into one.
20. Each returned question must belong to exactly one page.
21. Ignore introductory text such as
"Answer Questions 31–35",
"Refer to the following graph",
"Use the following diagram"
unless the actual MCQ with four options is also present.

For every valid MCQ return:

page = the page number of the uploaded page image (starting from 1)
- question_number
- question
- options
- bounding_box

The bounding_box MUST completely cover:

- question text
- option A
- option B
- option C
- option D
- any diagram, graph, table or image belonging to that question

The bounding_box coordinates must use the original page image.

x = left
y = top
width = width of the entire question
height = height of the entire question

Do not estimate. Return the tightest rectangle that fully contains the complete MCQ.

Return ONLY valid JSON.

Output Format:

{
  "questions": [
    {
      "page": 3,
      "question_number": 12,
      "question": "Which of the following is a vertebrate?",
      "options": {
        "A": "Fish",
        "B": "Earthworm",
        "C": "Snail",
        "D": "Spider"
      },
      "bounding_box": {
        "x": 145,
        "y": 822,
        "width": 905,
        "height": 462
      }
    }
  ]
}

The bounding_box must surround the ENTIRE QUESTION including:

- question text
- all four options
- diagrams
- graphs
- tables
- figures

Coordinates are in pixels relative to the page image.
'
            ];

            foreach ($batch as $pageData) {

                $imagePath = $pageData['local_path'];

                if (!file_exists($imagePath)) {

                    Log::warning('Image not found', [
                        'path' => $imagePath,
                    ]);

                    continue;
                }

                $base64 = base64_encode(
                    file_get_contents($imagePath)
                );

                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:image/jpeg;base64,' . $base64,
                    ],
                ];
            }

            try {
                $response = $this->client->chat()->create([
                    'model' => $this->visionModel,

                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $content
                        ]
                    ],

                    'temperature' => 0,

                    'max_completion_tokens' => 4000,

                    // ✅ IMPROVED: Schema with question_number
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'extracted_questions',
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
                                                    'type' => 'integer'
                                                ],

                                                'question_number' => [
                                                    'type' => 'integer'
                                                ],

                                                'question' => [
                                                    'type' => 'string'
                                                ],

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
                                                'bounding_box' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'x' => [
                                                            'type' => 'integer'
                                                        ],
                                                        'y' => [
                                                            'type' => 'integer'
                                                        ],
                                                        'width' => [
                                                            'type' => 'integer'
                                                        ],
                                                        'height' => [
                                                            'type' => 'integer'
                                                        ]
                                                    ],
                                                    'required' => [
                                                        'x',
                                                        'y',
                                                        'width',
                                                        'height'
                                                    ],
                                                    'additionalProperties' => false
                                                ],
                                            ],
                                            'required' => [
                                                'page',
                                                'question_number',
                                                'question',
                                                'options',
                                                'bounding_box'
                                            ],
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

                $json = $response
                    ->choices[0]
                    ->message
                    ->content;

                    Log::info('Vision Raw Response', [
                    'batch' => $batchIndex + 1,
                    'response' => $json,
                ]);

                $decoded = json_decode($json, true);

                if (isset($decoded['questions']) && is_array($decoded['questions'])) {
                    $allQuestions = array_merge($allQuestions, $decoded['questions']);
                }

            } catch (\Exception $e) {
                Log::error('Vision API Batch Failed', [
                    'batch' => $batchIndex + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $allQuestions;
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
