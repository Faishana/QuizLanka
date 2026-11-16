<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Question;
use App\Models\QuestionOption;

class QuestionController extends Controller
{
    /**
     * List Questions (Only Admin's Own Questions)
     */
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

        // Search
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where('question_text', 'like', '%' . $search . '%');
        }

        // Grade
        if ($request->filled('grade_id')) {
            $query->where('grade_id', $request->grade_id);
        }

        // Subject
        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->subject_id);
        }

        // Medium
        // Database field: quiz_language
        // Values: si, en, ta
        if ($request->filled('medium')) {
            $query->where('quiz_language', $request->medium);
        }

        // Difficulty
        if ($request->filled('difficulty')) {
            $query->where('difficulty', $request->difficulty);
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

        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = strtolower($request->get('sort_order', 'asc'));

        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }

        if (!in_array($sortOrder, ['asc', 'desc'])) {
            $sortOrder = 'asc';
        }

        $query->orderBy($sortBy, $sortOrder);

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) $request->get('per_page', 20);

        // Prevent invalid / excessive values
        if ($perPage < 1) {
            $perPage = 20;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $questions = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $request->get('page', 1)
        );

        /*
        |--------------------------------------------------------------------------
        | Add Correct Answer Text
        |--------------------------------------------------------------------------
        */

        $questions->getCollection()->transform(function ($question) {

            $correctOption = $question->options->firstWhere(
                'option_key',
                $question->correct_answer
            );

            $question->correct_option_text = $correctOption?->option_text;

            return $question;
        });

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'data' => $questions
        ]);
    }

    /**
     * Show Question (Only Admin's Own Question)
     */
    public function show(Request $request, $id)
    {
        $question = Question::where('created_by', $request->user()->id)
            ->with([
                'grade:id,name',
                'subject:id,name',
                'material:id,title',
                'options'
            ])
            ->findOrFail($id);

        $correctOption = $question->options->firstWhere(
            'option_key',
            $question->correct_answer
        );

        $question->correct_option_text = $correctOption?->option_text;

        return response()->json([
            'success' => true,
            'data' => $question
        ]);
    }

    /**
     * Update Question (Only Admin's Own Question)
     */
    public function update(Request $request, $id)
    {
        $question = Question::where(
            'created_by',
            $request->user()->id
        )->findOrFail($id);

        $validated = $request->validate([
            'question_text' => 'required|string',
            'correct_answer' => 'required|string|max:10',

            // Medium
            'quiz_language' => 'nullable|in:si,en,ta',

            // Difficulty
            'difficulty' => 'required|in:easy,medium,hard',

            'explanation' => 'nullable|string'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Update Question
        |--------------------------------------------------------------------------
        */

        $question->update($validated);

        /*
        |--------------------------------------------------------------------------
        | Update Options
        |--------------------------------------------------------------------------
        */

        if ($request->has('options') && is_array($request->options)) {

            foreach ($request->options as $option) {

                if (
                    !isset($option['id']) ||
                    !isset($option['option_text'])
                ) {
                    continue;
                }

                QuestionOption::where('question_id', $question->id)
                    ->where('id', $option['id'])
                    ->update([
                        'option_text' => $option['option_text']
                    ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        $question = $question->fresh([
            'grade',
            'subject',
            'material',
            'options'
        ]);

        $correctOption = $question->options->firstWhere(
            'option_key',
            $question->correct_answer
        );

        $question->correct_option_text = $correctOption?->option_text;

        return response()->json([
            'success' => true,
            'message' => 'Question updated successfully',
            'data' => $question
        ]);
    }

    /**
     * Delete Question (Only Admin's Own Question)
     */
    public function destroy(Request $request, $id)
    {
        $question = Question::where(
            'created_by',
            $request->user()->id
        )->findOrFail($id);

        $question->delete();

        return response()->json([
            'success' => true,
            'message' => 'Question deleted successfully'
        ]);
    }
}
