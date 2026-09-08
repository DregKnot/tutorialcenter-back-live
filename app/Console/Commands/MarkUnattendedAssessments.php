<?php

namespace App\Console\Commands;

use App\Services\AssessmentService;
use Illuminate\Console\Command;

/**
 * Closes past-due assessments and marks students who never submitted as absent.
 */
class MarkUnattendedAssessments extends Command
{
    protected $signature = 'assessment:mark-unattended';

    protected $description = 'Close past-due assessments and mark non-submitters as absent';

    /**
     * Delegate to the service that marks unattended submissions as absent.
     */
    public function handle(AssessmentService $service): int
    {
        $service->markUnattended();
        $this->info('Unattended assessments marked absent.');
        return self::SUCCESS;
    }
}
