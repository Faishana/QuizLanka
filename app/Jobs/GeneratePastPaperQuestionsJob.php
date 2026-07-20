<?php

namespace App\Jobs;

use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\PdfToImageService;
use App\Services\PastPaperValidationService;
use App\Services\QuestionValidationService;
use Illuminate\Support\Facades\Log;

class GeneratePastPaperQuestionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Material $material;

    public $tries = 3;

    public $backoff = 60;

    public function __construct(Material $material)
    {
        $this->material = $material;
    }

    public function handle(): void
    {
        $tempPdf = null;

        try {
            Log::info('Past Paper Processing Started', [
                'material_id' => $this->material->id
            ]);

            /*
            |--------------------------------------------------------------------------
            | Download PDF From R2
            |--------------------------------------------------------------------------
            */

            $tempPdf = storage_path(
                'app/temp/' .
                $this->material->id .
                '_' .
                time() .
                '.pdf'
            );

            if (!file_exists(dirname($tempPdf))) {
                mkdir(dirname($tempPdf), 0777, true);
            }

            $pdfContent = Storage::disk('s3')->get(
                $this->material->file_path
            );

            file_put_contents(
                $tempPdf,
                $pdfContent
            );

            /*
            |--------------------------------------------------------------------------
            | Convert PDF Pages To Images
            |--------------------------------------------------------------------------
            */

            $pageImages = app(
                PdfToImageService::class
            )->convert(
                $this->material,
                $tempPdf
            );

            Log::info('PDF Converted', [
                'material_id' => $this->material->id,
                'pages' => count($pageImages)
            ]);



         /*
        |--------------------------------------------------------------------------
        | Extract Questions Using Vision AI
        |--------------------------------------------------------------------------
        */

        $questions = app(
            \App\Services\PastPaperVisionService::class
        )->extractQuestions($pageImages);

        Log::info('Vision extraction completed', [
            'images' => count($pageImages),
            'questions' => count($questions),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Validate Extracted Questions
        |--------------------------------------------------------------------------
        */

        $validatedQuestions = app(
            PastPaperValidationService::class
        )->validateBatch($questions);

        Log::info('Past Paper Validation Completed', [
            'valid_questions' => count($validatedQuestions),
        ]);

            if (empty($questions)) {
                Log::warning('No Questions Extracted', [
                    'material_id' => $this->material->id
                ]);

                $this->material->update([
                    'processing_status' => 'failed',
                    'error_message' => 'No questions extracted from past paper'
                ]);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove Existing Questions (if any)
            |--------------------------------------------------------------------------
            */

            Question::where(
                'material_id',
                $this->material->id
            )->delete();

            DB::beginTransaction();

            $savedCount = 0;

            $cropService = app(\App\Services\QuestionImageCropService::class);

            foreach ($validatedQuestions as $item) {
                // Check for duplicate question in same material
                $existing = Question::where('material_id', $this->material->id)
                    ->where('question_text', trim($item['question'] ?? ''))
                    ->first();

                if ($existing) {

                    Log::warning('Duplicate Question Skipped', [
                        'question_number' => $item['question_number'] ?? null,
                        'question' => $item['question'] ?? '',
                    ]);

                    continue;
                }

                $pageImage = collect($pageImages)
                    ->firstWhere('page', $item['page']);

                $questionImageKey = null;

                if (
                    $pageImage &&
                    !empty($item['bounding_box'])
                ) {
                    $questionImageKey = $cropService->cropQuestion(
                        $pageImage,
                        $item['bounding_box'],
                        $this->material->id,
                        $item['question_number']
                    );
                }

                $question = Question::create([

                    'material_id' => $this->material->id,

                    'grade_id' => $this->material->grade_id,

                    'subject_id' => $this->material->subject_id,

                    'question_number' => $item['question_number'],

                    'question_text' => trim($item['question']),

                    'question_image' => $questionImageKey,

                    'page_image' => $pageImage['r2_key'] ?? null,

                    'question_type' => 'mcq',

                    'difficulty' => 'medium',

                    'correct_answer' => null,

                    'ai_confidence' => null,

                    'verification_status' => 'pending',

                    'explanation' => null,

                    'is_ai_generated' => false,

                    'source_type' => 'past_paper',

                    'source_page' => $item['page'],

                    'paper_name' => $this->material->title,

                    'paper_year' => (int) substr(
                        $this->material->title,
                        0,
                        4
                    ),

                    'status' => 'published',

                ]);

                    Log::info('Question Saved', [
                        'id' => $question->id,
                        'question_number' => $item['question_number'] ?? null,
                    ]);

                    Log::info('Saving Options', [
                        'question_id' => $question->id,
                        'options' => $item['options'] ?? [],
                    ]);

                // Save options with validation
                foreach (($item['options'] ?? []) as $key => $value) {
                    // Ensure valid option key (A, B, C, D)
                    $key = strtoupper(trim($key));
                    if (!in_array($key, ['A', 'B', 'C', 'D'])) {
                        continue;
                    }

                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_key' => $key,
                        'option_text' => trim($value),
                        'is_correct' => ($key === ($item['correct_answer'] ?? null)),
                    ]);
                }

                Log::info('Options Saved', [
                    'question_id' => $question->id,
                ]);

                $savedCount++;
            }

            Log::info('Saving Question', [
                'question_number' => $item['question_number'] ?? null,
                'question' => $item['question'] ?? '',
            ]);

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | Update Material
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'processing_status' => 'completed',
                'processed_at' => now(),
                'questions_count' => $savedCount,
                'error_message' => null,
            ]);

            Log::info('Past Paper Processing Completed', [
                'material_id' => $this->material->id,
                'questions_saved' => $savedCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Past Paper Processing Failed', [
                'material_id' => $this->material->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->material->update([
                'processing_status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Re-throw for queue retry
            throw $e;

        } finally {
            /*
            |--------------------------------------------------------------------------
            | Delete Temp PDF
            |--------------------------------------------------------------------------
            */

            if ($tempPdf && file_exists($tempPdf)) {
                unlink($tempPdf);
            }
        }
    }
}
