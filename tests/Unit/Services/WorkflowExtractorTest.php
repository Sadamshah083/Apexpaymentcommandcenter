<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\BusinessResearch\GeminiClient;
use App\Services\BusinessResearch\WebSearchService;
use App\Services\Workflow\WorkflowExtractor;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkflowExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_extract_maps_markdown_report_fields(): void
    {
        config([
            'workflow_enrichment.follow_up_enabled' => false,
            'workflow_enrichment.gemini_google_search_enabled' => true,
            'gemini.api_key' => 'test-key',
        ]);

        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Import',
            'status' => 'extracting',
        ]);
        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 1,
            'business_name' => 'Joe\'s Plumbing',
            'city' => 'Dallas',
            'state' => 'TX',
            'status' => 'imported',
        ]);

        $markdown = <<<'MD'
### Business Identity & Location
* **Business Name**: [Joe's Plumbing](https://joesplumbing.example)
* **Physical Address**: 123 Main St, Dallas, TX 75201
* **Primary Service**: Residential plumbing repairs
* **Operating Hours**: Mon-Fri 8am-6pm

### Owner & Contact Information
* **Direct Owner Name**: Joe Martinez
* **Direct Phone Number**: (214) 555-0199
* **Direct Email Address**: info@joesplumbing.example

### Payment Processor & Booking Software
* **Payment Processor**: Square
* **System Integration**: Square POS handles in-person payments and invoices.
MD;

        $webSearch = Mockery::mock(WebSearchService::class);
        $webSearch->shouldReceive('gatherContext')->once()->andReturn([]);
        $webSearch->shouldReceive('formatContextBlock')->andReturn('No supplemental web snippets collected.');
        $this->app->instance(WebSearchService::class, $webSearch);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('researchWithGoogleSearch')
            ->once()
            ->andReturn([
                'content' => $markdown,
                'model' => 'gemini-2.5-flash',
                'tokens' => 321,
                'sources' => [],
                'search_queries' => [],
                'raw' => [],
            ]);

        $this->app->instance(GeminiClient::class, $gemini);

        $providerStatus = Mockery::mock(WorkflowProviderStatusService::class);
        $providerStatus->shouldReceive('getGeminiHealth')->andReturn(['state' => 'ok']);
        $providerStatus->shouldReceive('clearGeminiError')->once();
        $this->app->instance(WorkflowProviderStatusService::class, $providerStatus);

        $extractor = app(WorkflowExtractor::class);
        $result = $extractor->extract($lead);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('Joe Martinez', $result['owner_name']);
        $this->assertSame('(214) 555-0199', $result['direct_phone']);
        $this->assertSame('info@joesplumbing.example', $result['direct_email']);
        $this->assertSame('Square', $result['payment_processor']);
        $this->assertSame('123 Main St, Dallas, TX 75201', $result['address']);
    }

    public function test_extract_accepts_schema_when_all_fields_are_not_publicly_available(): void
    {
        config([
            'workflow_enrichment.follow_up_enabled' => false,
            'workflow_enrichment.gemini_google_search_enabled' => true,
            'gemini.api_key' => 'test-key',
        ]);

        $owner = User::factory()->create();
        $workspace = Workspace::create(['name' => 'Acme', 'admin_id' => $owner->id]);
        $workflow = Workflow::create([
            'workspace_id' => $workspace->id,
            'name' => 'Import',
            'status' => 'extracting',
        ]);
        $lead = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 2,
            'business_name' => 'WhoHungry?',
            'city' => 'Athens',
            'state' => 'GA',
            'status' => 'imported',
        ]);

        $markdown = <<<'MD'
### Business Identity & Location
* **Business Name**: WhoHungry?
* **Physical Address**: Not Publicly Available
* **Primary Service**: Food truck
* **Operating Hours**: Not Publicly Available

### Owner & Contact Information
* **Direct Owner Name**: Not Publicly Available
* **Direct Phone Number**: Not Publicly Available
* **Direct Email Address**: Not Publicly Available

### Payment Processor & Booking Software
* **Payment Processor**: Not Publicly Available
* **System Integration**: Not Publicly Available
MD;

        $webSearch = Mockery::mock(WebSearchService::class);
        $webSearch->shouldReceive('gatherContext')->once()->andReturn([]);
        $webSearch->shouldReceive('formatContextBlock')->andReturn('No supplemental web snippets collected.');
        $this->app->instance(WebSearchService::class, $webSearch);

        $gemini = Mockery::mock(GeminiClient::class);
        $gemini->shouldReceive('researchWithGoogleSearch')
            ->once()
            ->andReturn([
                'content' => $markdown,
                'model' => 'gemini-2.5-flash',
                'tokens' => 200,
                'sources' => [],
                'search_queries' => [],
                'raw' => [],
            ]);
        $this->app->instance(GeminiClient::class, $gemini);

        $providerStatus = Mockery::mock(WorkflowProviderStatusService::class);
        $providerStatus->shouldReceive('getGeminiHealth')->andReturn(['state' => 'ok']);
        $providerStatus->shouldReceive('clearGeminiError')->once();
        $this->app->instance(WorkflowProviderStatusService::class, $providerStatus);

        $result = app(WorkflowExtractor::class)->extract($lead);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('Not Publicly Available', $result['owner_name']);
        $this->assertStringContainsString('WhoHungry?', $result['markdown_report']);
    }
}
