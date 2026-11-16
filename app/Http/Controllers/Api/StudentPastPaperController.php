<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PastPaper;
use App\Models\Grade;
use App\Models\User;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\PastPaperQuestion;
use Illuminate\Validation\Rule;


class StudentPastPaperController extends Controller
{
    /**
     * Build the past-paper query according to
     * the authenticated user's category and profile.
     */

    private function accessiblePapersQuery(User $user): Builder
    {
        $query = PastPaper::query();

        // Government Exam Candidate
        if ($user->user_category === User::CATEGORY_GOV_EXAM) {

            return $query->whereHas('grade', function ($gradeQuery) {
                $gradeQuery
                    ->where('category', 'gov')
                    ->where('is_active', true);
            });
        }

        // Student
        if ($user->user_category === User::CATEGORY_STUDENT) {

            if (!$user->grade_id) {
                abort(403, 'Student grade has not been configured.');
            }

            $grade = Grade::find($user->grade_id);

            if (!$grade || !$grade->is_active) {
                abort(403, 'Student grade is not available.');
            }

            $query->where('grade_id', $grade->id);

            // A/L students must only see papers
            // belonging to their selected stream.
            if ($grade->category === 'al') {

                if (!$user->al_stream) {
                    abort(403, 'A/L stream has not been configured.');
                }

                $query->where('al_stream', $user->al_stream);
            }

            return $query;
        }

        abort(403, 'You are not authorized to access past papers.');
    }

    /**
     * Get subjects available to the authenticated user.
     */
    public function subjects(Request $request)
    {
        $user = $request->user();

        $papersQuery = $this->accessiblePapersQuery($user);

        $subjects = \App\Models\Subject::query()
            ->where('is_active', true)
            ->whereHas('pastPapers', function ($query) use ($papersQuery) {

                $query->whereIn(
                    'past_papers.id',
                    (clone $papersQuery)->select('past_papers.id')
                );

            })
            ->withCount([
                'pastPapers as past_papers_count' => function ($query) use ($papersQuery) {

                    $query->whereIn(
                        'past_papers.id',
                        (clone $papersQuery)->select('past_papers.id')
                    );

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
     * Get past papers available to the authenticated user.
     */
    public function index(Request $request)
    {
        $request->validate([
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'medium' => ['nullable', 'in:si,en,ta'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ]);

        $query = $this->accessiblePapersQuery($request->user());

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->filled('medium')) {
            $query->where('medium', $request->medium);
        }

        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }

        $papers = $query
            ->with([
                'grade:id,name,category',
                'subject:id,name,slug,icon,color',
            ])
            ->withCount('questions')
            ->orderByDesc('year')
            ->orderBy('paper_number')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $papers,
        ]);
    }

    /**
     * Get one accessible past paper for starting a quiz.
     * Correct answers are not returned.
     */
    public function show(Request $request, $id)
    {
        $pastPaper = $this->accessiblePapersQuery($request->user())
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
                            'explanation',
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
            'data' => $pastPaper,
        ]);
    }

    /**
     * Start a past paper attempt.
     */
    public function start(Request $request, $id)
    {
        $user = $request->user();

        // Check whether the student can access this paper.
        $pastPaper = $this->accessiblePapersQuery($user)
            ->withCount('questions')
            ->find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        // Check for an existing incomplete attempt.
        $existingAttempt = Quiz::where('user_id', $user->id)
            ->where('past_paper_id', $pastPaper->id)
            ->where('quiz_type', 'past_paper')
            ->where('is_completed', false)
            ->latest('id')
            ->first();

        if ($existingAttempt) {

            // Check whether the attempt has expired.
            if (
                $existingAttempt->expires_at &&
                now()->greaterThanOrEqualTo($existingAttempt->expires_at)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your previous attempt has expired.',
                    'data' => [
                        'quiz_id' => $existingAttempt->id,
                        'expires_at' => $existingAttempt->expires_at,
                        'expired' => true,
                    ],
                ], 409);
            }

            // Resume the existing attempt.
            $attempt = $existingAttempt;

        } else {

            // Create a new attempt.
            $attempt = DB::transaction(function () use (
                $user,
                $pastPaper
            ) {
                $startedAt = now();

                $expiresAt = $startedAt->copy()->addMinutes(
                    $pastPaper->time_limit_minutes
                );

                return Quiz::create([
                    'user_id' => $user->id,
                    'grade_id' => $pastPaper->grade_id,
                    'subject_id' => $pastPaper->subject_id,
                    'past_paper_id' => $pastPaper->id,
                    'quiz_type' => 'past_paper',

                    'total_questions' => $pastPaper->questions_count,
                    'correct_answers' => 0,
                    'wrong_answers' => 0,
                    'score' => 0,
                    'percentage' => 0,

                    'duration_seconds' => 0,
                    'is_completed' => false,

                    'started_at' => $startedAt,
                    'expires_at' => $expiresAt,
                ]);
            });
        }

        $remainingSeconds = max(
            0,
            now()->diffInSeconds($attempt->expires_at, false)
        );

        return response()->json([
            'success' => true,
            'message' => $existingAttempt
                ? 'Existing past paper attempt resumed successfully.'
                : 'Past paper started successfully.',

            'data' => [
                'quiz_id' => $attempt->id,

                'past_paper' => [
                    'id' => $pastPaper->id,
                    'title' => $pastPaper->title,
                    'year' => $pastPaper->year,
                    'medium' => $pastPaper->medium,
                    'time_limit_minutes' => $pastPaper->time_limit_minutes,
                    'total_questions' => $pastPaper->questions_count,
                ],

                'started_at' => $attempt->started_at,
                'expires_at' => $attempt->expires_at,
                'remaining_seconds' => $remainingSeconds,
                'is_resumed' => (bool) $existingAttempt,
            ],
        ]);
    }

    
    /**
     * Submit answers for an existing past paper attempt.
     * Uses auto-saved answers and merges final submitted answers.
     */
    public function submit(Request $request, $id)
    {
        $validated = $request->validate([
            'quiz_id' => [
                'required',
                'integer',
                'exists:quizzes,id',
            ],

            'answers' => [
                'sometimes',
                'array',
            ],

            'answers.*.question_id' => [
                'required',
                'integer',
                'distinct',
                'exists:past_paper_questions,id',
            ],

            'answers.*.answer' => [
                'present',
                'nullable',
                'string',
                Rule::in(['A', 'B', 'C', 'D']),
            ],
        ]);

        $user = $request->user();

        // Check whether the student can access this paper.
        $pastPaper = $this->accessiblePapersQuery($user)
            ->with([
                'questions' => function ($query) {
                    $query->orderBy('question_number');
                },
            ])
            ->find($id);

        if (!$pastPaper) {
            return response()->json([
                'success' => false,
                'message' => 'Past paper not found.',
            ], 404);
        }

        // Get all question IDs belonging to this paper.
        $questionIds = $pastPaper->questions
            ->pluck('id')
            ->all();

        // Validate submitted question IDs.
        foreach (($validated['answers'] ?? []) as $answer) {
            if (!in_array((int) $answer['question_id'], $questionIds, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid question for this past paper.',
                ], 422);
            }
        }

        try {

            $result = DB::transaction(function () use (
                $validated,
                $user,
                $pastPaper
            ) {

                // Find and lock the existing attempt.
                $quiz = Quiz::where('id', $validated['quiz_id'])
                    ->where('user_id', $user->id)
                    ->where('past_paper_id', $pastPaper->id)
                    ->where('quiz_type', 'past_paper')
                    ->lockForUpdate()
                    ->first();

                if (!$quiz) {
                    return [
                        'error' => 'Attempt not found or unauthorized.',
                        'status' => 404,
                    ];
                }

                // Prevent duplicate submissions.
                if ($quiz->is_completed) {
                    return [
                        'error' => 'This past paper attempt has already been submitted.',
                        'status' => 409,
                    ];
                }

                $now = now();

                // Check expiration.
                if (
                    !$quiz->expires_at ||
                    $now->greaterThanOrEqualTo($quiz->expires_at)
                ) {
                    return [
                        'error' => 'Your past paper time limit has expired.',
                        'status' => 409,
                        'expired' => true,
                    ];
                }

                /*
                * STEP 1:
                * Load auto-saved answers directly from the database.
                */
                $savedAnswers = $quiz->pastPaperAnswers()
                    ->pluck(
                        'selected_answer',
                        'past_paper_question_id'
                    )
                    ->map(function ($answer) {
                        return $answer !== null
                            ? strtoupper($answer)
                            : null;
                    });

                /*
                * STEP 2:
                * Load answers submitted with the final request.
                */
                $submittedAnswers = collect(
                    $validated['answers'] ?? []
                )->mapWithKeys(function ($item) {
                    return [
                        (int) $item['question_id'] => $item['answer'] !== null
                            ? strtoupper($item['answer'])
                            : null,
                    ];
                });

                /*
                * STEP 3:
                * Merge answers.
                * Final submitted answers override auto-saved answers.
                */
                $mergedAnswers = $savedAnswers;

                foreach ($submittedAnswers as $questionId => $answer) {
                    $mergedAnswers->put($questionId, $answer);
                }

                /*
                * STEP 4:
                * Calculate results for every question.
                */
                $total = $pastPaper->questions->count();

                $correct = 0;
                $wrong = 0;
                $unanswered = 0;

                $results = [];

                foreach ($pastPaper->questions as $question) {

                    $questionId = (int) $question->id;

                    $selected = $mergedAnswers->has($questionId)
                        ? $mergedAnswers->get($questionId)
                        : null;

                    $correctAnswer = $question->correct_answer
                        ? strtoupper($question->correct_answer)
                        : null;

                    $isSkipped = $selected === null;

                    $isCorrect = !$isSkipped
                        && $correctAnswer !== null
                        && $selected === $correctAnswer;

                    if ($isSkipped) {
                        $unanswered++;
                    } elseif ($isCorrect) {
                        $correct++;
                    } else {
                        $wrong++;
                    }

                    /*
                    * STEP 5:
                    * Update existing auto-saved answer or create
                    * a new record if no answer was previously saved.
                    */
                    $quiz->pastPaperAnswers()->updateOrCreate(
                        [
                            'past_paper_question_id' => $questionId,
                        ],
                        [
                            'selected_answer' => $selected,
                            'correct_answer' => $correctAnswer,
                            'is_correct' => $isCorrect,
                            'is_skipped' => $isSkipped,
                        ]
                    );

                    $results[] = [
                        'question_id' => $questionId,
                        'question_number' => $question->question_number,
                        'selected_answer' => $selected,
                        'correct_answer' => $correctAnswer,
                        'is_correct' => $isCorrect,
                        'is_skipped' => $isSkipped,
                    ];
                }

                $answered = $correct + $wrong;

                $percentage = $total > 0
                    ? round(($correct / $total) * 100, 2)
                    : 0;

                /*
                * STEP 6:
                * Calculate actual attempt duration.
                */
                $durationSeconds = $quiz->started_at
                    ? max(0, $quiz->started_at->diffInSeconds($now))
                    : 0;

                /*
                * STEP 7:
                * Complete the existing quiz attempt.
                */
                $quiz->update([
                    'total_questions' => $total,
                    'correct_answers' => $correct,
                    'wrong_answers' => $wrong,
                    'score' => $correct,
                    'percentage' => $percentage,
                    'duration_seconds' => $durationSeconds,
                    'is_completed' => true,
                    'completed_at' => $now,
                ]);

                return [
                    'quiz' => $quiz,
                    'total' => $total,
                    'answered' => $answered,
                    'correct' => $correct,
                    'wrong' => $wrong,
                    'unanswered' => $unanswered,
                    'percentage' => $percentage,
                    'duration_seconds' => $durationSeconds,
                    'results' => $results,
                ];
            });

            // Handle transaction validation errors.
            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'],
                    'expired' => $result['expired'] ?? false,
                ], $result['status']);
            }

            $quiz = $result['quiz'];

            return response()->json([
                'success' => true,
                'message' => 'Past paper submitted successfully.',

                'data' => [
                    'quiz_id' => $quiz->id,

                    'past_paper' => [
                        'id' => $pastPaper->id,
                        'title' => $pastPaper->title,
                        'year' => $pastPaper->year,
                        'medium' => $pastPaper->medium,
                        'time_limit_minutes' => $pastPaper->time_limit_minutes,
                    ],

                    'summary' => [
                        'total_questions' => $result['total'],
                        'answered_questions' => $result['answered'],
                        'correct_answers' => $result['correct'],
                        'wrong_answers' => $result['wrong'],
                        'unanswered_questions' => $result['unanswered'],
                        'score' => $result['correct'],
                        'percentage' => $result['percentage'],
                        'duration_seconds' => $result['duration_seconds'],
                    ],

                    'results' => $result['results'],
                ],
            ]);

        } catch (\Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit past paper. Please try again.',
            ], 500);
        }
    }


    /**
     * Auto-save a student's answer for a past paper attempt.
     */
    public function saveAnswer(Request $request, $quizId)
    {
        $validated = $request->validate([
            'question_id' => [
                'required',
                'integer',
                'exists:past_paper_questions,id',
            ],

            'answer' => [
                'present',
                'nullable',
                'string',
                Rule::in(['A', 'B', 'C', 'D']),
            ],
        ]);

        $user = $request->user();

        try {

            $result = DB::transaction(function () use (
                $validated,
                $quizId,
                $user
            ) {

                // Find and lock the student's attempt.
                $quiz = Quiz::where('id', $quizId)
                    ->where('user_id', $user->id)
                    ->where('quiz_type', 'past_paper')
                    ->lockForUpdate()
                    ->first();

                if (!$quiz) {
                    return [
                        'error' => 'Past paper attempt not found.',
                        'status' => 404,
                    ];
                }

                // Prevent saving after submission.
                if ($quiz->is_completed) {
                    return [
                        'error' => 'This attempt has already been submitted.',
                        'status' => 409,
                    ];
                }

                $now = now();

                // Check attempt expiration.
                if (
                    !$quiz->expires_at ||
                    $now->greaterThanOrEqualTo($quiz->expires_at)
                ) {
                    return [
                        'error' => 'Your past paper time limit has expired.',
                        'status' => 409,
                    ];
                }

                // Verify question belongs to this attempt's paper.
                $question = PastPaperQuestion::where('id', $validated['question_id'])
                    ->where('past_paper_id', $quiz->past_paper_id)
                    ->first();

                if (!$question) {
                    return [
                        'error' => 'Invalid question for this past paper.',
                        'status' => 422,
                    ];
                }

                $selectedAnswer = $validated['answer'] !== null
                    ? strtoupper($validated['answer'])
                    : null;

                $correctAnswer = $question->correct_answer
                    ? strtoupper($question->correct_answer)
                    : null;

                $isCorrect = $selectedAnswer !== null
                    && $correctAnswer !== null
                    && $selectedAnswer === $correctAnswer;

                // Create or update the existing answer.
                $savedAnswer = $quiz->pastPaperAnswers()->updateOrCreate(
                    [
                        'past_paper_question_id' => $question->id,
                    ],
                    [
                        'selected_answer' => $selectedAnswer,
                        'correct_answer' => $correctAnswer,
                        'is_correct' => $isCorrect,
                        'is_skipped' => $selectedAnswer === null,
                    ]
                );

                return [
                    'saved' => true,
                    'question_id' => $question->id,
                    'selected_answer' => $savedAnswer->selected_answer,
                    'saved_at' => $savedAnswer->updated_at,
                ];
            });

            // Handle validation errors.
            if (isset($result['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error'],
                ], $result['status']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Answer saved successfully.',
                'data' => $result,
            ]);

        } catch (\Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to save answer. Please try again.',
            ], 500);
        }
    }


}
