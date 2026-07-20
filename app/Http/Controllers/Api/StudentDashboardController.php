<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Quiz;

class StudentDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user()->load('grade');

        /*
        |--------------------------------------------------------------------------
        | Selected Subjects
        |--------------------------------------------------------------------------
        */

        $subjects = $user->subjects()
            ->withCount('questions')
            ->orderBy('sort_order')
            ->get([
                'subjects.id',
                'subjects.name',
                'subjects.icon',
                'subjects.color'
            ])
            ->map(function ($subject) {

                return [

                    'id' => $subject->id,

                    'name' => $subject->name,

                    'icon' => $subject->icon,

                    'color' => $subject->color,

                    'questions_count' => $subject->questions_count,

                ];

            });

        /*
        |--------------------------------------------------------------------------
        | Dashboard Statistics
        |--------------------------------------------------------------------------
        */

        $completedQuizzes = Quiz::where('user_id', $user->id)
            ->where('is_completed', true);

        $stats = [

            'selected_subjects' => $subjects->count(),

            'completed_quizzes' => $completedQuizzes->count(),

            'average_score' => round(
                $completedQuizzes->avg('percentage') ?? 0,
                2
            ),

        ];

        /*
        |--------------------------------------------------------------------------
        | Continue Learning
        |--------------------------------------------------------------------------
        */

        $lastQuiz = Quiz::with('subject:id,name,color')
            ->where('user_id', $user->id)
            ->where('is_completed', true)
            ->latest()
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Recent Quizzes
        |--------------------------------------------------------------------------
        */

        $recentQuizzes = Quiz::with('subject:id,name,color')
            ->where('user_id', $user->id)
            ->where('is_completed', true)
            ->latest()
            ->take(5)
            ->get()
            ->map(function ($quiz) {

                return [

                    'id' => $quiz->id,

                    'subject' => [

                        'id' => $quiz->subject->id,

                        'name' => $quiz->subject->name,

                        'color' => $quiz->subject->color,

                    ],

                    'quiz_type' => $quiz->quiz_type,

                    'score' => (float) $quiz->score,

                    'percentage' => (float) $quiz->percentage,

                    'completed_at' => $quiz->completed_at,

                ];

            });

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'user' => [

                'id' => $user->id,

                'name' => $user->name,

                'email' => $user->email,

                'school' => $user->school,

                'district' => $user->district,

                'grade' => [

                    'id' => $user->grade?->id,

                    'name' => $user->grade?->name,

                ],

            ],

            'stats' => $stats,

            'continue_learning' => $lastQuiz ? [

                'quiz_id' => $lastQuiz->id,

                'subject' => [

                    'id' => $lastQuiz->subject->id,

                    'name' => $lastQuiz->subject->name,

                    'color' => $lastQuiz->subject->color,

                ],

                'last_score' => (float) $lastQuiz->score,

                'last_percentage' => (float) $lastQuiz->percentage,

                'completed_at' => $lastQuiz->completed_at,

            ] : null,

            'subjects' => $subjects,

            'recent_quizzes' => $recentQuizzes,

        ]);
    }
}
