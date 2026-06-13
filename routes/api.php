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
use App\Http\Controllers\Api\QuestionController;

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

    // Authentication

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Grades

    Route::get('/grades', [GradeController::class, 'index']);
    Route::post('/grades', [GradeController::class, 'store']);
    Route::get('/grades/{id}', [GradeController::class, 'show']);
    Route::put('/grades/{id}', [GradeController::class, 'update']);
    Route::delete('/grades/{id}', [GradeController::class, 'destroy']);

    // Subjects

    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/subjects/grade/{gradeId}', [SubjectController::class, 'byGrade']);
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);


    // Lessons

    Route::get('/lessons', [LessonController::class, 'all']);
    Route::get('/lessons/subject/{subjectId}', [LessonController::class, 'index']);
    Route::post('/lessons', [LessonController::class, 'store']);
    Route::put('/lessons/{id}', [LessonController::class, 'update']);
    Route::delete('/lessons/{id}', [LessonController::class, 'destroy']);
    Route::get('/lessons/subject/{subjectId}', [LessonController::class, 'bySubject']);

    // Materials

    Route::get('/materials', [MaterialController::class, 'index']);
    Route::get('/materials/{id}', [MaterialController::class, 'show']);
    Route::post('/materials/upload', [MaterialController::class, 'upload']);
    Route::post('/materials/{id}/generate-questions', [MaterialController::class, 'generateQuestions']);
    Route::put('/materials/{id}', [MaterialController::class, 'update']);
    Route::delete('/materials/{id}', [MaterialController::class, 'destroy']);

   // Get questions for material

    Route::get('/materials/{material}/questions', [MaterialQuestionController::class, 'index']);
    Route::get('/materials/{id}/questions', [QuestionController::class, 'byMaterial']);

    // Create question for material

    Route::post('/quiz/start', [QuizController::class, 'start']);
    Route::post('/quiz/submit', [QuizController::class, 'submit']);
    Route::get('/quiz/{quiz}/result', [QuizController::class, 'result']);
    Route::get('/quiz/history', [QuizController::class, 'history']);
    Route::get('/quiz/{quiz}/review', [QuizController::class, 'review']);

    // Dashboard

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/admin/dashboard', [DashboardController::class, 'adminDashboard']);

    // Questions

    Route::get('/questions', [QuestionController::class, 'index']);
    Route::get('/questions/{question}', [QuestionController::class, 'show']);
    Route::put('/questions/{question}', [QuestionController::class, 'update']);
    Route::delete('/questions/{question}', [QuestionController::class, 'destroy']);

});


