<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StudentSubjectController extends Controller
{
    private const AL_STREAMS = [
        'bio',
        'maths',
        'technology',
        'arts',
        'commerce',
    ];

    /**
     * Supported quiz languages.
     */
    private const SUPPORTED_LANGUAGES = ['si', 'en', 'ta'];

    /**
     * Check whether the user is a Government Exam Candidate.
     */
    private function isGovernmentExamCandidate($user): bool
    {
        return trim($user->user_category ?? '') === 'Government Exam Candidate';
    }

    /**
     * Resolve the grade for normal students.
     *
     * Government candidates do not need a Grade record.
     */
    private function resolveUserGrade($user): ?Grade
    {
        if (!$user || $this->isGovernmentExamCandidate($user)) {
            return null;
        }

        if (!$user->grade_id) {
            return null;
        }

        return Grade::where('id', $user->grade_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get Government Exam subjects directly from subjects table.
     */
    private function getGovernmentSubjectsQuery()
    {
        return Subject::where('grade_category', 'gov')
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Get subjects available for normal students.
     *
     * Non A/L:
     *   Subjects assigned to their grade.
     *
     * A/L:
     *   Subjects assigned to their grade and selected stream.
     */
    private function getUserSubjectsQuery($user, Grade $grade)
    {
        $query = $grade->subjects()
            ->where('subjects.is_active', true);

        if (strtolower($grade->category ?? '') === 'al') {

            $stream = strtolower(trim($user->al_stream ?? ''));

            if (!in_array($stream, self::AL_STREAMS, true)) {
                return null;
            }

            $query->whereJsonContains(
                'grade_subjects.al_streams',
                $stream
            );
        }

        return $query
            ->select('subjects.*')
            ->distinct()
            ->orderBy('subjects.sort_order');
    }

    /**
     * Validate and normalize the requested quiz language.
     */
    private function resolveLanguage(Request $request): ?string
    {
        $language = strtolower(trim((string) $request->query('language', 'si')));

        if (!in_array($language, self::SUPPORTED_LANGUAGES, true)) {
            return null;
        }

        return $language;
    }

    /**
     * Standard 422 response for invalid quiz language.
     */
    private function invalidLanguageResponse(string $key = 'subjects')
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid quiz language.',
            $key => [],
        ], 422);
    }

    /**
     * Format subject response according to selected quiz language.
     */
    private function formatSubject(Subject $subject, string $language): array
    {
        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'slug' => $subject->slug,

            'grade_category' => $subject->grade_category,

            'icon' => $subject->icon
                ? Storage::disk('s3')->url($subject->icon)
                : null,

            'color' => $subject->color,

            'questions_count' => Question::where('subject_id', $subject->id)
                ->where('quiz_language', $language)
                ->where('status', 'published')
                ->count(),

            'description' => $subject->description,
        ];
    }

    /**
     * GET /api/subjects?language=si|en|ta
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'subjects' => [],
                ], 401);
            }

            $language = $this->resolveLanguage($request);

            if ($language === null) {
                return $this->invalidLanguageResponse('subjects');
            }

            /*
             * GOVERNMENT EXAM CANDIDATES
             *
             * No Grade relationship required.
             * Fetch subjects directly using grade_category = gov.
             */
            if ($this->isGovernmentExamCandidate($user)) {

                $subjects = $this->getGovernmentSubjectsQuery()->get();

                return response()->json([
                    'success' => true,
                    'grade_category' => 'gov',
                    'grade' => null,
                    'al_stream' => null,
                    'language' => $language,

                    'subjects' => $subjects->map(function ($subject) use ($language) {
                        return $this->formatSubject($subject, $language);
                    })->values(),

                    'message' => $subjects->isEmpty()
                        ? 'No government exam subjects available yet.'
                        : null,
                ]);
            }

            /*
             * NORMAL STUDENTS
             */
            $grade = $this->resolveUserGrade($user);

            if (!$grade) {
                return response()->json([
                    'success' => true,
                    'grade_category' => null,
                    'language' => $language,
                    'subjects' => [],
                    'message' => 'Please select your grade first.',
                ]);
            }

            $query = $this->getUserSubjectsQuery($user, $grade);

            if (!$query) {
                return response()->json([
                    'success' => false,
                    'language' => $language,
                    'subjects' => [],
                    'message' => 'Please select a valid A/L stream.',
                ], 422);
            }

            $subjects = $query->get();

            return response()->json([
                'success' => true,

                'grade_category' => $grade->category,

                'grade' => [
                    'id' => $grade->id,
                    'name' => $grade->name,
                    'category' => $grade->category,
                ],

                'al_stream' => strtolower(trim($user->al_stream ?? '')),

                'language' => $language,

                'subjects' => $subjects->map(function ($subject) use ($language) {
                    return $this->formatSubject($subject, $language);
                })->values(),
            ]);

        } catch (\Throwable $e) {

            Log::error('Student subjects error', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load subjects.',
                'subjects' => [],
            ], 500);
        }
    }

    /**
     * GET /api/subjects/{id}?language=si|en|ta
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            $language = $this->resolveLanguage($request);

            if ($language === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid quiz language.',
                ], 422);
            }

            /*
             * GOVERNMENT EXAM CANDIDATES
             *
             * Only allow access to active government subjects.
             */
            if ($this->isGovernmentExamCandidate($user)) {

                $subject = $this->getGovernmentSubjectsQuery()
                    ->where('id', $id)
                    ->first();

                if (!$subject) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Government exam subject not found.',
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'grade_category' => 'gov',
                    'grade' => null,
                    'al_stream' => null,
                    'language' => $language,
                    'subject' => $this->formatSubject($subject, $language),
                ]);
            }

            /*
             * NORMAL STUDENTS
             */
            $grade = $this->resolveUserGrade($user);

            if (!$grade) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select your grade first.',
                ], 403);
            }

            $query = $this->getUserSubjectsQuery($user, $grade);

            if (!$query) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please select a valid A/L stream.',
                ], 422);
            }

            // Verify subject belongs to user's grade and stream.
            $subject = $query
                ->where('subjects.id', $id)
                ->first();

            if (!$subject) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subject not found or not available for your grade and stream.',
                ], 404);
            }

            return response()->json([
                'success' => true,

                'grade_category' => $grade->category,

                'grade' => [
                    'id' => $grade->id,
                    'name' => $grade->name,
                    'category' => $grade->category,
                ],

                'al_stream' => strtolower(trim($user->al_stream ?? '')),

                'language' => $language,

                'subject' => $this->formatSubject($subject, $language),
            ]);

        } catch (\Throwable $e) {

            Log::error('Student subject show error', [
                'user_id' => $request->user()?->id,
                'subject_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load subject.',
            ], 500);
        }
    }
}
