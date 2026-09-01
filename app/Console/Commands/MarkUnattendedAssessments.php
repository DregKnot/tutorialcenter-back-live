<?php

namespace App\Console\Commands;

use App\Services\AssessmentService;
use Illuminate\Console\Command;

class MarkUnattendedAssessments extends Command
{
    protected $signature = 'assessment:mark-unattended';

    protected $description = 'Close past-due assessments and mark non-submitters as absent';

    public function handle(AssessmentService $service): int
    {
        $service->markUnattended();
        $this->info('Unattended assessments marked absent.');
        return self::SUCCESS;
    }
}
