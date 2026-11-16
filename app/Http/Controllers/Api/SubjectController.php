<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    private const AL_STREAMS = [
        'bio',
        'maths',
        'technology',
        'arts',
        'commerce',
    ];

    /**
     * Validate selected grades belong to selected category.
     * Government subjects do not require grades.
     */
    private function validateGradeCategory(
        array $gradeIds,
        string $category
    ): void {
        if ($category === 'gov') {
            return;
        }

        $grades = Grade::whereIn('id', $gradeIds)->get();

        if ($grades->count() !== count(array_unique($gradeIds))) {
            throw ValidationException::withMessages([
                'grade_ids' => [
                    'One or more selected grades are invalid.',
                ],
            ]);
        }

        $invalidGrades = $grades->filter(
            fn ($grade) => $grade->category !== $category
        );

        if ($invalidGrades->isNotEmpty()) {
            throw ValidationException::withMessages([
                'grade_ids' => [
                    'Selected grades must belong to the selected category.',
                ],
            ]);
        }
    }

    /**
     * Validate A/L stream assignments.
     */
    private function validateStreams(array $validated): void
    {
        $category = $validated['grade_category'];
        $streams = $validated['al_streams'] ?? [];

        if ($category === 'al' && empty($streams)) {
            throw ValidationException::withMessages([
                'al_streams' => [
                    'Please select at least one A/L stream.',
                ],
            ]);
        }

        if ($category !== 'al' && !empty($streams)) {
            throw ValidationException::withMessages([
                'al_streams' => [
                    'A/L streams are only allowed for A/L subjects.',
                ],
            ]);
        }
    }

    /**
     * Ensure non-government subjects have at least one grade selected.
     */
    private function validateGradeIdsRequired(
        array $gradeIds,
        string $category
    ): void {
        if ($category === 'gov') {
            return;
        }

        if (empty($gradeIds)) {
            throw ValidationException::withMessages([
                'grade_ids' => [
                    'Please select at least one grade.',
                ],
            ]);
        }
    }

    /**
     * Sync subject grade assignments.
     */
    private function syncGradeAssignments(
        Subject $subject,
        array $gradeIds,
        string $category,
        array $streams = []
    ): void {
        $streams = $category === 'al'
            ? array_values(array_unique($streams))
            : [];

        $pivotData = [
            'al_streams' => !empty($streams)
                ? json_encode($streams)
                : null,
        ];

        $subject->grades()->syncWithPivotValues(
            $gradeIds,
            $pivotData
        );
    }

    /**
     * Transform subject response.
     */
    private function transformSubject(Subject $subject): array
    {
        $grades = $subject->relationLoaded('grades')
            ? $subject->grades
            : $subject->grades()->get();

        $allStreams = [];

        $formattedGrades = $grades->map(
            function ($grade) use (&$allStreams) {
                $pivotStreams = $grade->pivot->al_streams ?? null;

                if (is_string($pivotStreams)) {
                    $pivotStreams = json_decode($pivotStreams, true);
                }

                if (!is_array($pivotStreams)) {
                    $pivotStreams = [];
                }

                $pivotStreams = array_values(
                    array_unique($pivotStreams)
                );

                $allStreams = array_merge(
                    $allStreams,
                    $pivotStreams
                );

                return [
                    'id' => $grade->id,
                    'name' => $grade->name,
                    'slug' => $grade->slug,
                    'category' => $grade->category,
                    'al_streams' => $pivotStreams,
                ];
            }
        )->values();

        $allStreams = array_values(
            array_unique($allStreams)
        );

        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'slug' => $subject->slug,

            // Read category directly from subjects table.
            'grade_category' => $subject->grade_category,

            'grades' => $formattedGrades,
            'grade_ids' => $grades->pluck('id')->values(),

            'al_streams' => $allStreams,

            'icon' => $subject->icon
                ? Storage::disk('s3')->url($subject->icon)
                : null,

            'color' => $subject->color,
            'description' => $subject->description,
            'is_active' => $subject->is_active,
            'sort_order' => $subject->sort_order,
            'created_at' => $subject->created_at,
            'updated_at' => $subject->updated_at,
        ];
    }

    /**
     * All Subjects.
     */
    public function index(Request $request)
    {
        $subjects = Subject::with('grades')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects->map(
                fn ($subject) => $this->transformSubject($subject)
            ),
        ]);
    }

    /**
     * Subjects By Grade.
     */
    public function byGrade(Request $request, $gradeId)
    {
        $validated = $request->validate([
            'al_stream' => 'nullable|in:bio,maths,technology,arts,commerce',
        ]);

        $grade = Grade::findOrFail($gradeId);

        $query = $grade->subjects()
            ->with('grades')
            ->where('subjects.is_active', true);

        if (
            $grade->category === 'al' &&
            !empty($validated['al_stream'])
        ) {
            $query->whereJsonContains(
                'grade_subjects.al_streams',
                $validated['al_stream']
            );
        }

        $subjects = $query
            ->orderBy('subjects.sort_order')
            ->get();

        return response()->json([
            'success' => true,

            'grade' => [
                'id' => $grade->id,
                'name' => $grade->name,
                'category' => $grade->category,
            ],

            'al_stream' => $validated['al_stream'] ?? null,

            'data' => $subjects->map(
                fn ($subject) => $this->transformSubject($subject)
            ),
        ]);
    }

    /**
     * Create Subject.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'grade_category' => 'required|in:scholarship,ol,al,gov',

            // Empty arrays are allowed here; a manual check below
            // enforces "at least one grade" for non-gov categories.
            'grade_ids' => 'nullable|array',
            'grade_ids.*' => 'required|integer|distinct|exists:grades,id',

            'al_streams' => 'nullable|array',
            'al_streams.*' => 'required|distinct|in:bio,maths,technology,arts,commerce',

            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',

            'icon' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $gradeIds = $validated['grade_ids'] ?? [];
        $streams = $validated['al_streams'] ?? [];

        // Manual "required for non-gov" check (replaces min:1 rule).
        $this->validateGradeIdsRequired(
            $gradeIds,
            $validated['grade_category']
        );

        $this->validateStreams($validated);

        $this->validateGradeCategory(
            $gradeIds,
            $validated['grade_category']
        );

        $iconPath = null;

        try {
            if ($request->hasFile('icon')) {
                $iconPath = $request->file('icon')
                    ->store('subjects', 's3');
            }

            $subject = DB::transaction(function () use (
                $validated,
                $gradeIds,
                $streams,
                $iconPath
            ) {
                $subject = Subject::create([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name']),
                    'grade_category' => $validated['grade_category'],
                    'color' => $validated['color'] ?? '#3B82F6',
                    'description' => $validated['description'] ?? null,
                    'sort_order' => $validated['sort_order'] ?? 0,
                    'icon' => $iconPath,
                    'is_active' => true,
                ]);

                $this->syncGradeAssignments(
                    $subject,
                    $gradeIds,
                    $validated['grade_category'],
                    $streams
                );

                return $subject;
            });

            $subject->load('grades');

            return response()->json([
                'success' => true,
                'message' => 'Subject created successfully.',
                'data' => $this->transformSubject($subject),
            ], 201);

        } catch (\Throwable $e) {
            if ($iconPath) {
                Storage::disk('s3')->delete($iconPath);
            }

            throw $e;
        }
    }

    /**
     * Update Subject.
     */
    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',

            'grade_category' => 'required|in:scholarship,ol,al,gov',

            // Empty arrays are allowed here; a manual check below
            // enforces "at least one grade" for non-gov categories.
            'grade_ids' => 'nullable|array',
            'grade_ids.*' => 'required|integer|distinct|exists:grades,id',

            'al_streams' => 'nullable|array',
            'al_streams.*' => 'required|distinct|in:bio,maths,technology,arts,commerce',

            'color' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',

            'icon' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',

            'is_active' => 'nullable|boolean',
        ]);

        $gradeIds = $validated['grade_ids'] ?? [];
        $streams = $validated['al_streams'] ?? [];

        // Manual "required for non-gov" check (replaces min:1 rule).
        $this->validateGradeIdsRequired(
            $gradeIds,
            $validated['grade_category']
        );

        $this->validateStreams($validated);

        $this->validateGradeCategory(
            $gradeIds,
            $validated['grade_category']
        );

        $oldIconPath = $subject->icon;
        $newIconPath = null;

        try {
            if ($request->hasFile('icon')) {
                $newIconPath = $request->file('icon')
                    ->store('subjects', 's3');
            }

            DB::transaction(function () use (
                $subject,
                $validated,
                $gradeIds,
                $streams,
                $newIconPath
            ) {
                $subject->update([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name']),

                    'grade_category' => $validated['grade_category'],

                    'color' => $validated['color'] ?? $subject->color,

                    'description' => $validated['description']
                        ?? $subject->description,

                    'sort_order' => $validated['sort_order']
                        ?? $subject->sort_order,

                    'icon' => $newIconPath ?? $subject->icon,

                    'is_active' => $validated['is_active']
                        ?? $subject->is_active,
                ]);

                $this->syncGradeAssignments(
                    $subject,
                    $gradeIds,
                    $validated['grade_category'],
                    $streams
                );
            });

            if ($newIconPath && $oldIconPath) {
                Storage::disk('s3')->delete($oldIconPath);
            }

            $subject->load('grades');

            return response()->json([
                'success' => true,
                'message' => 'Subject updated successfully.',
                'data' => $this->transformSubject($subject),
            ]);

        } catch (\Throwable $e) {
            if ($newIconPath) {
                Storage::disk('s3')->delete($newIconPath);
            }

            throw $e;
        }
    }

    /**
     * Delete Subject.
     */
    public function destroy(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $iconPath = $subject->icon;

        DB::transaction(function () use ($subject) {
            $subject->grades()->detach();
            $subject->delete();
        });

        if ($iconPath) {
            Storage::disk('s3')->delete($iconPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully.',
        ]);
    }

    /**
     * Public Subjects.
     */
    public function publicSubjects(Request $request)
    {
        $validated = $request->validate([
            'grade_id' => 'nullable|integer|exists:grades,id',
            'grade_category' => 'nullable|in:scholarship,ol,al,gov',
            'al_stream' => 'nullable|in:bio,maths,technology,arts,commerce',
        ]);

        $query = Subject::with('grades')
            ->where('is_active', true);

        // Filter by a specific grade.
        if (!empty($validated['grade_id'])) {
            $gradeId = $validated['grade_id'];

            $query->whereHas('grades', function ($q) use ($gradeId) {
                $q->where('grades.id', $gradeId);
            });
        }

        // Filter category directly from subjects table.
        // This also includes government subjects without grades.
        if (!empty($validated['grade_category'])) {
            $query->where(
                'subjects.grade_category',
                $validated['grade_category']
            );
        }

        // Filter A/L streams.
        if (!empty($validated['al_stream'])) {
            $stream = $validated['al_stream'];
            $gradeId = $validated['grade_id'] ?? null;

            $query->whereHas('grades', function ($q) use ($stream, $gradeId) {
                $q->where('grades.category', 'al')
                    ->whereJsonContains(
                        'grade_subjects.al_streams',
                        $stream
                    );

                if ($gradeId) {
                    $q->where('grades.id', $gradeId);
                }
            });
        }

        $subjects = $query
            ->orderBy('subjects.sort_order')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $subjects->map(
                fn ($subject) => $this->transformSubject($subject)
            ),
        ]);
    }
}
