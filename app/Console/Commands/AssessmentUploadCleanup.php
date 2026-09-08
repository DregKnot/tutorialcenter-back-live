<?php

namespace App\Console\Commands;

use App\Models\AssessmentAnswerFile;
use App\Models\AssessmentQuestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AssessmentUploadCleanup extends Command
{
    protected $signature = 'assessment:cleanup-uploads';

    protected $description = 'Delete assessment image uploads that were never attached to a submission';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        $referenced = AssessmentAnswerFile::pluck('file_path')
            ->merge(AssessmentQuestion::whereNotNull('model_image')->pluck('model_image'))
            ->flip();

        $cutoff = now()->subHours(24)->getTimestamp();

        foreach ($disk->directories('assessment-uploads') as $directory) {
            foreach ($disk->allFiles($directory) as $file) {
                if (! $referenced->has($file) && $disk->lastModified($file) < $cutoff) {
                    $disk->delete($file);
                }
            }
        }

        $this->info('Orphaned assessment uploads cleaned.');
        return self::SUCCESS;
    }
}
