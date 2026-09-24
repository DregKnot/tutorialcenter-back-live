<?php

namespace App\Http\Controllers;

use App\Models\PastQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Services\AdminNotificationService;

class PastQuestionController extends Controller
{
    // // List past questions with optional filtering by exam year, group, type, and status
    // public function index(Request $request)
    // {
    //     try {
    //         $query = PastQuestion::with([
    //             'examYear.examBody',
    //             'examYear.subject',
    //             'group',
    //             'options',
    //             'files',
    //         ]);

    //         if ($request->filled('exam_year_id')) {
    //             $query->where('exam_year_id', $request->exam_year_id);
    //         }

    //         if ($request->filled('past_question_group_id')) {
    //             $query->where('past_question_group_id', $request->past_question_group_id);
    //         }

    //         if ($request->filled('question_type')) {
    //             $query->where('question_type', $request->question_type);
    //         }

    //         if ($request->filled('status')) {
    //             $query->where('status', $request->status);
    //         }

    //         $questions = $query
    //             // ->get();
    //             ->orderBy('question_number')->get();
    //         // ->paginate(20);
    //         return response()->json(['questions' => $questions], 200);
    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'message' => 'An error occurred while fetching past questions.',
    //             'error' => $e->getMessage(),
    //             'file' => $e->getFile(),
    //             'line' => $e->getLine(),
    //         ], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $query = PastQuestion::with([
                'examYear.examBody',
                'examYear.subject',
                'group',
                'options',
                'files',
            ]);

            if ($request->filled('exam_year_id')) {
                $query->where('exam_year_id', $request->exam_year_id);
            }

            if ($request->filled('past_question_group_id')) {
                $query->where('past_question_group_id', $request->past_question_group_id);
            }

            if ($request->filled('question_type')) {
                $query->where('question_type', $request->question_type);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            $questions = $query
                // ->orderBy('question_number')->get();
                ->orderBy('question_number')
                ->paginate(50);
            return response()->json(['questions' => $questions], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while fetching past questions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Create new past question
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_year_id' => ['required', 'exists:exam_years,id'],
            'past_question_group_id' => ['nullable', 'exists:past_question_groups,id'],

            'question_number' => ['nullable', 'integer', 'min:1'],
            'question' => ['required', 'string'],
            'question_type' => ['nullable', 'in:multiple_choice,true_false,short_answer,essay'],
            'marks' => ['nullable', 'integer', 'min:1'],
            'explanation' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],

            'options' => ['nullable', 'array'],
            'options.*.label' => ['nullable', 'string', 'max:10'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer'],

            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf,mp3,wav', 'max:5120'],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $question = PastQuestion::create([
                'exam_year_id' => $request->exam_year_id,
                'past_question_group_id' => $request->past_question_group_id,
                'question_number' => $request->question_number,
                'question' => $this->sanitizeBase64Images($request->question),
                'question_type' => $request->question_type ?? 'multiple_choice',
                'marks' => $request->marks ?? 1,
                'explanation' => $this->sanitizeBase64Images($request->explanation),
                'status' => $request->status ?? 'active',
            ]);

            if ($request->filled('options')) {
                foreach ($request->options as $index => $option) {
                    $question->options()->create([
                        'label' => $option['label'] ?? null,
                        'option_text' => $this->sanitizeBase64Images($option['option_text']),
                        'is_correct' => $option['is_correct'] ?? false,
                        'sort_order' => $option['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $index => $file) {
                    $path = $file->store('past-question-files', 'public');

                    $question->files()->create([
                        'file_path' => $path,
                        'file_type' => $this->getFileType($file->getMimeType()),
                        'caption' => $request->captions[$index] ?? null,
                    ]);
                }
            }
            DB::commit();

            AdminNotificationService::notify(
                'Past Question Created',
                "Past question created for exam year ID: {$question->exam_year_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['past_question_id' => $question->id]
            );

            return response()->json([
                'message' => 'Past question created successfully.',
                'data' => $question->load(['examYear.examBody', 'examYear.subject', 'group', 'options', 'files']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while creating the past question.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Show single past question with questions and options
    public function show(PastQuestion $pastQuestion)
    {
        return response()->json(
            $pastQuestion->load([
                'examYear.examBody',
                'examYear.subject',
                'group',
                'options',
                'files',
            ])
        );
    }

    // Update past question
    public function update(Request $request, $id)
    {
        $pastQuestion = PastQuestion::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'exam_year_id' => ['required', 'exists:exam_years,id'],
            'past_question_group_id' => ['nullable', 'exists:past_question_groups,id'],

            'question_number' => ['nullable', 'integer', 'min:1'],
            'question' => ['required', 'string'],
            'question_type' => ['nullable', 'in:multiple_choice,true_false,short_answer,essay'],
            'marks' => ['nullable', 'integer', 'min:1'],
            'explanation' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive'],

            'options' => ['nullable', 'array'],
            'options.*.id' => ['nullable', 'exists:past_question_options,id'],
            'options.*.label' => ['nullable', 'string', 'max:10'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'options.*.sort_order' => ['nullable', 'integer'],

            'files' => ['nullable', 'array'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,pdf,mp3,wav', 'max:5120'],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }
        DB::beginTransaction();
        try {
            $pastQuestion->update([
                'exam_year_id' => $request->exam_year_id,
                'past_question_group_id' => $request->past_question_group_id,
                'question_number' => $request->question_number,
                'question' => $request->question,
                'question_type' => $request->question_type ?? $pastQuestion->question_type,
                'marks' => $request->marks ?? $pastQuestion->marks,
                'explanation' => $request->explanation,
                'status' => $request->status ?? $pastQuestion->status,
            ]);

            if ($request->filled('options')) {
                $pastQuestion->options()->delete();

                foreach ($request->options as $index => $option) {
                    $pastQuestion->options()->create([
                        'label' => $option['label'] ?? null,
                        'option_text' => $option['option_text'],
                        'is_correct' => $option['is_correct'] ?? false,
                        'sort_order' => $option['sort_order'] ?? $index,
                    ]);
                }
            }

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $index => $file) {
                    $path = $file->store('past-question-files', 'public');

                    $pastQuestion->files()->create([
                        'file_path' => $path,
                        'file_type' => $this->getFileType($file->getMimeType()),
                        'caption' => $request->captions[$index] ?? null,
                    ]);
                }
            }
            DB::commit();

            AdminNotificationService::notify(
                'Past Question Updated',
                "Past question updated for exam year ID: {$pastQuestion->exam_year_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['past_question_id' => $pastQuestion->id]
            );


            return response()->json([
                'message' => 'Past question updated successfully.',
                'pastQuestion' => $pastQuestion->load(['examYear.examBody', 'examYear.subject', 'group', 'options', 'files']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while updating the past question.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Soft delete past question
    public function destroy(PastQuestion $pastQuestion, Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $pastQuestion = PastQuestion::findOrFail($id);
            $pastQuestion->delete();
            DB::commit();
            AdminNotificationService::notify(
                'Past Question Deleted',
                "Past question deleted for exam year ID: {$pastQuestion->exam_year_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['past_question_id' => $pastQuestion->id]
            );
            return response()->json([
                'message' => 'Past question deleted successfully.',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while deleting the past question.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Restore soft deleted past question
    public function restore(PastQuestion $pastQuestion, Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $pastQuestion = PastQuestion::findOrFail($id);
            $pastQuestion->restore();
            DB::commit();
            AdminNotificationService::notify(
                'Past Question Restored',
                "Past question restored for exam year ID: {$pastQuestion->exam_year_id} by user: {$request->user()->staff_id}, {$request->user()->firstname} {$request->user()->surname}, {$request->user()->email}",
                ['past_question_id' => $pastQuestion->id]
            );
            return response()->json([
                'message' => 'Past question restored successfully.',
                'data' => $pastQuestion->load(['examYear.examBody', 'examYear.subject', 'group', 'options', 'files']),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'An error occurred while restoring the past question.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper function to determine file type based on MIME type
    private function getFileType(string $mimeType): string
    {
        try {
            if (str_starts_with($mimeType, 'image/')) {
                return 'image';
            }

            if (str_starts_with($mimeType, 'audio/')) {
                return 'audio';
            }

            if ($mimeType === 'application/pdf') {
                return 'pdf';
            }

            return 'file';
        } catch (\Exception $e) {
            return 'file type detection error: ' . $e->getMessage();
        }
    }

    /**
     * Upload an image for past questions, options, or explanations.
     * Stores the file on public disk and returns a short URL.
     */
    public function uploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp,gif,svg', 'max:5120'], // Max 5MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Image validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $file = $request->file('image');
            $path = $file->store('exams/images', 'public');
            $relativeUrl = Storage::url($path);
            $fullUrl = asset($relativeUrl);

            return response()->json([
                'success' => true,
                'message' => 'Image uploaded successfully.',
                'url' => $fullUrl,
                'relative_url' => $relativeUrl,
                'path' => $path,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to upload image.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Strip raw base64 image data URIs to protect database and network payloads.
     */
    private function sanitizeBase64Images(?string $content): ?string
    {
        if (!$content) {
            return $content;
        }
        // Strips any data:image/...;base64,... strings
        return preg_replace('/data:image\/[a-zA-Z0-9+\/]+;base64,[^"\'\s>]+/i', '', $content);
    }
}
