<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\QuestionOption;

class QuestionController extends Controller
{
    public function index(Request $request)
    {
        $query = Question::with([
            'grade:id,name',
            'subject:id,name',
            'material:id,title',
            'options'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {
            $query->where(
                'question_text',
                'like',
                '%' . $request->search . '%'
            );
        }

        if ($request->filled('grade_id')) {
            $query->where(
                'grade_id',
                $request->grade_id
            );
        }

        if ($request->filled('subject_id')) {
            $query->where(
                'subject_id',
                $request->subject_id
            );
        }

        if ($request->filled('difficulty')) {
            $query->where(
                'difficulty',
                $request->difficulty
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $allowedSorts = [
            'id',
            'difficulty',
            'created_at',
            'question_text'
        ];

        if (
            $request->filled('sort_by') &&
            in_array($request->sort_by, [
                'id',
                'question_text',
                'difficulty',
                'created_at'
            ])
        ) {

            $query->orderBy(
                $request->sort_by,
                $request->get('sort_order', 'desc')
            );

        } else {

            $query->orderBy('id', 'asc');
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $questions = $query->paginate(
            $request->get('per_page', 20)
        );

        /*
        |--------------------------------------------------------------------------
        | Add Correct Answer Text
        |--------------------------------------------------------------------------
        */

        $questions->getCollection()->transform(function ($question) {

            $correctOption = $question->options
                ->firstWhere(
                    'option_key',
                    $question->correct_answer
                );

            $question->correct_option_text =
                $correctOption?->option_text;

            return $question;
        });

        return response()->json([
            'success' => true,
            'data' => $questions
        ]);
    }

    public function show(Question $question)
    {
        $question->load([
            'grade:id,name',
            'subject:id,name',
            'material:id,title',
            'options'
        ]);

        $correctOption = $question->options
            ->firstWhere(
                'option_key',
                $question->correct_answer
            );

        $question->correct_option_text =
            $correctOption?->option_text;

        return response()->json([
            'success' => true,
            'data' => $question
        ]);
    }

    public function update(
        Request $request,
        Question $question
    ) {
        $validated = $request->validate([
            'question_text' => 'required|string',
            'correct_answer' => 'required|string|max:10',
            'difficulty' => 'required|in:easy,medium,hard',
            'explanation' => 'nullable|string'
        ]);

        $question->update($validated);

        if ($request->has('options')) {

            foreach ($request->options as $option) {

                QuestionOption::where(
                    'question_id',
                    $question->id
                )
                ->where(
                    'id',
                    $option['id']
                )
                ->update([
                    'option_text' =>
                        $option['option_text']
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully',
            'data' => $question->fresh([
                'grade',
                'subject',
                'options'
            ])
        ]);
    }

    public function destroy(
        Question $question
    ) {
        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully'
        ]);
    }
}
