<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MaterialQuestionController;
 use App\Http\Controllers\Api\QuizController;
 use App\Http\Controllers\Api\DashboardController;


// API Routes
Route::prefix('auth')->group(function () {

    Route::post('/register', [AuthController::class, 'register']);

    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {

        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/me', [AuthController::class, 'me']);
    });
});

// Protected routes

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    */

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/auth/me', [AuthController::class, 'me']);

    /*
    |--------------------------------------------------------------------------
    | Grades
    |--------------------------------------------------------------------------
    */

    Route::get('/grades', [GradeController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | Subjects
    |--------------------------------------------------------------------------
    */

    // Get all subjects
    Route::get('/subjects', [SubjectController::class, 'index']);

    // Get subjects by grade
    Route::get('/subjects/grade/{gradeId}', [SubjectController::class, 'byGrade']);

    // Create subject
    Route::post('/subjects', [SubjectController::class, 'store']);

    // Update subject
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);

    // Delete subject
    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Lessons
    |--------------------------------------------------------------------------
    */

    // Get all lessons
    Route::get('/lessons', [LessonController::class, 'all']);
    // Get lessons by subject
    Route::get('/lessons/subject/{subjectId}', [LessonController::class, 'index']);
    // Create lesson
    Route::post('/lessons', [LessonController::class, 'store']);
    // Update lesson
    Route::put('/lessons/{id}', [LessonController::class, 'update']);
    // Delete lesson
    Route::delete('/lessons/{id}', [LessonController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | Materials
    |--------------------------------------------------------------------------
    */

    // Get all materials
    Route::get('/materials', [MaterialController::class, 'index']);
    // Get material by ID
    Route::get('/materials/{id}', [MaterialController::class, 'show']);
    // Upload material
    Route::post('/materials/upload', [MaterialController::class, 'upload']);
    // Generate questions from material
    Route::post('/materials/{id}/generate-questions', [MaterialController::class, 'generateQuestions']);
    // Update material
    Route::put('/materials/{id}', [MaterialController::class, 'update']);
    // Delete material
    Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Material Questions
    |--------------------------------------------------------------------------
    */

    Route::get('/materials/{material}/questions', [MaterialQuestionController::class, 'index']);
    // Get questions by material
    Route::get('/materials/{id}/questions', [QuestionController::class, 'byMaterial']);

   /*
    |--------------------------------------------------------------------------
    | Quiz
    |--------------------------------------------------------------------------
   */

    Route::post('/quiz/start', [QuizController::class, 'start']);
    Route::post('/quiz/submit', [QuizController::class, 'submit']);
    Route::get('/quiz/{quiz}/result', [QuizController::class, 'result']);
    Route::get('/quiz/history', [QuizController::class, 'history']);
    Route::get('/quiz/{quiz}/review', [QuizController::class, 'review']);

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |------------------------------------------------------------------------
    */

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/admin/dashboard', [DashboardController::class, 'adminDashboard']
);
});


