<?php

namespace App\Http\Controllers\Api;

use App\Models\Subject;
use App\Models\Grade;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class SubjectController extends Controller
{
    /**
     * All Subjects (Only Admin's Own Subjects)
     */
    public function index(Request $request)
    {
        $subjects = Subject::orderBy('sort_order')
        ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects,
        ]);
    }

    /**
     * Subjects By Grade (Only Admin's Own Subjects)
     */
    public function byGrade(Request $request, $gradeId)
    {
        $grade = Grade::findOrFail($gradeId);

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

        $grade = Grade::findOrFail($validated['grade_id']);

       try {
            $subject = Subject::create([
                'grade_id' => $grade->id,
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'color' => $validated['color'] ?? '#3B82F6',
                'description' => $validated['description'] ?? null,
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'data' => $subject
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update Subject (Only Admin's Own Subject)
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

        $grade = Grade::findOrFail($validated['grade_id']);

        $subject->update([
            'grade_id' => $grade->id,
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
     * Delete Subject (Only Admin's Own Subject)
     */
    public function destroy(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully.',
        ]);
    }
}
