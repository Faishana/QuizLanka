<?php

namespace App\Http\Controllers\Api;

use App\Models\Material;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\OcrExtractionService;
use App\Services\AiQuestionGenerationService;
use Illuminate\Http\JsonResponse;
use App\Models\Question;
use App\Jobs\GenerateQuestionsFromChunksJob;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MaterialController extends Controller
{
    /**
     * Upload Material
     */
    public function upload(Request $request)
    {
        $request->validate([
            'grade_id' => 'required|exists:grades,id',
            'subject_id' => 'required|exists:subjects,id',
            'title' => 'required|string|max:255',
            'material_type' => 'required|in:lesson,past_paper',
            'file' => 'required|file|mimes:pdf,docx,txt|max:102400',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Store File
        |--------------------------------------------------------------------------
        */

        $file = $request->file('file');
        $path = $file->store('materials', 's3');

        /*
        |--------------------------------------------------------------------------
        | File Extension
        |--------------------------------------------------------------------------
        */

        $extension = strtolower($file->getClientOriginalExtension());

        /*
        |--------------------------------------------------------------------------
        | TXT Extraction (Immediate)
        |--------------------------------------------------------------------------
        */

        $extractedText = null;
        $processingStatus = 'pending';

        if ($extension === 'txt') {
            try {
                $fullPath = storage_path('app/public/' . $path);
                $extractedText = file_get_contents($fullPath);
                $processingStatus = 'completed';
            } catch (\Exception $e) {
                Log::error('TXT Extraction Failed', [
                    'error' => $e->getMessage(),
                    'file' => $path,
                ]);
                $processingStatus = 'failed';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save Material
        |--------------------------------------------------------------------------
        */

        $material = Material::create([
            'grade_id' => $request->grade_id,
            'subject_id' => $request->subject_id,
            'uploaded_by' => auth()->id(), // 👈 Already exists - GOOD!
            'title' => $request->title,
            'material_type' => $request->material_type,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $extension,
            'file_size' => $file->getSize(),
            'extracted_text' => $extractedText,
            'processing_status' => $processingStatus,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Dispatch Processing Job
        |--------------------------------------------------------------------------
        */

        if ($extension === 'pdf') {

            try {

                if ($material->material_type === 'past_paper') {

                    Log::info('Dispatching Past Paper Job', ['material_id' => $material->id]);
                    \App\Jobs\GeneratePastPaperQuestionsJob::dispatch($material);

                } else {

                    Log::info('Dispatching Lesson OCR Job', ['material_id' => $material->id]);
                    \App\Jobs\ProcessMaterialOCR::dispatch($material);

                }

            } catch (\Throwable $e) {

                Log::error('JOB DISPATCH FAILED', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Job dispatch failed: '.$e->getMessage(),
                ], 500);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' => $extension === 'pdf'
                ? 'Material uploaded. OCR processing started.'
                : 'Material uploaded successfully',
            'data' => [
                'id' => $material->id,
                'title' => $material->title,
                'file_name' => $material->file_name,
                'processing_status' => $material->processing_status,
            ]
        ]);
    }

    /**
     * Materials List (Only Admin's Own Materials)
     */
    public function index(Request $request) // 👈 Added Request
    {
        $materials = Material::withCount('questions')
            ->where('uploaded_by', $request->user()->id) // 👈 Filter
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $materials,
        ]);
    }

    /**
     * Material Details (Only Admin's Own Material)
     */
    public function show(Request $request, $id) // 👈 Added Request
    {
        $material = Material::where('uploaded_by', $request->user()->id) // 👈 Filter
            ->with([
                'grade',
                'subject',
                'uploader'
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $material->id,
                'title' => $material->title,
                'file_name' => $material->file_name,
                'file_type' => $material->file_type,
                'file_size' => $material->file_size,
                'processing_status' => $material->processing_status,
                'extracted_text_preview' => mb_substr(
                    $material->extracted_text,
                    0,
                    3000
                ),
                'grade' => $material->grade?->name,
                'subject' => $material->subject?->name,
                'uploaded_by' => $material->uploader?->name,
                'created_at' => $material->created_at,
            ]
        ]);
    }

    /**
     * Generate Questions (Only Admin's Own Material)
     */
    public function generateQuestions(Request $request, int $id): JsonResponse // 👈 Added Request
    {
        try {
            $material = Material::where('uploaded_by', auth()->id()) // 👈 Filter
                ->findOrFail($id);

            if (empty($material->extracted_text)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Material text has not been extracted yet.'
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Remove Existing Questions
            |--------------------------------------------------------------------------
            */

            Question::where('material_id', $material->id)->delete();

            /*
            |--------------------------------------------------------------------------
            | Generate Questions
            |--------------------------------------------------------------------------
            */

            $aiService = new AiQuestionGenerationService();
            $result = $aiService->generate($material);

            /*
            |--------------------------------------------------------------------------
            | Count Generated Questions
            |--------------------------------------------------------------------------
            */

            $totalQuestions = Question::where('material_id', $material->id)->count();

            $fileDeleted = false;

            /*
            |--------------------------------------------------------------------------
            | Mark Generated + Delete Source PDF
            |--------------------------------------------------------------------------
            */

            if ($result && $totalQuestions > 0) {
                $material->update([
                    'questions_generated_at' => now(),
                    'processing_status' => 'completed'
                ]);

                Log::info('Delete Check', [
                    'material_id' => $material->id,
                    'path' => $material->file_path,
                    'exists' => Storage::disk('public')->exists($material->file_path)
                ]);

                if (
                    !empty($material->file_path) &&
                    Storage::disk('public')->exists($material->file_path)
                ) {
                    Storage::disk('public')->delete($material->file_path);

                    $material->update([
                        'file_path' => null,
                        'file_name' => null,
                        'source_file_deleted' => true
                    ]);

                    $fileDeleted = true;

                    Log::info('Source PDF Deleted', [
                        'material_id' => $material->id
                    ]);
                }
            }

            return response()->json([
                'success' => $result,
                'material_id' => $material->id,
                'questions_generated' => $totalQuestions,
                'source_file_deleted' => $fileDeleted,
                'message' => $result
                    ? 'Questions generated successfully.'
                    : 'Question generation failed.'
            ]);

        } catch (\Exception $e) {
            Log::error('Question Generation Failed', [
                'material_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Material (Only Admin's Own Material)
     */
    public function update(Request $request, $id)
    {
        try {
            $material = Material::where('uploaded_by', $request->user()->id) // 👈 Filter
                ->findOrFail($id);

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'grade_id' => 'sometimes|required|exists:grades,id',
                'subject_id' => 'sometimes|required|exists:subjects,id',
            ]);

            $material->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Material updated successfully',
                'data' => $material
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Material not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update material: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update material'
            ], 500);
        }
    }

    /**
     * Delete Material (Only Admin's Own Material)
     */
    public function destroy(Request $request, $id) // 👈 Added Request
    {
        try {
            $material = Material::where('uploaded_by', $request->user()->id) // 👈 Filter
                ->findOrFail($id);

            Question::where('material_id', $material->id)->delete();

            if (
                $material->file_path &&
                Storage::disk('public')->exists($material->file_path)
            ) {
                Storage::disk('public')->delete($material->file_path);
            }

            $material->delete();

            return response()->json([
                'success' => true,
                'message' => 'Material deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
