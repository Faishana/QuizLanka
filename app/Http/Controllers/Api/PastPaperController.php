<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\PastPaper;
use App\Models\PastPaperQuestion;
use App\Models\PastPaperOption;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PastPaperController extends Controller
{
    /**
     * Allowed A/L streams.
     */
    private const AL_STREAMS = [
        'bio',
        'maths',
        'technology',
        'arts',
        'commerce',
    ];

    /**
     * Common validation rules for creating and updating papers.
     */
    private function paperValidationRules(Request $request): array
    {
        $gradeCategory = Grade::where(
            'id',
            $request->input('grade_id')
        )->value('category');

        $isAl = $gradeCategory === 'al';

        return [
            'grade_id' => [
                'required',
                'integer',
                'exists:grades,id',
            ],

            'subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
            ],

            'al_stream' => [
                Rule::requiredIf($isAl),
                'nullable',
                'string',
                Rule::in(self::AL_STREAMS),
            ],

            'year' => [
                'required',
                'integer',
                'min:1900',
                'max:2100',
            ],

            // Paper time limit (minutes)
            'time_limit_minutes' => [
                'required',
                'integer',
                'min:1',
                'max:600',
            ],

            'medium' => [
                'required',
                Rule::in(['si', 'en', 'ta']),
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'paper_number' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'total_questions' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'questions' => [
                'required',
                'array',
                'min:1',
            ],

            'questions.*.question_number' => [
                'required',
                'integer',
                'min:1',
            ],

            'questions.*.question_text' => [
                'required',
                'string',
            ],

            'questions.*.image_url' => [
                'nullable',
                'string',
                'url',
            ],

            'questions.*.correct_answer' => [
                'required',
                Rule::in(['A', 'B', 'C', 'D']),
            ],

            'questions.*.difficulty' => [
                'nullable',
                Rule::in(['easy', 'medium', 'hard']),
            ],

            'questions.*.explanation' => [
                'nullable',
                'string',
            ],

            'questions.*.options' => [
                'required',
                'array',
                'size:4',
            ],

            'questions.*.options.A' => [
                'required',
                'string',
            ],

            'questions.*.options.B' => [
                'required',
                'string',
            ],

            'questions.*.options.C' => [
                'required',
                'string',
            ],

            'questions.*.options.D' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * Save questions and options.
     */
    private function saveQuestions(
        PastPaper $pastPaper,
        array $questions,
        int $userId
    ): void {
        foreach ($questions as $questionData) {

            $question = PastPaperQuestion::create([
                'past_paper_id' => $pastPaper->id,
                'question_number' => $questionData['question_number'],
                'question_text' => $questionData['question_text'],
                'image_url' => $questionData['image_url'] ?? null,
                'correct_answer' => $questionData['correct_answer'],
                'difficulty' => $questionData['difficulty'] ?? 'medium',
                'explanation' => $questionData['explanation'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($questionData['options'] as $key => $text) {

                PastPaperOption::create([
                    'past_paper_question_id' => $question->id,
                    'option_key' => $key,
                    'option_text' => $text,
                ]);
            }
        }
    }

    /**
     * Display a list of past papers.
     * Admin endpoint.
     */
    public function index(Request $request)
    {
        $query = PastPaper::with([
            'grade:id,name,category',
            'subject:id,name',
        ]);

        if ($request->filled('grade_id')) {
            $query->where('grade_id', $request->grade_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        if ($request->filled('medium')) {
            $query->where('medium', $request->medium);
        }

        if ($request->filled('al_stream')) {
            $query->where('al_stream', $request->al_stream);
        }

        $pastPapers = $query
            ->orderByDesc('year')
            ->orderBy('title')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $pastPapers,
        ]);
    }

    /**
     * Upload a question image to Cloudflare R2.
     */
    public function uploadImage(Request $request)
    {
        $validated = $request->validate([
            'image' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $file = $validated['image'];

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );

        $filename = 'question_' . uniqid('', true) . '.' . $extension;

        $path = 'pastpapers/questions/' . $filename;

        Storage::disk('s3')->put(
            $path,
            file_get_contents($file->getRealPath()),
            [
                'visibility' => 'public',
                'ContentType' => $file->getMimeType(),
            ]
        );

        $url = Storage::disk('s3')->url($path);

        return response()->json([
            'success' => true,
            'message' => 'Question image uploaded successfully.',
            'data' => [
                'image_url' => $url,
                'image_path' => $path,
            ],
        ]);
    }

    /**
     * Store a complete past paper.
     */
    public function store(Request $request)
    {
        $validated = $request->validate(
            $this->paperValidationRules($request)
        );

        $userId = $request->user()->id;

        $grade = Grade::findOrFail($validated['grade_id']);

        // Only A/L papers can have a stream.
        $alStream = $grade->category === 'al'
            ? $validated['al_stream']
            : null;

        $pastPaper = DB::transaction(function () use (
            $validated,
            $userId,
            $alStream
        ) {

            $pastPaper = PastPaper::create([
                'grade_id' => $validated['grade_id'],
                'subject_id' => $validated['subject_id'],
                'al_stream' => $alStream,
                'year' => $validated['year'],
                'time_limit_minutes' => $validated['time_limit_minutes'],
                'medium' => $validated['medium'],
                'title' => $validated['title'],
                'paper_number' => $validated['paper_number'] ?? null,
                'total_questions' => $validated['total_questions']
                    ?? count($validated['questions']),
                'created_by' => $userId,
            ]);

            // Save questions and options.
            $this->saveQuestions(
                $pastPaper,
                $validated['questions'],
                $userId
            );

            return $pastPaper;
        });

        $pastPaper->load([
            'grade:id,name,category',
            'subject:id,name',
            'questions.options',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Past paper created successfully.',
            'data' => $pastPaper,
        ], 201);
    }

    /**
     * Display one past paper.
     * Admin endpoint - includes correct answers.
     */
    public function show($id)
    {
        $pastPaper = PastPaper::with([
            'grade:id,name,category',
            'subject:id,name',
            'questions.options',
        ])->find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $pastPaper,
        ]);
    }

    /**
     * Student: Get one past paper for starting the quiz.
     * Correct answers and explanations are hidden.
     */
    public function studentShow(Request $request, $id)
    {
        $pastPaper = PastPaper::query()
            ->where('id', $id)
            ->with([
                'grade:id,name,category',
                'subject:id,name,slug,icon,color',

                'questions' => function ($query) {
                    $query
                        ->orderBy('question_number')
                        ->select([
                            'id',
                            'past_paper_id',
                            'question_number',
                            'question_text',
                            'image_url',
                            'difficulty',
                        ]);
                },

                'questions.options' => function ($query) {
                    $query->select([
                        'id',
                        'past_paper_question_id',
                        'option_key',
                        'option_text',
                    ]);
                },
            ])
            ->first();

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $pastPaper->id,
                'grade_id' => $pastPaper->grade_id,
                'subject_id' => $pastPaper->subject_id,
                'al_stream' => $pastPaper->al_stream,
                'year' => $pastPaper->year,
                'time_limit_minutes' => $pastPaper->time_limit_minutes,
                'medium' => $pastPaper->medium,
                'title' => $pastPaper->title,
                'paper_number' => $pastPaper->paper_number,
                'total_questions' => $pastPaper->total_questions,
                'grade' => $pastPaper->grade,
                'subject' => $pastPaper->subject,
                'questions' => $pastPaper->questions,
            ],
        ]);
    }

    /**
     * Student: Submit answers for a past paper.
     */
    public function studentSubmit(Request $request, $id)
    {
        $validated = $request->validate([
            'answers' => [
                'required',
                'array',
                'min:1',
            ],

            'answers.*.question_id' => [
                'required',
                'integer',
                'exists:past_paper_questions,id',
                'distinct',
            ],

            'answers.*.answer' => [
                'required',
                Rule::in(['A', 'B', 'C', 'D']),
            ],
        ]);

        $pastPaper = PastPaper::with([
            'grade:id,name,category',
            'subject:id,name,slug,icon,color',
            'questions.options',
        ])->find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        $questionIds = $pastPaper->questions
            ->pluck('id')
            ->toArray();

        foreach ($validated['answers'] as $answer) {

            if (!in_array(
                $answer['question_id'],
                $questionIds
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid question for this past paper.',
                ], 422);
            }
        }

        $submittedAnswers = collect($validated['answers'])
            ->keyBy('question_id')
            ->map(function ($item) {
                return strtoupper($item['answer']);
            });

        $totalQuestions = $pastPaper->questions->count();

        $answeredQuestions = 0;
        $correctAnswers = 0;
        $wrongAnswers = 0;
        $unansweredQuestions = 0;

        $results = [];

        foreach ($pastPaper->questions as $question) {

            $selectedAnswer = $submittedAnswers->get(
                $question->id
            );

            if ($selectedAnswer === null) {

                $unansweredQuestions++;

                $results[] = [
                    'question_id' => $question->id,
                    'question_number' => $question->question_number,
                    'selected_answer' => null,
                    'correct_answer' => $question->correct_answer,
                    'is_correct' => false,
                ];

                continue;
            }

            $answeredQuestions++;

            $isCorrect = $selectedAnswer === strtoupper(
                $question->correct_answer
            );

            if ($isCorrect) {
                $correctAnswers++;
            } else {
                $wrongAnswers++;
            }

            $results[] = [
                'question_id' => $question->id,
                'question_number' => $question->question_number,
                'selected_answer' => $selectedAnswer,
                'correct_answer' => $question->correct_answer,
                'is_correct' => $isCorrect,
            ];
        }

        $percentage = $totalQuestions > 0
            ? round(($correctAnswers / $totalQuestions) * 100, 2)
            : 0;

        return response()->json([
            'success' => true,
            'message' => 'Past paper submitted successfully.',
            'data' => [
                'past_paper' => [
                    'id' => $pastPaper->id,
                    'year' => $pastPaper->year,
                    'medium' => $pastPaper->medium,
                    'title' => $pastPaper->title,
                    'paper_number' => $pastPaper->paper_number,
                ],

                'grade' => $pastPaper->grade,
                'subject' => $pastPaper->subject,

                'summary' => [
                    'total_questions' => $totalQuestions,
                    'answered_questions' => $answeredQuestions,
                    'correct_answers' => $correctAnswers,
                    'wrong_answers' => $wrongAnswers,
                    'unanswered_questions' => $unansweredQuestions,
                    'score' => $correctAnswers,
                    'percentage' => $percentage,
                ],

                'results' => $results,
            ],
        ]);
    }

    /**
     * Update a complete past paper.
     */
    public function update(Request $request, $id)
    {
        $pastPaper = PastPaper::find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        $validated = $request->validate(
            $this->paperValidationRules($request)
        );

        $grade = Grade::findOrFail($validated['grade_id']);

        // Only A/L papers can have a stream.
        $alStream = $grade->category === 'al'
            ? $validated['al_stream']
            : null;

        DB::transaction(function () use (
            $pastPaper,
            $validated,
            $request,
            $alStream
        ) {

            $pastPaper->update([
                'grade_id' => $validated['grade_id'],
                'subject_id' => $validated['subject_id'],
                'al_stream' => $alStream,
                'year' => $validated['year'],
                'time_limit_minutes' => $validated['time_limit_minutes'],
                'medium' => $validated['medium'],
                'title' => $validated['title'],
                'paper_number' => $validated['paper_number'] ?? null,
                'total_questions' => $validated['total_questions']
                    ?? count($validated['questions']),
            ]);

            // Delete old questions and their options.
            $pastPaper->questions()->delete();

            // Save updated questions and options.
            $this->saveQuestions(
                $pastPaper,
                $validated['questions'],
                $request->user()->id
            );
        });

        $pastPaper->load([
            'grade:id,name,category',
            'subject:id,name',
            'questions.options',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Past paper updated successfully.',
            'data' => $pastPaper,
        ]);
    }

    /**
     * Delete a past paper.
     */
    public function destroy($id)
    {
        $pastPaper = PastPaper::find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        $pastPaper->delete();

        return response()->json([
            'success' => true,
            'message' => 'Past paper deleted successfully.',
        ]);
    }

    /**
     * Get subjects that have past papers for a category.
     */
    public function subjectsByCategory(Request $request)
    {
        $request->validate([
            'category' => [
                'required',
                'string',
                Rule::in([
                    'primary',
                    'ol',
                    'al',
                    'gov',
                ]),
            ],
        ]);

        $category = $request->category;

        $subjects = Subject::query()
            ->where('is_active', true)

            ->whereHas('pastPapers', function ($query) use ($category) {
                $query->whereHas('grade', function ($gradeQuery) use ($category) {
                    $gradeQuery
                        ->where('category', $category)
                        ->where('is_active', true);
                });
            })

            ->withCount([
                'pastPapers as past_papers_count' => function ($query) use ($category) {
                    $query->whereHas('grade', function ($gradeQuery) use ($category) {
                        $gradeQuery
                            ->where('category', $category)
                            ->where('is_active', true);
                    });
                },
            ])

            ->orderBy('sort_order')
            ->orderBy('name')

            ->get([
                'id',
                'name',
                'slug',
                'icon',
                'color',
                'description',
            ]);

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    /**
     * Student: Get past papers by category, subject and medium.
     */
    public function studentList(Request $request)
    {
        $validated = $request->validate([
            'category' => [
                'required',
                'string',
                Rule::in([
                    'primary',
                    'ol',
                    'al',
                    'gov',
                ]),
            ],

            'subject_id' => [
                'required',
                'integer',
                'exists:subjects,id',
            ],

            'medium' => [
                'required',
                'string',
                Rule::in(['si', 'en', 'ta']),
            ],

            'al_stream' => [
                'nullable',
                'string',
                Rule::in(self::AL_STREAMS),
            ],
        ]);

        $category = $validated['category'];

        $query = PastPaper::query()
            ->where('subject_id', $validated['subject_id'])
            ->where('medium', $validated['medium'])

            ->whereHas('grade', function ($gradeQuery) use ($category) {
                $gradeQuery
                    ->where('category', $category)
                    ->where('is_active', true);
            });

        if ($category === 'al') {

            if (empty($validated['al_stream'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'A/L stream is required.',
                ], 422);
            }

            $query->where('al_stream', $validated['al_stream']);
        }

        $papers = $query
            ->with([
                'grade:id,name,category',
                'subject:id,name,slug,icon,color',
            ])
            ->withCount('questions')
            ->orderByDesc('year')
            ->orderBy('paper_number')
            ->get([
                'id',
                'grade_id',
                'subject_id',
                'al_stream',
                'year',
                'time_limit_minutes',
                'medium',
                'title',
                'paper_number',
                'total_questions',
            ]);

        return response()->json([
            'success' => true,
            'data' => $papers,
        ]);
    }
}
