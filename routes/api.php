<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentAuthController;

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\StudentDashboardController;

use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MaterialQuestionController;
use App\Http\Controllers\Api\QuestionController;

use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProfileSubjectController;

use App\Http\Controllers\Api\StudentSubjectController;
use App\Http\Controllers\Api\StudentMaterialController;

use App\Http\Controllers\Api\QuizController;

use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\StudentProfileController;

use App\Http\Controllers\Api\PastPaperController;
use App\Http\Controllers\Api\BattlePresenceController;
use App\Http\Controllers\Api\BattleController;

use App\Http\Controllers\Api\StudentPastPaperController;

/*
|--------------------------------------------------------------------------
| Test
|--------------------------------------------------------------------------
*/

Route::get('/test-r2', function () {

    Storage::disk('s3')->put(
        'test.txt',
        'QuizLanka AI R2 Test'
    );

    return 'Upload Success';
});

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/public/grades', [GradeController::class, 'publicGrades']);
Route::get('/public/subjects', [SubjectController::class, 'publicSubjects']);
Route::get('/public/profiles/{token}', [StudentProfileController::class, 'publicProfile']);

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/admin-register', [AuthController::class, 'adminRegister']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/google-login', [AuthController::class, 'googleLogin']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

    });

});

/*
|--------------------------------------------------------------------------
| Student Authentication
|--------------------------------------------------------------------------
*/

Route::prefix('student')->group(function () {

    // Registration & Authentication
    Route::post('/register', [StudentAuthController::class, 'register']);
    Route::post('/login', [StudentAuthController::class, 'login']);
    Route::post('/google-login', [StudentAuthController::class, 'googleLogin']);

    // Password Reset
    Route::post('/forgot-password', [StudentAuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [StudentAuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [StudentAuthController::class, 'resetPassword']);

    // Authenticated Routes
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [StudentAuthController::class, 'logout']);
        Route::get('/me', [StudentAuthController::class, 'me']);

        // Complete Student / Government Exam Candidate Onboarding
        Route::post(
            '/complete-onboarding',
            [StudentAuthController::class, 'completeOnboarding']
        );

    });

});

/*
|--------------------------------------------------------------------------
| Admin APIs
|--------------------------------------------------------------------------
*/

Route::prefix('admin')
    ->middleware('auth:sanctum')
    ->group(function () {

        /*
        | Dashboard
        */

        Route::get('/dashboard', [DashboardController::class, 'adminDashboard']);

        /*
        | Grades
        */

        Route::get('/grades', [GradeController::class, 'index']);
        Route::post('/grades', [GradeController::class, 'store']);
        Route::get('/grades/{id}', [GradeController::class, 'show']);
        Route::put('/grades/{id}', [GradeController::class, 'update']);
        Route::delete('/grades/{id}', [GradeController::class, 'destroy']);

        /*
        | Subjects
        */

        Route::get('/subjects', [SubjectController::class, 'index']);
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::get('/subjects/grade/{gradeId}', [SubjectController::class, 'byGrade']);
        Route::put('/subjects/{id}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);
        Route::get('/grades/{grade}/subjects', [SubjectController::class, 'byGrade']);

        /*
        | Materials
        */

        Route::get('/materials', [MaterialController::class, 'index']);
        Route::get('/materials/{id}', [MaterialController::class, 'show']);
        Route::post('/materials/upload', [MaterialController::class, 'upload']);
        Route::put('/materials/{id}', [MaterialController::class, 'update']);
        Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);

        /*
        | AI Question Generation
        */

        Route::post('/materials/{id}/generate-questions', [MaterialController::class, 'generateQuestions']);

        /*
        | Material Questions
        */

        Route::get('/materials/{material}/questions', [MaterialQuestionController::class, 'index']);

        /*
        | Question Bank
        */

        Route::get('/questions', [QuestionController::class, 'index']);
        Route::get('/questions/{question}', [QuestionController::class, 'show']);
        Route::put('/questions/{question}', [QuestionController::class, 'update']);
        Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);

        /*
        | Quiz Reports
        */

        Route::get('/quizzes', [QuizController::class, 'adminHistory']);
        Route::get('/quizzes/stats', [QuizController::class, 'adminStats']);
        Route::get('/quizzes/{id}', [QuizController::class, 'adminShow']);

        /*
        | Profile
        */

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::put('/profile/password', [ProfileController::class, 'changePassword']);

        /*
        | Past Papers (Admin)
        */

        Route::get('/past-papers', [PastPaperController::class, 'index']);
        Route::post('/past-papers', [PastPaperController::class, 'store']);

        Route::post('/past-papers/upload-image', [PastPaperController::class, 'uploadImage']);

        Route::get('/past-papers/{id}', [PastPaperController::class, 'show']);
        Route::put('/past-papers/{id}', [PastPaperController::class, 'update']);
        Route::delete('/past-papers/{id}', [PastPaperController::class, 'destroy']);

    });

/*
|--------------------------------------------------------------------------
| Student APIs
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    | Dashboard
    */

    Route::get('/dashboard', [StudentDashboardController::class, 'index']);

    /*
    | Profile
    */

    Route::get('/profile', [StudentProfileController::class, 'show']);
    Route::put('/profile', [StudentProfileController::class, 'update']);
    Route::post('/profile/photo', [StudentProfileController::class, 'uploadPhoto']);
    Route::post('/profile/share', [StudentProfileController::class, 'enableProfileSharing']);
    Route::delete('/profile/share', [StudentProfileController::class, 'disableProfileSharing']);

    /*
    | Profile Subjects
    */

    Route::get('/profile/subjects', [ProfileSubjectController::class, 'index']);
    Route::post('/profile/subjects', [ProfileSubjectController::class, 'store']);

    /*
    | Subjects
    */

    Route::get('/subjects', [StudentSubjectController::class, 'index']);
    Route::get('/subjects/{id}', [StudentSubjectController::class, 'show']);

    /*
    | Materials
    */

    Route::get('/materials', [StudentMaterialController::class, 'index']);
    Route::get('/materials/{id}', [StudentMaterialController::class, 'show']);
    Route::get('/subjects/{subject}/materials', [StudentMaterialController::class, 'bySubject']);

    /*
    |--------------------------------------------------------------------------
    | Student Past Papers
    |--------------------------------------------------------------------------
    */

    Route::prefix('past-papers')->group(function () {

        // Get available subjects
        Route::get('/subjects', [StudentPastPaperController::class, 'subjects']);

        // Get all accessible past papers with filters
        Route::get('/', [StudentPastPaperController::class, 'index']);

        // Get a single past paper with questions
        Route::get('/{id}', [StudentPastPaperController::class, 'show']);

        // Start or resume a past paper quiz
        Route::post('/{id}/start', [StudentPastPaperController::class, 'start']);

        // Submit past paper answers
        Route::post('/{id}/submit', [StudentPastPaperController::class, 'submit']);

        // Save individual past paper answer
        Route::post('/past-paper-attempts/{quizId}/answers', [StudentPastPaperController::class, 'saveAnswer']);

    });

    // Save an individual answer during a past paper attempt
    Route::post(
        '/past-paper-attempts/{quizId}/answers',
        [StudentPastPaperController::class, 'saveAnswer']
    );

    /*
    | Quiz
    */

    Route::post('/quiz/start', [QuizController::class, 'start']);
    Route::post('/quiz/submit', [QuizController::class, 'submit']);
    Route::get('/quiz/history', [QuizController::class, 'history']);
    Route::get('/quiz/{quiz}/result', [QuizController::class, 'result']);
    Route::get('/quiz/{quiz}/review', [QuizController::class, 'review']);

    /*
    | Leaderboard
    */

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    /*
    | Battle Presence
    */
    Route::post('/battle/heartbeat', [BattlePresenceController::class, 'heartbeat']);
    Route::get('/battle/online-students', [BattlePresenceController::class, 'onlineStudents']);

    Route::prefix('battle')->group(function () {
        Route::post('/challenge', [BattleController::class, 'challenge']);
        Route::get('/invitations', [BattleController::class, 'invitations']);
        Route::get('/my-challenges', [BattleController::class, 'myChallenges']);

        Route::post('/{battle}/accept', [BattleController::class, 'accept']);
        Route::post('/{battle}/reject', [BattleController::class, 'reject']);
        Route::post('/{battle}/start', [BattleController::class, 'start']);
        Route::get('/{battle}/questions', [BattleController::class, 'questions']);
        Route::post('/{battle}/answer', [BattleController::class, 'submitAnswer']);
    });

});


