<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Battle;
use App\Models\Question;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BattleController extends Controller
{
    private const INVITATION_EXPIRY_MINUTES = 10;
    private const ONLINE_TIMEOUT_SECONDS = 60;

    private const MEDIUM_LANGUAGE_MAP = [
        'Sinhala' => 'si',
        'English' => 'en',
        'Tamil' => 'ta',
    ];

    /**
     * Send a challenge.
     * POST /api/battle/challenge
     */
    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opponent_id' => ['required', 'integer', 'exists:users,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'question_count' => ['sometimes', 'integer', 'min:5', 'max:30'],
            'duration_seconds' => ['sometimes', 'integer', 'min:60', 'max:1800'],
        ]);

        $challenger = $request->user();

        if (!$challenger->grade_id) {
            throw ValidationException::withMessages([
                'grade_id' => 'Your account does not have a grade assigned.',
            ]);
        }

        $opponent = User::query()->findOrFail(
            (int) $validated['opponent_id']
        );

        $subjectId = (int) $validated['subject_id'];

        if ((int) $challenger->id === (int) $opponent->id) {
            throw ValidationException::withMessages([
                'opponent_id' => 'You cannot challenge yourself.',
            ]);
        }

        if (
            !$opponent->grade_id ||
            (int) $opponent->grade_id !== (int) $challenger->grade_id
        ) {
            throw ValidationException::withMessages([
                'opponent_id' => 'You can only challenge students in your grade.',
            ]);
        }

        if (
            empty($challenger->preferred_medium) ||
            empty($opponent->preferred_medium)
        ) {
            throw ValidationException::withMessages([
                'opponent_id' => 'Both students must have a preferred medium set.',
            ]);
        }

        if (
            $challenger->preferred_medium !==
            $opponent->preferred_medium
        ) {
            throw ValidationException::withMessages([
                'opponent_id' => 'You can only challenge students who use the same medium.',
            ]);
        }

        $quizLanguage = self::MEDIUM_LANGUAGE_MAP[
            $challenger->preferred_medium
        ] ?? null;

        if (!$quizLanguage) {
            throw ValidationException::withMessages([
                'preferred_medium' => 'Your preferred medium is not supported for battles.',
            ]);
        }

        if (!$this->isOnline($challenger)) {
            throw ValidationException::withMessages([
                'challenger_id' => 'Your online status has expired. Send a heartbeat and try again.',
            ]);
        }

        if (!$this->isOnline($opponent)) {
            throw ValidationException::withMessages([
                'opponent_id' => 'This student is currently offline.',
            ]);
        }

        $subjectAllowed = DB::table('grade_subjects')
            ->where('grade_id', $challenger->grade_id)
            ->where('subject_id', $subjectId)
            ->exists();

        if (!$subjectAllowed) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is not available for your grade.',
            ]);
        }

        $questionCount = (int) ($validated['question_count'] ?? 10);
        $duration = (int) ($validated['duration_seconds'] ?? 300);

        // Count published questions matching the students' medium.
        $availableQuestions = Question::query()
            ->where('grade_id', $challenger->grade_id)
            ->where('subject_id', $subjectId)
            ->where('status', 'published')
            ->where('quiz_language', $quizLanguage)
            ->count();

        if ($availableQuestions < $questionCount) {
            throw ValidationException::withMessages([
                'question_count' =>
                    "Only {$availableQuestions} published questions are available for this grade, subject and medium.",
            ]);
        }

        $battle = DB::transaction(function () use (
            $challenger,
            $opponent,
            $subjectId,
            $questionCount,
            $duration
        ) {
            $this->expirePendingInvitations();

            $existingInvitation = Battle::query()
                ->where('status', 'pending')
                ->where(function ($query) use ($challenger, $opponent) {
                    $query->where(function ($q) use ($challenger, $opponent) {
                        $q->where('challenger_id', $challenger->id)
                            ->where('opponent_id', $opponent->id);
                    })->orWhere(function ($q) use ($challenger, $opponent) {
                        $q->where('challenger_id', $opponent->id)
                            ->where('opponent_id', $challenger->id);
                    });
                })
                ->exists();

            if ($existingInvitation) {
                throw ValidationException::withMessages([
                    'opponent_id' =>
                        'There is already a pending invitation between these students.',
                ]);
            }

            return Battle::create([
                'challenger_id' => $challenger->id,
                'opponent_id' => $opponent->id,
                'grade_id' => $challenger->grade_id,
                'subject_id' => $subjectId,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(
                    self::INVITATION_EXPIRY_MINUTES
                ),
                'question_count' => $questionCount,
                'duration_seconds' => $duration,
                'challenger_score' => 0,
                'opponent_score' => 0,
            ]);
        });

        $battle->load([
            'challenger:id,name,profile_image',
            'opponent:id,name,profile_image',
            'subject:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Battle invitation sent successfully. It expires in 10 minutes.',
            'data' => $battle,
        ], 201);
    }

    /**
     * Received invitations.
     * GET /api/battle/invitations
     */
    public function invitations(Request $request): JsonResponse
    {
        $this->expirePendingInvitations();

        $invitations = Battle::query()
            ->where('opponent_id', $request->user()->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with([
                'challenger:id,name,profile_image,grade_id',
                'subject:id,name',
            ])
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $invitations,
        ]);
    }

    /**
     * Challenges sent by the authenticated student.
     * GET /api/battle/my-challenges
     */
    public function myChallenges(Request $request): JsonResponse
    {
        $this->expirePendingInvitations();

        $battles = Battle::query()
            ->where('challenger_id', $request->user()->id)
            ->with([
                'opponent:id,name,profile_image,grade_id',
                'subject:id,name',
            ])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $battles,
        ]);
    }

    /**
     * Accept an invitation.
     * POST /api/battle/{battle}/accept
     */
    public function accept(Request $request, int $battle): JsonResponse
    {
        $user = $request->user();

        $this->expirePendingInvitations();

        $battleRecord = DB::transaction(function () use ($user, $battle) {
            $record = Battle::query()
                ->lockForUpdate()
                ->findOrFail($battle);

            if ((int) $record->opponent_id !== (int) $user->id) {
                abort(403, 'You are not allowed to accept this invitation.');
            }

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'battle' => 'This invitation is no longer pending or has expired.',
                ]);
            }

            if (
                !$record->expires_at ||
                Carbon::parse($record->expires_at)->lessThanOrEqualTo(now())
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'This invitation has expired. Ask the student to send a new challenge.',
                ]);
            }

            if (
                !$user->grade_id ||
                (int) $record->grade_id !== (int) $user->grade_id
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'Your grade does not match this battle.',
                ]);
            }

            if (!$this->isOnline($user)) {
                throw ValidationException::withMessages([
                    'battle' => 'Your online status has expired. Send a heartbeat and try again.',
                ]);
            }

            $challenger = User::query()->find($record->challenger_id);

            if (!$challenger || !$this->isOnline($challenger)) {
                throw ValidationException::withMessages([
                    'battle' => 'The challenger is currently offline.',
                ]);
            }

            if (
                empty($challenger->preferred_medium) ||
                empty($user->preferred_medium) ||
                $challenger->preferred_medium !== $user->preferred_medium
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'Both students must have the same preferred medium.',
                ]);
            }

            return tap($record, function ($record) {
                $record->status = 'accepted';
                $record->accepted_at = now();
                $record->save();
            })->fresh();
        });

        $battleRecord->load([
            'challenger:id,name,profile_image',
            'opponent:id,name,profile_image',
            'subject:id,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Invitation accepted. The battle is ready to start.',
            'data' => $battleRecord,
        ]);
    }

    /**
     * Start an accepted battle and assign questions.
     * POST /api/battle/{battle}/start
     */
    public function start(Request $request, int $battle): JsonResponse
    {
        $user = $request->user();

        $battleRecord = DB::transaction(function () use ($user, $battle) {
            $record = Battle::query()
                ->lockForUpdate()
                ->findOrFail($battle);

            if (
                (int) $record->challenger_id !== (int) $user->id &&
                (int) $record->opponent_id !== (int) $user->id
            ) {
                abort(403, 'You are not a participant in this battle.');
            }

            if ($record->status !== 'accepted') {
                throw ValidationException::withMessages([
                    'battle' => 'This battle must be accepted before it can start.',
                ]);
            }

            $challenger = User::query()->find($record->challenger_id);
            $opponent = User::query()->find($record->opponent_id);

            if (
                !$challenger ||
                !$opponent ||
                !$this->isOnline($challenger) ||
                !$this->isOnline($opponent)
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'Both students must be online to start the battle. Send heartbeats and try again.',
                ]);
            }

            if (
                empty($challenger->preferred_medium) ||
                empty($opponent->preferred_medium) ||
                $challenger->preferred_medium !== $opponent->preferred_medium
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'Both students must have the same preferred medium.',
                ]);
            }

            if (
                !$challenger->grade_id ||
                (int) $challenger->grade_id !== (int) $opponent->grade_id ||
                (int) $record->grade_id !== (int) $challenger->grade_id
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'Both students must belong to the battle grade.',
                ]);
            }

            $quizLanguage = self::MEDIUM_LANGUAGE_MAP[
                $challenger->preferred_medium
            ] ?? null;

            if (!$quizLanguage) {
                throw ValidationException::withMessages([
                    'battle' => 'The selected medium is not supported.',
                ]);
            }

            if ($record->questions()->exists()) {
                throw ValidationException::withMessages([
                    'battle' => 'Questions have already been assigned to this battle.',
                ]);
            }

            $questionIds = Question::query()
                ->where('grade_id', $record->grade_id)
                ->where('subject_id', $record->subject_id)
                ->where('status', 'published')
                ->where('quiz_language', $quizLanguage)
                ->inRandomOrder()
                ->limit($record->question_count)
                ->pluck('id');

            if ($questionIds->count() < $record->question_count) {
                throw ValidationException::withMessages([
                    'questions' => 'There are not enough published questions in the students’ medium to start this battle.',
                ]);
            }

            foreach ($questionIds->values() as $index => $questionId) {
                $record->questions()->create([
                    'question_id' => $questionId,
                    'question_order' => $index + 1,
                ]);
            }

            $startTime = now();

            $record->status = 'in_progress';
            $record->started_at = $startTime;
            $record->ends_at = $startTime->copy()->addSeconds(
                $record->duration_seconds
            );
            $record->save();

            return $record->fresh();
        });

        $battleRecord->load('subject:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Battle started successfully.',
            'data' => $battleRecord,
        ]);
    }

    /**
     * Reject an invitation.
     * POST /api/battle/{battle}/reject
     */
    public function reject(Request $request, int $battle): JsonResponse
    {
        $user = $request->user();

        $this->expirePendingInvitations();

        $battleRecord = DB::transaction(function () use ($user, $battle) {
            $record = Battle::query()
                ->lockForUpdate()
                ->findOrFail($battle);

            if ((int) $record->opponent_id !== (int) $user->id) {
                abort(403, 'You are not allowed to reject this invitation.');
            }

            if ($record->status !== 'pending') {
                throw ValidationException::withMessages([
                    'battle' => 'This invitation is no longer pending or has expired.',
                ]);
            }

            if (
                !$record->expires_at ||
                Carbon::parse($record->expires_at)->lessThanOrEqualTo(now())
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'This invitation has expired.',
                ]);
            }

            $record->status = 'rejected';
            $record->save();

            return $record->fresh();
        });

        return response()->json([
            'success' => true,
            'message' => 'Battle invitation rejected.',
            'data' => $battleRecord,
        ]);
    }

    /**
     * Mark expired pending invitations as expired.
     */
    private function expirePendingInvitations(): void
    {
        $now = now();

        $legacyExpiry = $now->copy()->subMinutes(
            self::INVITATION_EXPIRY_MINUTES
        );

        Battle::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($now, $legacyExpiry) {
                $query->where(function ($q) use ($now) {
                    $q->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $now);
                })->orWhere(function ($q) use ($legacyExpiry) {
                    $q->whereNull('expires_at')
                        ->where('created_at', '<=', $legacyExpiry);
                });
            })
            ->update(['status' => 'expired']);
    }

    /**
     * Check online status using last_seen_at.
     */
    private function isOnline(User $user): bool
    {
        if (!$user->last_seen_at) {
            return false;
        }

        return Carbon::parse($user->last_seen_at)
            ->greaterThanOrEqualTo(
                now()->subSeconds(self::ONLINE_TIMEOUT_SECONDS)
            );
    }

    /**
     * Fetch questions assigned to an active battle.
     * GET /api/battle/{battle}/questions
     */
    public function questions(Request $request, int $battle): JsonResponse
    {
        $user = $request->user();

        $battleRecord = Battle::query()->findOrFail($battle);

        if (
            (int) $battleRecord->challenger_id !== (int) $user->id &&
            (int) $battleRecord->opponent_id !== (int) $user->id
        ) {
            abort(403, 'You are not a participant in this battle.');
        }

        if ($battleRecord->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'This battle is not in progress.',
            ], 422);
        }

        if (
            !$battleRecord->ends_at ||
            now()->greaterThanOrEqualTo($battleRecord->ends_at)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The battle time has ended.',
            ], 422);
        }

        $questions = $battleRecord->questions()
            ->with([
                'question:id,question_text,question_type,difficulty',
                'question.options:id,question_id,option_key,option_text',
            ])
            ->orderBy('question_order')
            ->get()
            ->map(function ($battleQuestion) {
                $question = $battleQuestion->question;

                if (!$question) {
                    return null;
                }

                return [
                    'question_order' => $battleQuestion->question_order,
                    'question_id' => $question->id,
                    'question_text' => $question->question_text,
                    'question_type' => $question->question_type,
                    'difficulty' => $question->difficulty,
                    'options' => $question->options->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_key' => $option->option_key,
                            'option_text' => $option->option_text,
                        ];
                    })->values(),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'battle_id' => $battleRecord->id,
                'status' => $battleRecord->status,
                'question_count' => $questions->count(),
                'duration_seconds' => $battleRecord->duration_seconds,
                'started_at' => $battleRecord->started_at,
                'ends_at' => $battleRecord->ends_at,
                'questions' => $questions,
            ],
        ]);
    }


    /**
     * Submit an answer for an active battle.
     * POST /api/battle/{battle}/answer
     */
    public function submitAnswer(Request $request, int $battle): JsonResponse
    {
        $validated = $request->validate([
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'selected_option_id' => ['required', 'integer', 'exists:question_options,id'],
        ]);

        $user = $request->user();

        $result = DB::transaction(function () use ($battle, $user, $validated) {
            $battleRecord = Battle::query()
                ->lockForUpdate()
                ->findOrFail($battle);

            if (
                (int) $battleRecord->challenger_id !== (int) $user->id &&
                (int) $battleRecord->opponent_id !== (int) $user->id
            ) {
                abort(403, 'You are not a participant in this battle.');
            }

            if ($battleRecord->status !== 'in_progress') {
                throw ValidationException::withMessages([
                    'battle' => 'This battle is not in progress.',
                ]);
            }

            if (
                !$battleRecord->ends_at ||
                now()->greaterThanOrEqualTo($battleRecord->ends_at)
            ) {
                throw ValidationException::withMessages([
                    'battle' => 'The battle time has ended.',
                ]);
            }

            $questionId = (int) $validated['question_id'];
            $optionId = (int) $validated['selected_option_id'];

            $battleQuestion = $battleRecord->questions()
                ->where('question_id', $questionId)
                ->exists();

            if (!$battleQuestion) {
                throw ValidationException::withMessages([
                    'question_id' => 'This question is not assigned to this battle.',
                ]);
            }

            $question = Question::query()
                ->with('options:id,question_id,is_correct')
                ->findOrFail($questionId);

            $option = $question->options
                ->firstWhere('id', $optionId);

            if (!$option) {
                throw ValidationException::withMessages([
                    'selected_option_id' => 'The selected option does not belong to this question.',
                ]);
            }

            $alreadyAnswered = $battleRecord->answers()
                ->where('user_id', $user->id)
                ->where('question_id', $questionId)
                ->exists();

            if ($alreadyAnswered) {
                throw ValidationException::withMessages([
                    'question_id' => 'You have already answered this question.',
                ]);
            }

            $isCorrect = (bool) $option->is_correct;

            $battleRecord->answers()->create([
                'user_id' => $user->id,
                'question_id' => $questionId,
                'selected_option_id' => $optionId,
                'is_correct' => $isCorrect,
                'answered_at' => now(),
            ]);

            $scoreColumn = (int) $battleRecord->challenger_id === (int) $user->id
                ? 'challenger_score'
                : 'opponent_score';

            if ($isCorrect) {
                $battleRecord->{$scoreColumn} =
                    (int) $battleRecord->{$scoreColumn} + 1;

                $battleRecord->save();
            }

            return [
                'battle_id' => $battleRecord->id,
                'question_id' => $questionId,
                'selected_option_id' => $optionId,
                'is_correct' => $isCorrect,
                'score' => (int) $battleRecord->{$scoreColumn},
                'challenger_score' => (int) $battleRecord->challenger_score,
                'opponent_score' => (int) $battleRecord->opponent_score,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Answer submitted successfully.',
            'data' => $result,
        ]);
    }

}
