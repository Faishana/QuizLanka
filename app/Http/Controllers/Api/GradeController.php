<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GradeController extends Controller
{
    /**
     * List all grades
     */
    public function index()
    {
        $grades = Grade::orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $grades,
        ]);
    }

    /**
     * Create Grade
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:grades,name',
            'category' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);

        $nextSortOrder = (Grade::max('sort_order') ?? 0) + 1;

        $grade = Grade::create([
            'name' => trim($validated['name']),
            'slug' => Str::slug($validated['name']),
            'category' => $validated['category'] ?? null,
            'sort_order' => $nextSortOrder,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grade created successfully.',
            'data' => $grade,
        ], 201);
    }

    /**
     * Show Grade
     */
    public function show($id)
    {
        $grade = Grade::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $grade,
        ]);
    }

    /**
     * Update Grade
     */
    public function update(Request $request, $id)
    {
        $grade = Grade::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:grades,name,' . $grade->id,
            'category' => 'sometimes|nullable|string|max:255',
            'sort_order' => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
            $validated['slug'] = Str::slug($validated['name']);
        }

        $grade->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Grade updated successfully.',
            'data' => $grade->fresh(),
        ]);
    }

    /**
     * Delete Grade
     */
    public function destroy($id)
    {
        $grade = Grade::findOrFail($id);

        $grade->delete();

        return response()->json([
            'success' => true,
            'message' => 'Grade deleted successfully.',
        ]);
    }

    /**
     * Public Grades
     */
    public function publicGrades()
    {
        $grades = Grade::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $grades,
        ]);
    }
}
