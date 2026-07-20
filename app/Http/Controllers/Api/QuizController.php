<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

use App\Models\Quiz;
use App\Models\Question;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\QuestionOption;
use App\Services\StatisticsService;

class QuizController extends Controller
{
    /**
     * Start a new quiz
     */
    public function start(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'difficulty' => 'required|in:easy,medium,hard',
            'question_count' => 'nullable|integer|min:5|max:100',
        ]);

        $questionCount = $request->question_count ?? 20;

        /*
        |--------------------------------------------------------------------------
        | Fetch Questions
        |--------------------------------------------------------------------------
        */

        $questions = Question::with('options')
            ->where('grade_id', $user->grade_id)
            ->where('subject_id', $request->subject_id)
            ->where('difficulty', $request->difficulty)
            ->where('status', 'published')
            ->inRandomOrder()
            ->limit($questionCount)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Check Minimum Questions
        |--------------------------------------------------------------------------
        */

        if ($questions->count() < 5) {
            return response()->json([
                'success' => false,
                'message' => 'Not enough published questions available for this difficulty.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Quiz
        |--------------------------------------------------------------------------
        */

        $quiz = Quiz::create([
            'user_id' => $user->id,
            'grade_id' => $user->grade_id,
            'subject_id' => $request->subject_id,
            'quiz_type' => 'practice',
            'total_questions' => $questions->count(),
            'started_at' => now(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save Quiz Questions
        |--------------------------------------------------------------------------
        */

        foreach ($questions as $index => $question) {
            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_id' => $question->id,
                'question_order' => $index + 1,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hide Sensitive Data
        |--------------------------------------------------------------------------
        */

        $responseQuestions = $questions->map(function ($question) {

            return [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'question_type' => $question->question_type,
                'difficulty' => $question->difficulty,

                'options' => $question->options->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'option_key' => $option->option_key,
                        'option_text' => $option->option_text,
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'success' => true,

            'quiz' => [
                'id' => $quiz->id,
                'subject_id' => $quiz->subject_id,
                'difficulty' => $request->difficulty,
                'total_questions' => $quiz->total_questions,
                'started_at' => $quiz->started_at,
            ],

            'questions' => $responseQuestions,
        ]);
    }

    /**
     * Submit quiz answers and calculate results
     */

    public function submit(Request $request)
    {
        $request->validate([
            'quiz_id' => 'required|exists:quizzes,id',
            'answers' => 'required|array|min:1',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.selected_option_id' => 'nullable|exists:question_options,id',
        ]);

        $quiz = Quiz::findOrFail($request->quiz_id);

        /*
        |--------------------------------------------------------------------------
        | Authorization
        |--------------------------------------------------------------------------
        */

        if ($quiz->user_id !== $request->user()->id) {

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Resubmission
        |--------------------------------------------------------------------------
        */

        if ($quiz->is_completed) {

            return response()->json([
                'success' => false,
                'message' => 'Quiz already submitted.'
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Duplicate Questions
        |--------------------------------------------------------------------------
        */

        $duplicates = collect($request->answers)
            ->pluck('question_id')
            ->duplicates();

        if ($duplicates->isNotEmpty()) {

            return response()->json([
                'success' => false,
                'message' => 'Duplicate questions submitted.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            $correctCount = 0;
            $wrongCount = 0;
            $processedQuestions = [];

            foreach ($request->answers as $answer) {

                $questionId = $answer['question_id'];
                $selectedOptionId = $answer['selected_option_id'] ?? null;

                /*
                |--------------------------------------------------------------------------
                | Verify Question Belongs To Quiz
                |--------------------------------------------------------------------------
                */

                $quizQuestion = QuizQuestion::where('quiz_id', $quiz->id)
                    ->where('question_id', $questionId)
                    ->exists();

                if (!$quizQuestion) {
                    continue;
                }

                $question = Question::find($questionId);

                if (!$question) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Correct Option
                |--------------------------------------------------------------------------
                */

                $correctOption = QuestionOption::where(
                    'question_id',
                    $questionId
                )
                    ->where(
                        'option_key',
                        $question->correct_answer
                    )
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Skipped Question
                |--------------------------------------------------------------------------
                */

                if (!$selectedOptionId) {

                    QuizAnswer::updateOrCreate(
                        [
                            'quiz_id' => $quiz->id,
                            'question_id' => $questionId,
                        ],
                        [
                            'selected_option_id' => null,
                            'correct_option_id' => $correctOption?->id,
                            'is_correct' => false,
                        ]
                    );

                    $wrongCount++;
                    $processedQuestions[] = $questionId;

                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | Verify Selected Option
                |--------------------------------------------------------------------------
                */

                $selectedOption = QuestionOption::where('id', $selectedOptionId)
                    ->where('question_id', $questionId)
                    ->first();

                if (!$selectedOption) {
                    continue;
                }

                $isCorrect =
                    $correctOption &&
                    $selectedOption->id == $correctOption->id;

                /*
                |--------------------------------------------------------------------------
                | Save Answer
                |--------------------------------------------------------------------------
                */

                QuizAnswer::updateOrCreate(
                    [
                        'quiz_id' => $quiz->id,
                        'question_id' => $questionId,
                    ],
                    [
                        'selected_option_id' => $selectedOption->id,
                        'correct_option_id' => $correctOption?->id,
                        'is_correct' => $isCorrect,
                    ]
                );

                if ($isCorrect) {
                    $correctCount++;
                } else {
                    $wrongCount++;
                }

                $processedQuestions[] = $questionId;
            }

            /*
            |--------------------------------------------------------------------------
            | Unanswered Questions
            |--------------------------------------------------------------------------
            */

            $remainingQuestions =
                $quiz->total_questions - count($processedQuestions);

            if ($remainingQuestions > 0) {
                $wrongCount += $remainingQuestions;
            }

            /*
            |--------------------------------------------------------------------------
            | Percentage
            |--------------------------------------------------------------------------
            */

            $percentage = $quiz->total_questions > 0
                ? round(($correctCount / $quiz->total_questions) * 100, 2)
                : 0;

            /*
            |--------------------------------------------------------------------------
            | Update Quiz
            |--------------------------------------------------------------------------
            */

            $quiz->update([

                'correct_answers' => $correctCount,

                'wrong_answers' => $wrongCount,

                'score' => $correctCount,

                'percentage' => $percentage,

                'is_completed' => true,

                'completed_at' => now(),

                'duration_seconds' => $quiz->started_at
                    ? now()->diffInSeconds($quiz->started_at)
                    : 0,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Refresh Statistics
            |--------------------------------------------------------------------------
            */

            $statistics = StatisticsService::refresh(
                $request->user()
            );

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'success' => true,

                'message' => 'Quiz submitted successfully.',

                'quiz_id' => $quiz->id,

                'score' => $correctCount,

                'correct_answers' => $correctCount,

                'wrong_answers' => $wrongCount,

                'percentage' => $percentage,

                'total_questions' => $quiz->total_questions,

                'duration_seconds' => $quiz->duration_seconds,

                'statistics' => [

                    'xp' => $statistics->xp,

                    'level' => $statistics->level,

                    'current_streak' => $statistics->current_streak,

                    'completed_quizzes' => $statistics->completed_quizzes,
                ],

            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Quiz submission failed.',

                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : 'Something went wrong.'

            ], 500);
        }
    }

    /**
     * Get quiz result
     */
    public function result(Request $request, Quiz $quiz)
    {
        if ($quiz->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$quiz->is_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not completed yet.'
            ], 422);
        }

        $rank = match (true) {
            $quiz->percentage >= 75 => 'A',
            $quiz->percentage >= 65 => 'B',
            $quiz->percentage >= 50 => 'C',
            default => 'D',
        };

        return response()->json([
            'success' => true,

            'result' => [
                'quiz_id' => $quiz->id,
                'total_questions' => $quiz->total_questions,
                'correct_answers' => $quiz->correct_answers,
                'wrong_answers' => $quiz->wrong_answers,
                'score' => (float) $quiz->score,
                'percentage' => (float) $quiz->percentage,
                'rank' => $rank,
                'passed' => $quiz->percentage >= 50,
                'duration_seconds' => $quiz->duration_seconds,
                'quiz_type' => $quiz->quiz_type,
                'started_at' => $quiz->started_at,
                'completed_at' => $quiz->completed_at,
            ]
        ]);
    }

    /**
     * Quiz History
     */
    public function history(Request $request)
    {
        $quizzes = Quiz::where('user_id', $request->user()->id)
            ->with([
                'grade:id,name',
                'subject:id,name,color'
            ])
            ->latest()
            ->paginate(20);

        $history = collect($quizzes->items())->map(function ($quiz) {

            return [

                'id' => $quiz->id,

                'grade' => [
                    'id' => $quiz->grade->id,
                    'name' => $quiz->grade->name,
                ],

                'subject' => [
                    'id' => $quiz->subject->id,
                    'name' => $quiz->subject->name,
                    'color' => $quiz->subject->color,
                ],

                'quiz_type' => $quiz->quiz_type,

                'total_questions' => $quiz->total_questions,

                'correct_answers' => $quiz->correct_answers,

                'wrong_answers' => $quiz->wrong_answers,

                'score' => (float) $quiz->score,

                'percentage' => (float) $quiz->percentage,

                'duration_seconds' => $quiz->duration_seconds,

                'completed_at' => $quiz->completed_at,
            ];
        });

        return response()->json([

            'success' => true,

            'history' => $history,

            'pagination' => [

                'current_page' => $quizzes->currentPage(),

                'last_page' => $quizzes->lastPage(),

                'per_page' => $quizzes->perPage(),

                'total' => $quizzes->total(),

            ]

        ]);
    }

    /**
     * Review Quiz (Includes Skipped Questions)
     */
    public function review(Request $request, Quiz $quiz)
    {
        if ($quiz->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if (!$quiz->is_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz not completed yet.'
            ], 422);
        }

        $quizQuestions = QuizQuestion::with([
            'question.options'
        ])
        ->where('quiz_id', $quiz->id)
        ->orderBy('question_order')
        ->get();

        $review = $quizQuestions->map(function ($quizQuestion) use ($quiz) {

            $question = $quizQuestion->question;

            $answer = QuizAnswer::with('selectedOption')
                ->where('quiz_id', $quiz->id)
                ->where('question_id', $question->id)
                ->first();

            $correctOption = $question->options
                ->firstWhere('option_key', $question->correct_answer);

            return [

                'question_id' => $question->id,

                'question_text' => $question->question_text,

                'options' => $question->options->map(function ($option) {

                    return [
                        'id' => $option->id,
                        'option_key' => $option->option_key,
                        'option_text' => $option->option_text,
                    ];

                })->values(),

                'selected_option_id' => $answer?->selected_option_id,

                'correct_option_id' => $correctOption?->id,

                'selected_option' => $answer?->selectedOption?->option_text,

                'correct_option' => $correctOption?->option_text,

                'is_correct' => $answer?->is_correct ?? false,

                'is_skipped' => is_null($answer?->selected_option_id),

                'explanation' => $question->explanation,
            ];

        });

        return response()->json([

            'success' => true,

            'quiz' => [

                'id' => $quiz->id,

                'score' => (float) $quiz->score,

                'percentage' => (float) $quiz->percentage,

                'correct_answers' => $quiz->correct_answers,

                'wrong_answers' => $quiz->wrong_answers,

                'total_questions' => $quiz->total_questions,

            ],

            'answers' => $review,

        ]);
    }

    /**
     * Admin: Get quiz history with filters (paginated)
     */
    public function adminHistory(Request $request)
    {
        // Optional Admin Check (uncomment if needed)
        // if (!$request->user()?->is_admin) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Unauthorized'
        //     ], 403);
        // }

        $query = Quiz::with([
            'user:id,name,email',
            'grade',
            'subject'
        ]);

        if ($request->filled('grade_id')) {
            $query->where('grade_id', $request->grade_id);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('quiz_type')) {
            $query->where('quiz_type', $request->quiz_type);
        }

        if ($request->filled('is_completed')) {
            $query->where('is_completed', filter_var($request->is_completed, FILTER_VALIDATE_BOOLEAN));
        }

        $quizzes = $query->latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $quizzes
        ]);
    }

    /**
     * Admin: Get single quiz details
     */
    public function adminShow(Request $request, $id)
    {
        // Optional Admin Check
        // if (!$request->user()?->is_admin) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Unauthorized'
        //     ], 403);
        // }

        $quiz = Quiz::with([
            'user:id,name,email',
            'grade',
            'subject',
            'answers' => function ($query) {
                $query->with(['question', 'selectedOption']);
            }
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $quiz
        ]);
    }

    /**
     * Admin: Overall quiz statistics
     */
    public function adminStats(Request $request)
    {
        // Optional Admin Check
        // if (!$request->user()?->is_admin) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Unauthorized'
        //     ], 403);
        // }

        $totalQuizzes = Quiz::count();
        $completedQuizzes = Quiz::where('is_completed', true)->count();

        // Get statistics by grade and subject
        $statsByGrade = Quiz::where('is_completed', true)
            ->selectRaw('grade_id, COUNT(*) as count, AVG(percentage) as avg_score')
            ->groupBy('grade_id')
            ->with('grade')
            ->get();

        $statsBySubject = Quiz::where('is_completed', true)
            ->selectRaw('subject_id, COUNT(*) as count, AVG(percentage) as avg_score')
            ->groupBy('subject_id')
            ->with('subject')
            ->get();

        // Recent activity (last 7 days)
        $recentQuizzes = Quiz::where('is_completed', true)
            ->where('completed_at', '>=', now()->subDays(7))
            ->count();

        return response()->json([
            'success' => true,
            'total_quizzes' => $totalQuizzes,
            'completed_quizzes' => $completedQuizzes,
            'in_progress_quizzes' => $totalQuizzes - $completedQuizzes,
            'average_score' => round(Quiz::avg('percentage') ?? 0, 2),
            'unique_students' => Quiz::distinct()->count('user_id'),
            'recent_quizzes' => $recentQuizzes,
            'stats_by_grade' => $statsByGrade,
            'stats_by_subject' => $statsBySubject,
        ]);
    }

    /**
     * Get quiz details with all answers for a specific quiz
     */
    public function show(Request $request, Quiz $quiz)
    {
        // Authorization check
        if ($quiz->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $quiz->load(['grade', 'subject', 'answers.question.options']);

        return response()->json([
            'success' => true,
            'data' => $quiz
        ]);
    }
}
