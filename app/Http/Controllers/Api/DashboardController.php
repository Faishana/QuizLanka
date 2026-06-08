<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use Illuminate\Http\Request;
use App\Models\Subject;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Material;
use App\Models\Question;

class DashboardController extends Controller
{

    // Admin dashboard with overall stats

    public function adminDashboard()
    {
        return response()->json([
            'success' => true,

            'stats' => [
                'grades' => Grade::count(),
                'subjects' => Subject::count(),
                'lessons' => Lesson::count(),
                'materials' => Material::count(),
                'questions' => Question::count(),
            ]
        ]);
    }
}
