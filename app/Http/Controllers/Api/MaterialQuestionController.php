<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Material;

class MaterialQuestionController extends Controller
{
    public function index(Material $material)
    {
        $questions = $material->questions()
            ->with('options')
            ->get();

        return response()->json([
            'success' => true,
            'material_id' => $material->id,
            'question_count' => $questions->count(),
            'data' => $questions
        ]);
    }
}
