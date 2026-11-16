<?php

namespace App\Jobs;

use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Services\PdfToImageService;
use App\Services\PastPaperValidationService;
use App\Services\PastPaperVisionService;
use App\Services\QuestionImageCropService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GeneratePastPaperQuestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

            /*
            |--------------------------------------------------------------------------
            | START
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Past Paper Processing Started',
                [
                    'material_id' => $this->material->id,
                    'title' => $this->material->title,
                    'medium' => $this->material->medium,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | VALIDATE MATERIAL LANGUAGE
            |--------------------------------------------------------------------------
            |
            | Material.medium must be:
            |
            | si = Sinhala
            | en = English
            | ta = Tamil
            |
            */

            $quizLanguage = strtolower(
                trim($this->material->medium ?? '')
            );

            if (!in_array(
                $quizLanguage,
                ['si', 'en', 'ta'],
                true
            )) {

                Log::error(
                    'Invalid Past Paper Material Medium',
                    [
                        'material_id' =>
                            $this->material->id,

                        'medium' =>
                            $this->material->medium,
                    ]
                );

                throw new \RuntimeException(
                    'Invalid material medium: ' .
                    ($this->material->medium ?? 'NULL')
                );
            }

            /*
            |--------------------------------------------------------------------------
            | LANGUAGE LOG
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Past Paper Quiz Language Resolved',
                [
                    'material_id' =>
                        $this->material->id,

                    'material_medium' =>
                        $this->material->medium,

                    'quiz_language' =>
                        $quizLanguage,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | MARK PROCESSING
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'processing_status' => 'processing',
                'error_message' => null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | TEMP DIRECTORY
            |--------------------------------------------------------------------------
            */

            $tempDirectory = storage_path('app/temp');

            if (!is_dir($tempDirectory)) {

                mkdir(
                    $tempDirectory,
                    0775,
                    true
                );
            }

            /*
            |--------------------------------------------------------------------------
            | TEMP PDF
            |--------------------------------------------------------------------------
            */

            $tempPdf =
                $tempDirectory .
                DIRECTORY_SEPARATOR .
                $this->material->id .
                '_' .
                time() .
                '.pdf';

            /*
            |--------------------------------------------------------------------------
            | DOWNLOAD PDF
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Downloading Past Paper PDF',
                [
                    'material_id' =>
                        $this->material->id,

                    'file_path' =>
                        $this->material->file_path,
                ]
            );

            $pdfContent = Storage::disk('s3')->get(
                $this->material->file_path
            );

            if (
                $pdfContent === null ||
                $pdfContent === ''
            ) {

                throw new \RuntimeException(
                    'Past paper PDF could not be downloaded or is empty.'
                );
            }

            if (
                file_put_contents(
                    $tempPdf,
                    $pdfContent
                ) === false
            ) {

                throw new \RuntimeException(
                    'Unable to save temporary past paper PDF.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | CONVERT PDF TO IMAGES
            |--------------------------------------------------------------------------
            */

            Log::info(
                'Converting Past Paper PDF To Images',
                [
                    'material_id' =>
                        $this->material->id,
                ]
            );

            $pageImages = app(
                PdfToImageService::class
            )->convert(
                $this->material,
                $tempPdf
            );

            if (empty($pageImages)) {

                throw new \RuntimeException(
                    'No page images were generated from the past paper PDF.'
                );
            }

            Log::info(
                'PDF Conversion Completed',
                [
                    'material_id' =>
                        $this->material->id,

                    'total_pages' =>
                        count($pageImages),
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | VISION EXTRACTION
            |--------------------------------------------------------------------------
            |
            | PastPaperVisionService:
            |
            | ONE PAGE = ONE VISION REQUEST
            |
            */

            $questions = app(
                PastPaperVisionService::class
            )->extractQuestions(
                $pageImages
            );

            Log::info(
                'Past Paper Vision Extraction Completed',
                [
                    'material_id' =>
                        $this->material->id,

                    'pages' =>
                        count($pageImages),

                    'questions_extracted' =>
                        count($questions),

                    'quiz_language' =>
                        $quizLanguage,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | NO QUESTIONS
            |--------------------------------------------------------------------------
            */

            if (empty($questions)) {

                $this->material->update([
                    'processing_status' => 'failed',

                    'error_message' =>
                        'No genuine MCQ questions were extracted from the past paper.',

                    'questions_count' => 0,
                ]);

                Log::warning(
                    'No Questions Extracted From Past Paper',
                    [
                        'material_id' =>
                            $this->material->id,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDATION SERVICE
            |--------------------------------------------------------------------------
            */

            $validatedQuestions = app(
                PastPaperValidationService::class
            )->validateBatch(
                $questions
            );

            Log::info(
                'Past Paper Validation Completed',
                [
                    'material_id' =>
                        $this->material->id,

                    'extracted_questions' =>
                        count($questions),

                    'valid_questions' =>
                        count($validatedQuestions),

                    'quiz_language' =>
                        $quizLanguage,
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | NO VALID QUESTIONS
            |--------------------------------------------------------------------------
            */

            if (empty($validatedQuestions)) {

                $this->material->update([
                    'processing_status' => 'failed',

                    'error_message' =>
                        'No valid MCQ questions found in past paper.',

                    'questions_count' => 0,
                ]);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | PAGE LOOKUP
            |--------------------------------------------------------------------------
            |
            | Build once instead of calling collect()->firstWhere()
            | for every question.
            |
            */

            $pageLookup = [];

            foreach ($pageImages as $pageImage) {

                if (!is_array($pageImage)) {
                    continue;
                }

                if (!isset($pageImage['page'])) {
                    continue;
                }

                $page = (int) $pageImage['page'];

                if ($page <= 0) {
                    continue;
                }

                $pageLookup[$page] = $pageImage;
            }

            /*
            |--------------------------------------------------------------------------
            | CROP SERVICE
            |--------------------------------------------------------------------------
            */

            $cropService = app(
                QuestionImageCropService::class
            );

            /*
            |--------------------------------------------------------------------------
            | DATABASE TRANSACTION
            |--------------------------------------------------------------------------
            */

            $savedCount = 0;

            DB::transaction(function () use (
                $validatedQuestions,
                $pageLookup,
                $cropService,
                $quizLanguage,
                &$savedCount
            ) {

                /*
                |--------------------------------------------------------------------------
                | REMOVE OLD QUESTIONS
                |--------------------------------------------------------------------------
                */

                Question::where(
                    'material_id',
                    $this->material->id
                )->delete();

                /*
                |--------------------------------------------------------------------------
                | SAVE QUESTIONS
                |--------------------------------------------------------------------------
                */

                foreach (
                    $validatedQuestions
                    as $item
                ) {

                    /*
                    |--------------------------------------------------------------------------
                    | BASIC STRUCTURE
                    |--------------------------------------------------------------------------
                    */

                    if (!is_array($item)) {
                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | QUESTION NUMBER
                    |--------------------------------------------------------------------------
                    */

                    $questionNumber =
                        isset(
                            $item['question_number']
                        )
                            ? (int)
                                $item['question_number']
                            : 0;

                    if ($questionNumber <= 0) {

                        Log::warning(
                            'Invalid Past Paper Question Number',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'item' =>
                                    $item,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | QUESTION TEXT
                    |--------------------------------------------------------------------------
                    */

                    $questionText = trim(
                        (string) (
                            $item['question']
                            ?? ''
                        )
                    );

                    if ($questionText === '') {

                        Log::warning(
                            'Empty Past Paper Question Text',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'question_number' =>
                                    $questionNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | PAGE
                    |--------------------------------------------------------------------------
                    */

                    $pageNumber =
                        isset($item['page'])
                            ? (int) $item['page']
                            : 0;

                    if ($pageNumber <= 0) {

                        Log::warning(
                            'Invalid Past Paper Question Page',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'question_number' =>
                                    $questionNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | SOURCE PAGE
                    |--------------------------------------------------------------------------
                    */

                    $pageImage =
                        $pageLookup[$pageNumber]
                        ?? null;

                    if (!$pageImage) {

                        Log::warning(
                            'Source Page Not Found For Question',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'question_number' =>
                                    $questionNumber,

                                'page' =>
                                    $pageNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | OPTIONS
                    |--------------------------------------------------------------------------
                    */

                    $options =
                        $item['options']
                        ?? [];

                    if (!is_array($options)) {

                        Log::warning(
                            'Past Paper Question Options Invalid',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'question_number' =>
                                    $questionNumber,

                                'page' =>
                                    $pageNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | REQUIRE A-D
                    |--------------------------------------------------------------------------
                    */

                    $normalizedOptions = [];

                    $validOptions = true;

                    foreach (
                        ['A', 'B', 'C', 'D']
                        as $optionKey
                    ) {

                        if (
                            !array_key_exists(
                                $optionKey,
                                $options
                            )
                        ) {

                            $validOptions = false;
                            break;
                        }

                        $optionText = trim(
                            (string)
                                $options[$optionKey]
                        );

                        if ($optionText === '') {

                            $validOptions = false;
                            break;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Reject obvious hallucinations
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $this->isPlaceholderOption(
                                $optionText
                            )
                        ) {

                            Log::warning(
                                'Hallucinated Past Paper Option Rejected',
                                [
                                    'material_id' =>
                                        $this->material->id,

                                    'page' =>
                                        $pageNumber,

                                    'question_number' =>
                                        $questionNumber,

                                    'option' =>
                                        $optionKey,

                                    'value' =>
                                        $optionText,
                                ]
                            );

                            $validOptions = false;
                            break;
                        }

                        $normalizedOptions[
                            $optionKey
                        ] = $optionText;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Do NOT save incomplete MCQ
                    |--------------------------------------------------------------------------
                    */

                    if (
                        !$validOptions ||
                        count($normalizedOptions) !== 4
                    ) {

                        Log::warning(
                            'Incomplete/Hallucinated Past Paper MCQ Skipped',
                            [
                                'material_id' =>
                                    $this->material->id,

                                'page' =>
                                    $pageNumber,

                                'question_number' =>
                                    $questionNumber,
                            ]
                        );

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CREATE QUESTION
                    |--------------------------------------------------------------------------
                    |
                    | IMPORTANT LANGUAGE FIX
                    |
                    | quiz_language comes directly from
                    | materials.medium.
                    |
                    | si = Sinhala
                    | en = English
                    | ta = Tamil
                    |
                    */

                    $question = Question::create([

                        'material_id' =>
                            $this->material->id,

                        'grade_id' =>
                            $this->material->grade_id,

                        'subject_id' =>
                            $this->material->subject_id,

                        'quiz_language' =>
                            $quizLanguage,

                        'question_number' =>
                            $questionNumber,

                        'question_text' =>
                            $questionText,

                        'question_image' =>
                            null,

                        'page_image' =>
                            $pageImage['r2_key']
                            ?? null,

                        'question_type' =>
                            'mcq',

                        'difficulty' =>
                            'medium',

                        'correct_answer' =>
                            null,

                        'ai_confidence' =>
                            null,

                        'verification_status' =>
                            'pending',

                        'explanation' =>
                            null,

                        'is_ai_generated' =>
                            false,

                        'source_type' =>
                            'past_paper',

                        'source_page' =>
                            $pageNumber,

                        'paper_name' =>
                            $this->material->title,

                        'paper_year' =>
                            null,

                        'status' =>
                            'published',
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | MEDIA
                    |--------------------------------------------------------------------------
                    */

                    $mediaItems =
                        $item['media']
                        ?? [];

                    if (
                        is_array($mediaItems) &&
                        !empty($mediaItems)
                    ) {

                        foreach (
                            $mediaItems
                            as $mediaIndex => $media
                        ) {

                            if (!is_array($media)) {
                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Media type
                            |--------------------------------------------------------------------------
                            */

                            $mediaType =
                                strtolower(
                                    trim(
                                        (string) (
                                            $media['type']
                                            ?? ''
                                        )
                                    )
                                );

                            /*
                            |--------------------------------------------------------------------------
                            | Bounding box
                            |--------------------------------------------------------------------------
                            */

                            $mediaBoundingBox =
                                $media['bounding_box']
                                ?? null;

                            if (
                                !is_array(
                                    $mediaBoundingBox
                                )
                            ) {

                                Log::warning(
                                    'Past Paper Media Missing Bounding Box',
                                    [
                                        'material_id' =>
                                            $this->material->id,

                                        'question_id' =>
                                            $question->id,

                                        'question_number' =>
                                            $questionNumber,

                                        'media_type' =>
                                            $mediaType,
                                    ]
                                );

                                continue;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Crop media
                            |--------------------------------------------------------------------------
                            */

                            try {

                                $mediaImageKey =
                                    $cropService->cropMedia(
                                        $pageImage,
                                        $mediaBoundingBox,
                                        $this->material->id,
                                        $question->id,
                                        ((int) $mediaIndex) + 1,
                                        $mediaType
                                    );

                                if (!$mediaImageKey) {

                                    Log::warning(
                                        'Past Paper Media Crop Returned Null',
                                        [
                                            'material_id' =>
                                                $this->material->id,

                                            'question_id' =>
                                                $question->id,

                                            'question_number' =>
                                                $questionNumber,

                                            'page' =>
                                                $pageNumber,

                                            'media_type' =>
                                                $mediaType,
                                        ]
                                    );

                                    continue;
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | Store media image
                                |--------------------------------------------------------------------------
                                */

                                $question->question_image =
                                    $mediaImageKey;

                                $question->save();

                                Log::info(
                                    'Past Paper Media Cropped',
                                    [
                                        'material_id' =>
                                            $this->material->id,

                                        'question_id' =>
                                            $question->id,

                                        'question_number' =>
                                            $questionNumber,

                                        'page' =>
                                            $pageNumber,

                                        'media_type' =>
                                            $mediaType,

                                        'r2_key' =>
                                            $mediaImageKey,

                                        'bounding_box' =>
                                            $mediaBoundingBox,
                                    ]
                                );

                                /*
                                |--------------------------------------------------------------------------
                                | IMPORTANT
                                |--------------------------------------------------------------------------
                                |
                                | Current Question model has only
                                | one question_image column.
                                |
                                | Therefore save only the first
                                | successfully cropped media.
                                |--------------------------------------------------------------------------
                                */

                                break;

                            } catch (Throwable $e) {

                                Log::warning(
                                    'Past Paper Media Crop Failed',
                                    [
                                        'material_id' =>
                                            $this->material->id,

                                        'question_id' =>
                                            $question->id,

                                        'question_number' =>
                                            $questionNumber,

                                        'page' =>
                                            $pageNumber,

                                        'media_type' =>
                                            $mediaType,

                                        'error' =>
                                            $e->getMessage(),
                                    ]
                                );

                                /*
                                |--------------------------------------------------------------------------
                                | Media failure must not destroy
                                | the entire question.
                                |--------------------------------------------------------------------------
                                */

                                continue;
                            }
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | SAVE OPTIONS
                    |--------------------------------------------------------------------------
                    */

                    foreach (
                        $normalizedOptions
                        as $optionKey => $optionText
                    ) {

                        QuestionOption::create([

                            'question_id' =>
                                $question->id,

                            'option_key' =>
                                $optionKey,

                            'option_text' =>
                                $optionText,

                            /*
                            |--------------------------------------------------------------------------
                            | Answer sheet pipeline will
                            | update this later.
                            |--------------------------------------------------------------------------
                            */

                            'is_correct' =>
                                false,
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | LOG SAVED QUESTION
                    |--------------------------------------------------------------------------
                    */

                    Log::info(
                        'Past Paper Question Saved',
                        [
                            'material_id' =>
                                $this->material->id,

                            'question_id' =>
                                $question->id,

                            'question_number' =>
                                $questionNumber,

                            'page' =>
                                $pageNumber,

                            'quiz_language' =>
                                $quizLanguage,

                            'has_question_image' =>
                                !empty(
                                    $question->question_image
                                ),

                            'has_media' =>
                                !empty(
                                    $mediaItems
                                ),
                        ]
                    );

                    $savedCount++;
                }
            });

            /*
            |--------------------------------------------------------------------------
            | NOTHING SAVED
            |--------------------------------------------------------------------------
            */

            if ($savedCount <= 0) {

                $this->material->update([
                    'processing_status' =>
                        'failed',

                    'error_message' =>
                        'Questions were extracted but none passed validation and could be saved.',

                    'questions_count' =>
                        0,
                ]);

                Log::warning(
                    'Past Paper Processing Saved Zero Questions',
                    [
                        'material_id' =>
                            $this->material->id,

                        'extracted' =>
                            count($questions),

                        'validated' =>
                            count($validatedQuestions),

                        'quiz_language' =>
                            $quizLanguage,
                    ]
                );

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | COMPLETED
            |--------------------------------------------------------------------------
            */

            $this->material->update([
                'processing_status' =>
                    'completed',

                'processed_at' =>
                    now(),

                'questions_count' =>
                    $savedCount,

                'error_message' =>
                    null,
            ]);

            Log::info(
                'Past Paper Processing Completed',
                [
                    'material_id' =>
                        $this->material->id,

                    'questions_saved' =>
                        $savedCount,

                    'quiz_language' =>
                        $quizLanguage,
                ]
            );

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | FAILED
            |--------------------------------------------------------------------------
            */

            Log::error(
                'Past Paper Processing Failed',
                [
                    'material_id' =>
                        $this->material->id,

                    'medium' =>
                        $this->material->medium,

                    'error' =>
                        $e->getMessage(),

                    'file' =>
                        $e->getFile(),

                    'line' =>
                        $e->getLine(),

                    'trace' =>
                        $e->getTraceAsString(),
                ]
            );

            try {

                $this->material->update([
                    'processing_status' =>
                        'failed',

                    'error_message' =>
                        $e->getMessage(),
                ]);

            } catch (Throwable $updateException) {

                Log::error(
                    'Failed To Update Material Processing Status',
                    [
                        'material_id' =>
                            $this->material->id,

                        'error' =>
                            $updateException->getMessage(),
                    ]
                );
            }

            throw $e;

        } finally {

            /*
            |--------------------------------------------------------------------------
            | DELETE TEMP PDF
            |--------------------------------------------------------------------------
            */

            if (
                $tempPdf &&
                file_exists($tempPdf)
            ) {

                try {

                    unlink($tempPdf);

                } catch (Throwable $e) {

                    Log::warning(
                        'Failed To Delete Temporary Past Paper PDF',
                        [
                            'file' =>
                                $tempPdf,

                            'error' =>
                                $e->getMessage(),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Reject obvious AI placeholder options.
     *
     * This is a SECOND safety layer.
     *
     * The Vision service already performs this check.
     */
    protected function isPlaceholderOption(
        string $value
    ): bool {

        $normalized = mb_strtolower(
            trim($value)
        );

        return in_array(
            $normalized,
            [

                'පිළිතුර a',
                'පිළිතුර b',
                'පිළිතුර c',
                'පිළිතුර d',

                'පිලිතුර a',
                'පිලිතුර b',
                'පිලිතුර c',
                'පිලිතුර d',

                'answer a',
                'answer b',
                'answer c',
                'answer d',

                'option a',
                'option b',
                'option c',
                'option d',

                'option a.',
                'option b.',
                'option c.',
                'option d.',
            ],
            true
        );
    }
}
