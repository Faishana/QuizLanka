<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Models\QuizAnswer;
use App\Models\QuestionOption;

class QuizController extends Controller
{

    // Get quiz result and details

    public function result(Request $request, Quiz $quiz)
    {
        if ($quiz->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'quiz_id' => $quiz->id,
            'total_questions' => $quiz->total_questions,
            'correct_answers' => $quiz->correct_answers,
            'wrong_answers' => $quiz->wrong_answers,
            'score' => $quiz->score,
            'started_at' => $quiz->started_at,
            'completed_at' => $quiz->completed_at,
        ]);
    }

    // Get quiz history for the authenticated user

    public function history(Request $request)
    {
        $quizzes = Quiz::where(
            'user_id',
            $request->user()->id
        )
        ->latest()
        ->get();

        return response()->json([
            'success' => true,
            'count' => $quizzes->count(),
            'data' => $quizzes
        ]);
    }

    // Review quiz answers with question and selected option details

    public function review(Request $request, Quiz $quiz)
    {
        if ($quiz->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $answers = $quiz->answers()
            ->with([
                'question.options',
                'selectedOption'
            ])
            ->get();

        $review = $answers->map(function ($answer) {

            $question = $answer->question;

            $correctOption = \App\Models\QuestionOption::where(
                'question_id',
                $question->id
            )
            ->where('option_key', $question->correct_answer)
            ->first();

            return [
                'question_id' => $question->id,
                'question_text' => $question->question_text,

                'selected_option' => $answer->selectedOption?->option_text,

                'correct_option' => $correctOption?->option_text,

                'is_correct' => $answer->is_correct,

                'explanation' => $question->explanation,
            ];
        });

        return response()->json([
            'success' => true,
            'quiz_id' => $quiz->id,
            'score' => $quiz->score,
            'answers' => $review,
        ]);
    }
}
