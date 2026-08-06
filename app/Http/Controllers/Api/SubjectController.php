<?php

namespace App\Http\Controllers\Api;

use App\Models\Subject;
use App\Models\Grade;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class SubjectController extends Controller
{
    /**
     * Transform subject to include full image URL from R2 (via S3 disk)
     */
    private function transformSubject(Subject $subject)
    {
        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'slug' => $subject->slug,
            'icon' => $subject->icon
                ? Storage::disk('s3')->url($subject->icon)
                : null,
            'color' => $subject->color,
            'is_active' => $subject->is_active,
            'sort_order' => $subject->sort_order,
            'created_at' => $subject->created_at,
            'updated_at' => $subject->updated_at,
        ];
    }

    /**
     * All Subjects (Only Admin's Own Subjects)
     */
    public function index(Request $request)
    {
        $subjects = Subject::orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => $subjects->map(fn ($subject) => $this->transformSubject($subject)),
        ]);
    }

    /**
     * Subjects By Grade (Only Admin's Own Subjects)
     */
    public function byGrade(Request $request, $gradeId)
    {
        $grade = Grade::findOrFail($gradeId);

        // Changed to use many-to-many relationship
        $subjects = $grade->subjects()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects->map(fn ($subject) => $this->transformSubject($subject)),
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
            'icon' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
        ]);

        try {
            // Handle icon upload to S3 (Cloudflare R2)
            $iconPath = null;

            if ($request->hasFile('icon')) {
                $iconPath = $request->file('icon')->store('subjects', 's3');
            }

            // Use firstOrNew instead of firstOrCreate to allow icon updates
            $subject = Subject::firstOrNew([
                'slug' => Str::slug($validated['name']),
            ]);

            $subject->name = $validated['name'];
            $subject->color = $validated['color'] ?? '#3B82F6';
            $subject->is_active = true;

            // Only update icon if a new one was uploaded
            if ($iconPath) {
                $subject->icon = $iconPath;
            }

            $subject->save();

            // Attach subject to grade
            $grade = Grade::findOrFail($validated['grade_id']);

            $grade->subjects()->syncWithoutDetaching([
                $subject->id,
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->transformSubject($subject->fresh()),
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
            'icon' => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:2048',
            'is_active' => 'nullable|boolean',
        ]);

        // Handle icon upload to S3 (Cloudflare R2)
        $iconPath = $subject->icon;

        if ($request->hasFile('icon')) {
            // Delete old icon from S3 if exists
            if ($subject->icon && Storage::disk('s3')->exists($subject->icon)) {
                Storage::disk('s3')->delete($subject->icon);
            }

            $iconPath = $request->file('icon')->store('subjects', 's3');
        }

        // Updated to remove grade_id from subject update
        $subject->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'color' => $validated['color'] ?? $subject->color,
            'icon' => $iconPath,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Sync the grade relationship
        $grade = Grade::findOrFail($validated['grade_id']);
        $grade->subjects()->syncWithoutDetaching([$subject->id]);

        return response()->json([
            'success' => true,
            'message' => 'Subject updated successfully.',
            'data' => $this->transformSubject($subject->fresh()),
        ]);
    }

    /**
     * Delete Subject (Only Admin's Own Subject)
     */
    public function destroy(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        // Delete icon from S3 if exists
        if ($subject->icon && Storage::disk('s3')->exists($subject->icon)) {
            Storage::disk('s3')->delete($subject->icon);
        }

        $subject->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully.',
        ]);
    }

    /**
     * Public Subjects (For students/parents)
     */
    public function publicSubjects()
    {
        $subjects = Subject::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects->map(fn ($subject) => $this->transformSubject($subject)),
        ]);
    }
}
