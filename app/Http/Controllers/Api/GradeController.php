<?php

namespace App\Http\Controllers\Api;

use App\Models\Grade;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Str;

class GradeController extends Controller
{
    /**
     * List Grades
     */
    public function index()
    {
        $grades = Grade::orderBy('sort_order')->get();

        return response()->json([
            'success' => true,
            'data' => $grades
        ]);
    }

    /**
     * Create Grade
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean'
        ]);

        $grade = Grade::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'category' => $validated['category'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Grade created successfully',
            'data' => $grade
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
            'data' => $grade
        ]);
    }

   /**
     * Update Grade
     */
    public function update(Request $request, $id)
    {
        $grade = Grade::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|nullable|string|max:255',
            'sort_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean'
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $grade->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Grade updated successfully',
            'data' => $grade->fresh()
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
            'message' => 'Grade deleted successfully'
        ]);
    }
}
