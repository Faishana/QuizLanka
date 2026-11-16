<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateProfileAvatarJob;
use App\Models\Grade;
use App\Models\Quiz;
use App\Services\StatisticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentProfileController extends Controller
{
    /**
     * Display complete student profile.
     *
     * GET /api/profile
     */
    public function show(Request $request)
    {
        $user = $request->user();

        $user->load('grade');

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        $statistics = null;

        try {
            $statistics = StatisticsService::refresh($user);
        } catch (\Throwable $e) {
            $statistics = $user->statistics ?? null;
        }

        /*
        |--------------------------------------------------------------------------
        | Completed Quizzes
        |--------------------------------------------------------------------------
        */

        $completedQuizzes = Quiz::where('user_id', $user->id)
            ->where('is_completed', true);

        $completedQuizCount = (clone $completedQuizzes)->count();

        $averageAccuracy = round(
            (float) ((clone $completedQuizzes)->avg('percentage') ?? 0),
            2
        );

        $timeSpentMinutes = (int) round(
            ((clone $completedQuizzes)->sum('duration_seconds') ?? 0) / 60
        );

        /*
        |--------------------------------------------------------------------------
        | Statistics Values
        |--------------------------------------------------------------------------
        */

        $xp = (int) ($statistics?->xp ?? 0);

        $level = (int) ($statistics?->level ?? 1);

        $currentStreak = (int) ($statistics?->current_streak ?? 0);

        $longestStreak = (int) ($statistics?->longest_streak ?? 0);

        $requiredXp = (int) (
            data_get($statistics, 'required_xp')
            ?? ($level * 1000)
        );

        /*
        |--------------------------------------------------------------------------
        | National / School Ranking
        |--------------------------------------------------------------------------
        */

        $ranking = $this->getRanking(
            $user,
            $statistics,
            $xp
        );

        /*
        |--------------------------------------------------------------------------
        | Subject Progress
        |--------------------------------------------------------------------------
        |
        | Get subjects according to the student's grade.
        |
        | A/L students receive only subjects assigned to their
        | selected stream through grade_subjects.al_streams.
        |
        */

        if ($user->grade) {

            $subjectsQuery = $user->grade->subjects()
                ->where('subjects.is_active', true);

            // Filter A/L subjects by selected stream.
            if ($user->grade->category === 'al') {

                $stream = strtolower(trim($user->al_stream ?? ''));

                $allowedStreams = [
                    'bio',
                    'maths',
                    'technology',
                    'arts',
                    'commerce',
                ];

                if (in_array($stream, $allowedStreams, true)) {

                    $subjectsQuery->whereJsonContains(
                        'grade_subjects.al_streams',
                        $stream
                    );

                } else {

                    // No subjects if the A/L stream is missing or invalid.
                    $subjectsQuery->whereRaw('1 = 0');
                }
            }

            $subjects = $subjectsQuery
                ->select([
                    'subjects.id',
                    'subjects.name',
                    'subjects.icon',
                    'subjects.color',
                ])
                ->distinct()
                ->orderBy('subjects.name')
                ->get();

        } else {

            $subjects = collect();
        }

        /*
        |--------------------------------------------------------------------------
        | Subject Analytics
        |--------------------------------------------------------------------------
        */

        $subjects = $subjects
            ->map(function ($subject) use ($user) {

                $subjectQuizzes = Quiz::where('user_id', $user->id)
                    ->where('subject_id', $subject->id)
                    ->where('is_completed', true);

                $quizCount = (clone $subjectQuizzes)->count();

                $accuracy = round(
                    (float) ((clone $subjectQuizzes)->avg('percentage') ?? 0),
                    2
                );

                $subjectXp = (int) (
                    (clone $subjectQuizzes)->sum('score') ?? 0
                );

                /*
                |--------------------------------------------------------------------------
                | Subject Rank
                |--------------------------------------------------------------------------
                */

                $subjectRank = 0;

                if ($quizCount > 0) {

                    $subjectRank = $this->getSubjectRank(
                        $subject->id,
                        $user->id,
                        $subjectXp
                    );
                }

                return [
                    'id' => $subject->id,
                    'name' => $subject->name,
                    'accuracy' => $accuracy,
                    'quizzes_completed' => $quizCount,
                    'xp' => $subjectXp,
                    'rank' => $subjectRank,
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Weekly Progress
        |--------------------------------------------------------------------------
        |
        | Last 7 days.
        | Each point represents that day's average quiz percentage.
        |
        */

        $weeklyProgress = collect();

        for ($i = 6; $i >= 0; $i--) {

            $date = now()->subDays($i);

            $value = Quiz::where('user_id', $user->id)
                ->where('is_completed', true)
                ->whereDate('completed_at', $date->toDateString())
                ->avg('percentage');

            $weeklyProgress->push([
                'day' => $date->format('D'),
                'value' => round((float) ($value ?? 0), 2),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Strongest Subject
        |--------------------------------------------------------------------------
        */

        $strongestSubject = null;

        $subjectWithHighestAccuracy = $subjects
            ->filter(function ($subject) {
                return $subject['quizzes_completed'] > 0;
            })
            ->sortByDesc('accuracy')
            ->first();

        if ($subjectWithHighestAccuracy) {

            $strongestSubject = [
                'name' => $subjectWithHighestAccuracy['name'],
                'accuracy' => $subjectWithHighestAccuracy['accuracy'],
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | AI / Weekly Improvement
        |--------------------------------------------------------------------------
        */

        $currentWeekAverage = (float) (
            Quiz::where('user_id', $user->id)
                ->where('is_completed', true)
                ->where(
                    'completed_at',
                    '>=',
                    now()->subDays(7)
                )
                ->avg('percentage') ?? 0
        );

        $previousWeekAverage = (float) (
            Quiz::where('user_id', $user->id)
                ->where('is_completed', true)
                ->whereBetween('completed_at', [
                    now()->subDays(14),
                    now()->subDays(7),
                ])
                ->avg('percentage') ?? 0
        );

        $aiImprovement = null;

        if ($previousWeekAverage > 0) {

            $improvement = round(
                $currentWeekAverage - $previousWeekAverage,
                2
            );

            $aiImprovement = [
                'percentage' => $improvement,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Achievements
        |--------------------------------------------------------------------------
        */

        $achievements = $this->getAchievements($user);

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            /*
            |--------------------------------------------------------------------------
            | User
            |--------------------------------------------------------------------------
            */

            'user' => [

                'id' => $user->id,

                'name' => $user->name,

                'email' => $user->email,

                'user_category' => $user->user_category,

                'school' => $user->school,

                'district' => $user->district,

                'preferred_medium' => $user->preferred_medium,

                'target_exam' => $user->target_exam,

                'profile_image' => $user->profile_image,

                'grade_category' => $user->grade?->category,

                'grade_id' => $user->grade_id,

                'al_stream' => $user->al_stream,

                'grade' => [
                    'id' => $user->grade?->id,
                    'name' => $user->grade?->name,
                    'slug' => $user->grade?->slug,
                    'category' => $user->grade?->category,
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Statistics
            |--------------------------------------------------------------------------
            */

            'statistics' => [

                'level' => $level,

                'xp' => $xp,

                'required_xp' => $requiredXp,

                'completed_quizzes' => $completedQuizCount,

                'average_accuracy' => $averageAccuracy,

                'time_spent_minutes' => $timeSpentMinutes,

                'current_streak' => $currentStreak,

                'longest_streak' => $longestStreak,
            ],

            /*
            |--------------------------------------------------------------------------
            | Ranking
            |--------------------------------------------------------------------------
            */

            'ranking' => [

                'national_rank' => $ranking['national_rank'],

                'school_rank' => $ranking['school_rank'],
            ],

            /*
            |--------------------------------------------------------------------------
            | Achievements
            |--------------------------------------------------------------------------
            */

            'achievements' => $achievements,

            /*
            |--------------------------------------------------------------------------
            | Subject Analytics
            |--------------------------------------------------------------------------
            */

            'subjects' => $subjects,

            /*
            |--------------------------------------------------------------------------
            | Weekly Progress
            |--------------------------------------------------------------------------
            */

            'weekly_progress' => $weeklyProgress,

            /*
            |--------------------------------------------------------------------------
            | Strongest Subject
            |--------------------------------------------------------------------------
            */

            'strongest_subject' => $strongestSubject,

            /*
            |--------------------------------------------------------------------------
            | AI Improvement
            |--------------------------------------------------------------------------
            */

            'ai_improvement' => $aiImprovement,
        ]);
    }


    /**
     * Update student or government exam candidate profile.
     *
     * PUT /api/profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'grade_category' => [
                'required',
                'in:scholarship,ol,al,gov',
            ],

            'grade_id' => [
                'nullable',
                'required_unless:grade_category,gov',
                'integer',
                'exists:grades,id',
            ],

            'al_stream' => [
                'nullable',
                'required_if:grade_category,al',
                'string',
                'in:bio,maths,technology,arts,commerce',
            ],

            'school' => [
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'nullable',
                'string',
                'max:255',
            ],

            'preferred_medium' => [
                'nullable',
                'in:Sinhala,English,Tamil',
            ],

            'target_exam' => [
                'nullable',
                'required_if:grade_category,gov',
                'string',
                'max:255',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Determine Account Category
        |--------------------------------------------------------------------------
        */

        $isGovernmentCandidate =
            $validated['grade_category'] === 'gov';

        $isAlStudent =
            $validated['grade_category'] === 'al';

        /*
        |--------------------------------------------------------------------------
        | Validate Selected Grade
        |--------------------------------------------------------------------------
        */

        $grade = null;

        if (!$isGovernmentCandidate) {

            $grade = Grade::where('id', $validated['grade_id'])
                ->where('category', $validated['grade_category'])
                ->where('is_active', true)
                ->first();

            if (!$grade) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected grade does not belong to the selected category.',
                    'errors' => [
                        'grade_id' => [
                            'Please select a valid grade for the selected category.',
                        ],
                    ],
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate A/L Stream
        |--------------------------------------------------------------------------
        */

        $isValidAlGrade = $isAlStudent
            && strtolower($grade->category ?? '') === 'al';

        if ($isValidAlGrade && empty($validated['al_stream'])) {
            return response()->json([
                'success' => false,
                'message' => 'Please select your A/L stream.',
                'errors' => [
                    'al_stream' => [
                        'An A/L stream is required.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare Profile Data
        |--------------------------------------------------------------------------
        */

        $updateData = [
            'name' => $validated['name'],

            'user_category' => $isGovernmentCandidate
                ? \App\Models\User::CATEGORY_GOV_EXAM
                : \App\Models\User::CATEGORY_STUDENT,

            'grade_id' => $grade?->id,

            'al_stream' => $isValidAlGrade
                ? $validated['al_stream']
                : null,

            'school' => $isGovernmentCandidate
                ? null
                : ($validated['school'] ?? null),

            'district' => $validated['district'] ?? null,

            'preferred_medium' =>
                $validated['preferred_medium'] ?? null,

            'target_exam' => $isGovernmentCandidate
                ? $validated['target_exam']
                : match ($validated['grade_category']) {
                    'scholarship' => 'Grade 5 Scholarship',
                    'ol' => 'GCE O/L',
                    'al' => 'GCE A/L',
                },
        ];

        /*
        |--------------------------------------------------------------------------
        | Update Profile and Synchronize Role
        |--------------------------------------------------------------------------
        */

        try {

            DB::transaction(function () use ($user, $updateData) {

                $user->update($updateData);

                // Synchronize Spatie role with the updated category.
                $user->syncCategoryRole();

            });

            $user->refresh();
            $user->load('grade');

            /*
            |--------------------------------------------------------------------------
            | Response
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully.',

                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,

                    'user_category' => $user->user_category,

                    'school' => $user->school,
                    'district' => $user->district,
                    'preferred_medium' => $user->preferred_medium,

                    'target_exam' => $user->target_exam,

                    'profile_image' => $user->profile_image,

                    'grade_category' => $user->grade?->category,

                    'grade_id' => $user->grade_id,

                    'al_stream' => $user->al_stream,

                    'grade' => $user->grade ? [
                        'id' => $user->grade->id,
                        'name' => $user->grade->name,
                        'slug' => $user->grade->slug,
                        'category' => $user->grade->category,
                    ] : null,
                ],
            ]);

        } catch (\Throwable $e) {

            Log::error('Student profile update failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update profile. Please try again.',
            ], 500);
        }
    }



    /**
     * Upload profile photo and generate AI avatar.
     *
     * POST /api/profile/photo
     */
    public function uploadPhoto(Request $request)
    {
        $request->validate([

            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Temporary Upload
        |--------------------------------------------------------------------------
        */

        $tempPath = $request->file('photo')->store(
            'temp/profile',
            'local'
        );

        /*
        |--------------------------------------------------------------------------
        | Queue Avatar Generation
        |--------------------------------------------------------------------------
        */

        GenerateProfileAvatarJob::dispatch(
            $user->id,
            $tempPath
        );

        return response()->json([

            'success' => true,

            'message' => 'Avatar generation has started.',
        ]);
    }


    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================


    /**
     * Get national and school ranking.
     */
    private function getRanking(
        $user,
        $statistics,
        int $xp
    ): array {

        if (!$statistics) {

            return [
                'national_rank' => 0,
                'school_rank' => 0,
            ];
        }

        $table = $statistics->getTable();

        /*
        |--------------------------------------------------------------------------
        | National Rank
        |--------------------------------------------------------------------------
        */

        $nationalRank = DB::table($table)
            ->where('xp', '>', $xp)
            ->count() + 1;

        /*
        |--------------------------------------------------------------------------
        | School Rank
        |--------------------------------------------------------------------------
        */

        $schoolRank = 0;

        if (!empty($user->school)) {

            $schoolRank = DB::table($table)
                ->join(
                    'users',
                    "{$table}.user_id",
                    '=',
                    'users.id'
                )
                ->where(
                    'users.school',
                    $user->school
                )
                ->where(
                    "{$table}.xp",
                    '>',
                    $xp
                )
                ->count() + 1;
        }

        return [
            'national_rank' => (int) $nationalRank,
            'school_rank' => (int) $schoolRank,
        ];
    }


    /**
     * Get subject-specific ranking.
     *
     * Uses completed quiz XP/score for the selected subject.
     */
    private function getSubjectRank(
        int $subjectId,
        int $userId,
        int $userXp
    ): int {

        $studentScores = Quiz::where(
            'subject_id',
            $subjectId
        )
            ->where('is_completed', true)
            ->select('user_id')
            ->selectRaw('SUM(score) as subject_xp')
            ->groupBy('user_id');

        $rank = DB::query()
            ->fromSub(
                $studentScores,
                'subject_scores'
            )
            ->where('subject_xp', '>', $userXp)
            ->count() + 1;

        return (int) $rank;
    }


    /**
     * Get user achievements.
     *
     * Return achievements only if the relationship exists.
     */
    private function getAchievements($user): array
    {
        if (!method_exists($user, 'achievements')) {
            return [];
        }

        try {

            $achievements = $user->achievements()->get();

            return $achievements
                ->map(function ($achievement) {

                    return [

                        'id' => $achievement->id,

                        'name' => $achievement->name ?? 'Achievement',

                        'description' => $achievement->description ?? '',

                        'icon' => $achievement->icon ?? null,

                        'color' => $achievement->color ?? null,
                    ];

                })
                ->values()
                ->toArray();

        } catch (\Throwable $e) {

            return [];
        }
    }
}
