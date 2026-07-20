<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Grade;
use App\Models\Subject;
use App\Models\Material;
use App\Models\Question;
use App\Models\Quiz;

class DashboardController extends Controller
{
    public function adminDashboard(Request $request)
    {
        return response()->json([
            'success' => true,
            'stats' => [
                'grades' => Grade::count(),

                'subjects' => Subject::count(),

                'materials' => Material::count(),

                'questions' => Question::count(),
            ]
        ]);
    }

   
}
