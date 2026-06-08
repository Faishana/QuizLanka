<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class LessonController extends Controller
{
    /**
     * All Lessons
     */
    public function all()
    {
        return response()->json([
            'success' => true,
            'data' => Lesson::latest()->get()
        ]);
    }

    /**
     * Lessons By Subject
     */
    public function index($subjectId)
    {
        $lessons = Lesson::where('subject_id', $subjectId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $lessons,
        ]);
    }

    /**
     * Create Lesson
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]);

        $lesson = Lesson::create([
            'subject_id' => $validated['subject_id'],
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']),
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lesson created successfully.',
            'data' => $lesson
        ]);
    }

    /**
     * Update Lesson
     */
    public function update(Request $request, $id)
    {
        $lesson = Lesson::findOrFail($id);

        $validated = $request->validate([
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $lesson->update([
            'subject_id' => $validated['subject_id'],
            'title' => $validated['title'],
            'slug' => Str::slug($validated['title']),
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lesson updated successfully.',
            'data' => $lesson->fresh()
        ]);
    }

    /**
     * Delete Lesson
     */
    public function destroy($id)
    {
        Lesson::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lesson deleted successfully.'
        ]);
    }
}
