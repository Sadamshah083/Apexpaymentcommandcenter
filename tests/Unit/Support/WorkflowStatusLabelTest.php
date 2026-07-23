<?php

namespace Tests\Unit\Support;

use App\Support\WorkflowStatusLabel;
use PHPUnit\Framework\TestCase;

class WorkflowStatusLabelTest extends TestCase
{
    public function test_upload_only_shows_uploading_and_uploaded(): void
    {
        $this->assertSame('Uploading', WorkflowStatusLabel::label('extracting', 'import_only'));
        $this->assertSame('Uploaded', WorkflowStatusLabel::label('completed', 'import_only'));
        $this->assertSame('app-status-pill-uploading', WorkflowStatusLabel::for('extracting', 'import_only')['class']);
    }

    public function test_enrichment_mode_keeps_enriching_label(): void
    {
        $this->assertSame('Enriching', WorkflowStatusLabel::label('extracting', 'import_and_enrich'));
        $this->assertSame('Complete', WorkflowStatusLabel::label('completed', 'import_and_enrich'));
    }
}
