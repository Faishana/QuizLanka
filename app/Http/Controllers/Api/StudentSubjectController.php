<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\Request;

class StudentSubjectController extends Controller
{
    /**
     * Get subjects for the logged-in student's grade.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $subjects = Subject::where('grade_id', $user->grade_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'slug' => $subject->slug,
                    'icon' => $subject->icon,
                    'color' => $subject->color,
                    'description' => $subject->description,
                ];
            });

        return response()->json([
            'success' => true,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Get a single subject.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $subject = Subject::where('id', $id)
            ->where('grade_id', $user->grade_id)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'slug' => $subject->slug,
                'icon' => $subject->icon,
                'color' => $subject->color,
                'description' => $subject->description,
            ],
        ]);
    }
}
