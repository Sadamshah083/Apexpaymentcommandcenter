<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Resolve Workspace
        $workspace = Workspace::where('name', 'ApexPayments')->first();
        if (!$workspace) {
            // Seed base workspace users/roles if not seeded yet
            $this->call(ApexPaymentsWorkspaceSeeder::class);
            $workspace = Workspace::where('name', 'ApexPayments')->first();
        }

        // 2. Fetch Setters & Closers to assign leads
        $setters = $workspace->users()->wherePivot('role', 'appointment_setter')->get();
        $closers = $workspace->users()->wherePivotIn('role', ['closer', 'closers_team_lead'])->get();

        if ($setters->isEmpty() || $closers->isEmpty()) {
            return;
        }

        // 3. Define File templates
        $files = [
            [
                'name' => 'Restaurant Lead List Q2.csv',
                'original_filename' => 'restaurant_leads_q2.csv',
                'total' => 25,
                'enriched' => 20,
                'failed' => 2,
                'businesses' => [
                    ['name' => 'Flavaz Restaurant', 'city' => 'Athens', 'state' => 'GA', 'service' => 'Food & Restaurant Services'],
                    ['name' => 'Tacos El Tio', 'city' => 'Atlanta', 'state' => 'GA', 'service' => 'Mexican Food & Catering'],
                    ['name' => 'Mamma Mia Pizza', 'city' => 'Marietta', 'state' => 'GA', 'service' => 'Italian Dining'],
                    ['name' => 'Sunny Side Diner', 'city' => 'Athens', 'state' => 'GA', 'service' => 'Breakfast Buffet'],
                    ['name' => 'The Grill House', 'city' => 'Savannah', 'state' => 'GA', 'service' => 'Steakhouse & Grill'],
                    ['name' => 'Boba Tea Spot', 'city' => 'Atlanta', 'state' => 'GA', 'service' => 'Beverage & Cafe'],
                    ['name' => 'Golden Wok', 'city' => 'Marietta', 'state' => 'GA', 'service' => 'Chinese Takeout'],
                    ['name' => 'Green Garden Salad', 'city' => 'Athens', 'state' => 'GA', 'service' => 'Healthy Salads'],
                ],
            ],
            [
                'name' => 'Dentists New York Bulk.xlsx',
                'original_filename' => 'dentists_ny_bulk.xlsx',
                'total' => 18,
                'enriched' => 15,
                'failed' => 1,
                'businesses' => [
                    ['name' => 'Apex Dental Care', 'city' => 'New York', 'state' => 'NY', 'service' => 'Dentistry & Orthodontics'],
                    ['name' => 'Broadway Family Dental', 'city' => 'Brooklyn', 'state' => 'NY', 'service' => 'General Dentistry'],
                    ['name' => 'Metro Smile Clinic', 'city' => 'Queens', 'state' => 'NY', 'service' => 'Cosmetic Dentistry'],
                    ['name' => 'Bright Dental Center', 'city' => 'Bronx', 'state' => 'NY', 'service' => 'Orthodontic Services'],
                    ['name' => 'Pearl Dental Group', 'city' => 'Manhattan', 'state' => 'NY', 'service' => 'Pediatric Dentistry'],
                ],
            ],
            [
                'name' => 'Local Roofers Georgia.csv',
                'original_filename' => 'local_roofers_ga.csv',
                'total' => 12,
                'enriched' => 10,
                'failed' => 0,
                'businesses' => [
                    ['name' => 'GA Roof Pro', 'city' => 'Macon', 'state' => 'GA', 'service' => 'Roofing & Construction'],
                    ['name' => 'PeachState Roofers', 'city' => 'Columbus', 'state' => 'GA', 'service' => 'Residential Roofing'],
                    ['name' => 'Apex Roofing Solutions', 'city' => 'Athens', 'state' => 'GA', 'service' => 'Commercial Roofing'],
                    ['name' => 'Southern Roofing & Gutters', 'city' => 'Savannah', 'state' => 'GA', 'service' => 'Roof Repair & Gutter Installs'],
                ],
            ],
        ];

        // 4. Seed workflows and leads
        foreach ($files as $index => $fileData) {
            $workflow = Workflow::create([
                'workspace_id' => $workspace->id,
                'name' => $fileData['name'],
                'status' => 'completed',
                'processing_mode' => 'import_and_enrich',
                'original_filename' => $fileData['original_filename'],
                'file_path' => 'workflows/' . $fileData['original_filename'],
                'total_leads' => $fileData['total'],
                'processed_leads' => $fileData['total'],
                'enriched_leads' => $fileData['enriched'],
                'failed_leads' => $fileData['failed'],
                'ingestion_complete' => true,
                'created_at' => Carbon::now()->subDays(3 - $index),
            ]);

            // Seed lead records for this workflow
            $totalLeads = $fileData['total'];
            for ($i = 1; $i <= $totalLeads; $i++) {
                $bizIndex = ($i - 1) % count($fileData['businesses']);
                $biz = $fileData['businesses'][$bizIndex];

                // Distribute phases
                $phaseRand = rand(1, 100);
                if ($phaseRand <= 30) {
                    // Closed (Won or Lost)
                    $phase = 'closed';
                    $stage = rand(1, 100) > 40 ? 'closed_won' : 'closed_lost';
                    $closerStatus = $stage === 'closed_won' ? 'sale_made' : 'closed_lost';
                } elseif ($phaseRand <= 55) {
                    // Showed (proposal, follow up)
                    $phase = 'with_closer';
                    $stage = 'proposal_sent';
                    $closerStatus = 'proposal_sent';
                } elseif ($phaseRand <= 75) {
                    // Booked
                    $phase = 'appointment_settled';
                    $stage = 'meeting_scheduled';
                    $closerStatus = 'scheduled';
                } elseif ($phaseRand <= 90) {
                    // Qualified
                    $phase = 'enriched';
                    $stage = 'connected';
                    $closerStatus = null;
                } else {
                    // New
                    $phase = 'imported';
                    $stage = 'new_lead';
                    $closerStatus = null;
                }

                $setter = $setters->random();
                $closer = $closers->random();

                // Payment processor
                $processors = ['Square', 'Stripe', 'Clover', 'Toast', 'ServiceTitan Payments', 'Not Publicly Available'];
                $processor = $processors[rand(0, count($processors) - 1)];

                $saleValue = ($stage === 'closed_won') ? rand(1500, 8500) : 0;
                $attempts = rand(1, 8);

                WorkflowLead::create([
                    'workflow_id' => $workflow->id,
                    'import_mode' => 'pipeline',
                    'pipeline_phase' => $phase,
                    'stage' => $stage,
                    'status' => ($i <= $fileData['enriched']) ? 'completed' : (($i <= $fileData['enriched'] + $fileData['failed']) ? 'failed' : 'imported'),
                    'error_message' => ($i > $fileData['enriched'] && $i <= $fileData['enriched'] + $fileData['failed']) ? 'LLM failed to output correct markdown structure.' : null,
                    'row_number' => $i,
                    'business_name' => $biz['name'] . ' #' . $i,
                    'city' => $biz['city'],
                    'state' => $biz['state'],
                    'country' => 'United States',
                    'primary_service' => $biz['service'],
                    'website' => 'https://' . strtolower(str_replace(' ', '', $biz['name'])) . '.com',
                    'input_phone' => '+1555000' . sprintf('%04d', rand(100, 9999)),
                    'input_email' => 'contact@' . strtolower(str_replace(' ', '', $biz['name'])) . '.com',
                    'owner_name' => rand(1, 100) > 20 ? 'John ' . chr(rand(65, 90)) . '. Doe' : null,
                    'direct_phone' => rand(1, 100) > 30 ? '+1555999' . sprintf('%04d', rand(100, 9999)) : null,
                    'direct_email' => rand(1, 100) > 35 ? 'owner@' . strtolower(str_replace(' ', '', $biz['name'])) . '.com' : null,
                    'payment_processor' => $processor,
                    'system_integration' => $processor !== 'Not Publicly Available' ? 'We found ' . $processor . ' checkout script on their booking page.' : 'No POS found.',
                    'operating_hours' => 'Mon-Fri 9AM-5PM',
                    'markdown_report' => "### Business Identity\n* **Business Name**: " . $biz['name'] . "\n* **Payment Processor**: " . $processor,
                    
                    // Assign users
                    'assigned_setter_id' => $setter->id,
                    'assigned_closer_id' => $closer->id,
                    'assigned_user_id' => ($phase === 'closed' || $phase === 'with_closer') ? $closer->id : $setter->id,
                    
                    'closer_status' => $closerStatus,
                    'setter_status' => ($phase !== 'imported' && $phase !== 'enriched') ? 'meeting_booked' : null,
                    'contact_attempts' => $attempts,
                    'sale_value' => $saleValue,
                    'monthly_processing_volume' => rand(10000, 150000),
                ]);
            }
        }
    }
}
