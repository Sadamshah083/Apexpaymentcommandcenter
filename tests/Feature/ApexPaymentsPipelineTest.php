<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\CloserAssignmentService;
use App\Services\Pipeline\LeadPipelineService;
use App\Services\Pipeline\SetterDistributionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApexPaymentsPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected User $superAdmin;

    protected User $admin;

    protected User $setter;

    protected User $closer;

    protected User $closerTl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'ApexPayments',
            'admin_id' => $this->superAdmin->id,
        ]);
        $this->workspace->users()->attach($this->superAdmin->id, [
            'role' => 'super_admin', 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->superAdmin->update(['current_workspace_id' => $this->workspace->id]);

        $this->admin = $this->attachUser('admin', 'admin');
        $this->setter = $this->attachUser('setter1', 'appointment_setter');
        $this->closer = $this->attachUser('closer1', 'closer');
        $this->closerTl = $this->attachUser('closertl', 'closers_team_lead');
    }

    protected function attachUser(string $name, string $role): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->workspace->users()->attach($user->id, [
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $user->update(['current_workspace_id' => $this->workspace->id]);

        return $user;
    }

    protected function makeLead(array $attrs = []): WorkflowLead
    {
        $workflow = Workflow::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Test Import',
            'status' => 'completed',
            'processing_mode' => 'full_pipeline',
        ]);

        return WorkflowLead::create(array_merge([
            'workflow_id' => $workflow->id,
            'row_number' => 1,
            'business_name' => 'Test Business',
            'status' => 'completed',
            'pipeline_phase' => 'with_setter',
            'setter_status' => 'new',
            'assigned_user_id' => $this->setter->id,
            'assigned_setter_id' => $this->setter->id,
        ], $attrs));
    }

    public function test_super_admin_can_create_member_but_admin_cannot(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.workspaces.members.store', $this->workspace), [
                'username' => 'newsetter',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
                'role' => 'appointment_setter',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['name' => 'newsetter']);

        $this->actingAs($this->admin)
            ->post(route('admin.workspaces.members.store', $this->workspace), [
                'username' => 'blocked',
                'password' => 'secret12',
                'password_confirmation' => 'secret12',
                'role' => 'appointment_setter',
            ])
            ->assertForbidden();
    }

    public function test_setter_handoff_moves_lead_to_closer_queue(): void
    {
        $lead = $this->makeLead();

        app(LeadPipelineService::class)->updateSetterStatus(
            $this->setter,
            $lead,
            $this->workspace,
            'appointment_settled',
            'Meeting booked Tuesday'
        );

        $lead->refresh();
        $this->assertSame('appointment_settled', $lead->pipeline_phase);
        $this->assertNull($lead->assigned_user_id);
        $this->assertNotNull($lead->appointment_settled_at);
    }

    public function test_closer_team_lead_can_assign_closer(): void
    {
        $lead = $this->makeLead([
            'pipeline_phase' => 'appointment_settled',
            'assigned_user_id' => null,
            'setter_status' => 'appointment_settled',
        ]);

        app(CloserAssignmentService::class)->assign(
            $this->workspace,
            $lead,
            $this->closer,
            $this->closerTl
        );

        $lead->refresh();
        $this->assertSame('with_closer', $lead->pipeline_phase);
        $this->assertSame($this->closer->id, $lead->assigned_user_id);
    }

    public function test_only_closer_can_mark_sale_made(): void
    {
        $lead = $this->makeLead([
            'pipeline_phase' => 'with_closer',
            'assigned_user_id' => $this->closer->id,
            'assigned_closer_id' => $this->closer->id,
            'closer_status' => 'new',
        ]);

        app(LeadPipelineService::class)->updateCloserStatus(
            $this->closer,
            $lead,
            $this->workspace,
            'sale_made'
        );

        $lead->refresh();
        $this->assertSame('closed', $lead->pipeline_phase);
        $this->assertSame('sale_made', $lead->closer_status);

        $lead2 = $this->makeLead([
            'pipeline_phase' => 'with_closer',
            'assigned_user_id' => $this->closer->id,
            'assigned_closer_id' => $this->closer->id,
            'closer_status' => 'new',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LeadPipelineService::class)->updateCloserStatus(
            $this->setter,
            $lead2,
            $this->workspace,
            'sale_made'
        );
    }

    public function test_setter_distribution_round_robin(): void
    {
        $setter2 = $this->attachUser('setter2', 'appointment_setter');
        $workflow = Workflow::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'RR Test',
            'status' => 'extracting',
            'processing_mode' => 'full_pipeline',
            'distribution_cursor' => 0,
        ]);

        $lead1 = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 1,
            'business_name' => 'Lead 1',
            'status' => 'completed',
            'pipeline_phase' => 'enriching',
        ]);
        $lead2 = WorkflowLead::create([
            'workflow_id' => $workflow->id,
            'row_number' => 2,
            'business_name' => 'Lead 2',
            'status' => 'completed',
            'pipeline_phase' => 'enriching',
        ]);

        $service = app(SetterDistributionService::class);
        $service->assignNext($this->workspace, $lead1, $workflow);
        $service->assignNext($this->workspace, $lead2, $workflow->fresh());

        $this->assertNotSame(
            $lead1->fresh()->assigned_user_id,
            $lead2->fresh()->assigned_user_id
        );
    }

    public function test_role_dashboard_routes(): void
    {
        $this->actingAs($this->setter)
            ->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.setter.dashboard'));

        $this->actingAs($this->closerTl)
            ->get(route('portal.dashboard'))
            ->assertRedirect(route('portal.closer-team.dashboard'));
    }

    public function test_closer_team_lead_can_access_dashboard(): void
    {
        $this->actingAs($this->closerTl)
            ->get(route('portal.closer-team.dashboard'))
            ->assertOk()
            ->assertViewIs('pipeline.closer-team.index')
            ->assertViewHas('leads')
            ->assertViewHas('teamMetrics');
    }
}
