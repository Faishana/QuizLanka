<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    /**
     * Display student profile.
     */
    public function show(Request $request)
    {
        $user = $request->user()->load('grade');

        return response()->json([
            'success' => true,

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'school' => $user->school,
                'district' => $user->district,
                'preferred_medium' => $user->preferred_medium,
                'target_exam' => $user->target_exam,
                'profile_image' => $user->profile_image,

                'grade' => [
                    'id' => $user->grade?->id,
                    'name' => $user->grade?->name,
                ],
            ],
        ]);
    }

    /**
     * Update student profile.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'grade_id' => 'required|exists:grades,id',
            'school' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
            'preferred_medium' => 'nullable|in:Sinhala,English,Tamil',
        ]);

        $user = $request->user();

        // Get selected grade
        $grade = Grade::findOrFail($validated['grade_id']);

        // Determine target exam from grade category
        $targetExam = match ($grade->category) {
            'primary'   => 'Grade 5 Scholarship',
            'ol'        => 'GCE O/L',
            'al'        => 'GCE A/L',
            'government'=> 'Government Exams',
            default     => null,
        };

        $user->update([
            'name' => $validated['name'],
            'grade_id' => $validated['grade_id'],
            'school' => $validated['school'] ?? null,
            'district' => $validated['district'] ?? null,
            'preferred_medium' => $validated['preferred_medium'] ?? null,
            'target_exam' => $targetExam,
        ]);

        $user->load('grade');

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',

            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'school' => $user->school,
                'district' => $user->district,
                'preferred_medium' => $user->preferred_medium,
                'target_exam' => $user->target_exam,
                'profile_image' => $user->profile_image,

                'grade' => [
                    'id' => $user->grade?->id,
                    'name' => $user->grade?->name,
                ],
            ],
        ]);
    }
}
