<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Material;

class StudentMaterialController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $materials = Material::where('grade_id', $user->grade_id)
            ->where('processing_status', 'completed')
            ->with('subject:id,name,color')
            ->latest()
            ->get()
            ->map(function ($material) {
                return [
                    'id' => $material->id,
                    'title' => $material->title,
                    'material_type' => $material->material_type,
                    'subject' => [
                        'id' => $material->subject->id,
                        'name' => $material->subject->name,
                        'color' => $material->subject->color,
                    ],
                    'questions_count' => $material->questions()->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'materials' => $materials,
        ]);
    }

    public function bySubject(Request $request, $subjectId)
    {
        $user = $request->user();

        $materials = Material::where('grade_id', $user->grade_id)
            ->where('subject_id', $subjectId)
            ->where('processing_status', 'completed')
            ->latest()
            ->get()
            ->map(function ($material) {
                return [
                    'id' => $material->id,
                    'title' => $material->title,
                    'material_type' => $material->material_type,
                    'questions_count' => $material->questions()->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'materials' => $materials,
        ]);
    }

    public function show(Request $request, $id)
{
    $material = Material::with('subject:id,name,color')
        ->withCount('questions')
        ->where('id', $id)
        ->where('grade_id', $request->user()->grade_id)
        ->where('processing_status', 'completed')
        ->first();

    if (!$material) {
        return response()->json([
            'success' => false,
            'message' => 'Material not found or not available for your grade.'
        ], 404);
    }

    return response()->json([
        'success' => true,
        'material' => [
            'id' => $material->id,
            'title' => $material->title,
            'material_type' => $material->material_type,
            'subject' => [
                'id' => $material->subject->id,
                'name' => $material->subject->name,
                'color' => $material->subject->color,
            ],
            'questions_count' => $material->questions_count,
        ]
    ]);
}
}
