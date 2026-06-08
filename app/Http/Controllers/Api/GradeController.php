<?php

namespace App\Http\Controllers\Api;

use App\Models\Grade;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class GradeController extends Controller
{
    public function index()
    {
        $grades = Grade::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([

            'success' => true,

            'data' => $grades,
        ]);
    }
}
