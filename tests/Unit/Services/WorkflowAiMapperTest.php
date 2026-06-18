<?php

namespace Tests\Unit\Services;

use App\Services\BusinessResearch\GeminiClient;
use App\Services\Workflow\WorkflowAiMapper;
use Mockery;
use Tests\TestCase;

class WorkflowAiMapperTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_heuristic_map_detects_business_name_column(): void
    {
        $mapper = new WorkflowAiMapper(Mockery::mock(GeminiClient::class));

        $mapping = $mapper->heuristicMap([
            'Company Name',
            'Street Address',
            'City',
            'State',
            'ZIP',
            'Website',
            'Phone',
            'Email',
        ]);

        $this->assertEquals('Company Name', $mapping['business_name']);
        $this->assertEquals('Street Address', $mapping['address']);
        $this->assertEquals('City', $mapping['city']);
        $this->assertEquals('State', $mapping['state']);
        $this->assertEquals('ZIP', $mapping['zip_code']);
    }

    public function test_heuristic_map_ignores_owner_name_for_business_name(): void
    {
        $mapper = new WorkflowAiMapper(Mockery::mock(GeminiClient::class));

        $mapping = $mapper->heuristicMap([
            'Owner Name',
            'Business',
            'City',
        ]);

        $this->assertEquals('Business', $mapping['business_name']);
    }

    public function test_match_header_to_headers_is_case_insensitive(): void
    {
        $mapper = new WorkflowAiMapper(Mockery::mock(GeminiClient::class));

        $matched = $mapper->matchHeaderToHeaders('company name', [
            'Company Name',
            'City',
        ]);

        $this->assertEquals('Company Name', $matched);
    }

    public function test_merge_mappings_prefers_ai_match_and_fills_gaps_with_heuristics(): void
    {
        $mapper = new WorkflowAiMapper(Mockery::mock(GeminiClient::class));
        $headers = ['Business', 'City', 'State'];

        $merged = $mapper->mergeMappings(
            [
                'business_name' => 'Business',
                'address' => null,
                'city' => 'City',
                'state' => 'State',
                'zip_code' => null,
                'country' => null,
                'website' => null,
                'input_phone' => null,
                'input_email' => null,
            ],
            [
                'business_name' => 'City',
                'address' => null,
                'city' => null,
                'state' => null,
                'zip_code' => null,
                'country' => null,
                'website' => null,
                'input_phone' => null,
                'input_email' => null,
            ],
            $headers
        );

        $this->assertEquals('City', $merged['business_name']);
        $this->assertEquals('City', $merged['city']);
        $this->assertEquals('State', $merged['state']);
    }
}
