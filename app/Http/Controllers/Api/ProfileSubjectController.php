<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfileSubjectController extends Controller
{
    /**
     * Get logged-in student's selected subjects.
     */
    public function index(Request $request)
    {
        $subjects = $request->user()
            ->subjects()
            ->select('subjects.id', 'subjects.name', 'subjects.slug', 'subjects.icon', 'subjects.color')
            ->orderBy('subjects.sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Save student's selected subjects.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', 'exists:subjects,id'],
        ]);

        $user = $request->user();

        // Save selected subjects
        $user->subjects()->sync($validated['subject_ids']);

        // Reload subjects
        $subjects = $user->subjects()
            ->select(
                'subjects.id',
                'subjects.name',
                'subjects.slug',
                'subjects.icon',
                'subjects.color'
            )
            ->orderBy('subjects.sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Subjects updated successfully.',
            'subjects' => $subjects,
        ]);
    }
}
