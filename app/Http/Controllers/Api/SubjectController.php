<?php

namespace App\Http\Controllers\Api;

use App\Models\Subject;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class SubjectController extends Controller
{
    /**
     * All Subjects
     */
    public function index()
    {
        $subjects = Subject::orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    /**
     * Subjects By Grade
     */
    public function byGrade($gradeId)
    {
        $subjects = Subject::where('grade_id', $gradeId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    /**
     * Create Subject
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'name' => 'required|string|max:255',
            'color' => 'nullable|string',
            'description' => 'nullable|string',
        ]);

        $subject = Subject::create([
            'grade_id' => $validated['grade_id'],
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'color' => $validated['color'] ?? '#3B82F6',
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject created successfully.',
            'data' => $subject,
        ], 201);
    }

    /**
     * Update Subject
     */
    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'name' => 'required|string|max:255',
            'color' => 'nullable|string',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $subject->update([
            'grade_id' => $validated['grade_id'],
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'color' => $validated['color'] ?? $subject->color,
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Subject updated successfully.',
            'data' => $subject->fresh(),
        ]);
    }

    /**
     * Delete Subject
     */
    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully.',
        ]);
    }
}
