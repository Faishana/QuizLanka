<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Subject;
use Illuminate\Http\Request;

class StudentSubjectController extends Controller
{
    /**
     * Get all active subjects (no grade filtering).
     */
    public function index(Request $request)
    {
        $subjects = Subject::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($subject) {
                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'slug' => $subject->slug,
                    'icon' => $subject->icon,
                    'color' => $subject->color,
                    'description' => $subject->description,
                ];
            });

        return response()->json([
            'success' => true,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Get a single subject by ID.
     */
    public function show(Request $request, $id)
    {
        $subject = Subject::where('id', $id)
            ->where('is_active', true)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'slug' => $subject->slug,
                'icon' => $subject->icon,
                'color' => $subject->color,
                'description' => $subject->description,
            ],
        ]);
    }
}
