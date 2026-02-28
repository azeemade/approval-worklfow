<?php

namespace Azeem\ApprovalWorkflow\Tests\Feature;

use Azeem\ApprovalWorkflow\Tests\TestCase;
use Azeem\ApprovalWorkflow\Models\ApprovalFlow;
use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Azeem\ApprovalWorkflow\Services\ApprovalService;
use Azeem\ApprovalWorkflow\Traits\HasApprovals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

class ApprovalServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('approval-workflow.user_model', ServiceTestUser::class);
        Notification::fake(); // Fake notifications so service tests don't trigger real mail

        if (!Schema::hasTable('test_models')) {
            Schema::create('test_models', function ($table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    /** @test */
    public function it_can_submit_a_request()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Expense Approval',
            'action_type' => 'expense',
            'is_active' => true
        ]);

        $flow->steps()->create(['level' => 1, 'action' => 'verify']);
        $flow->steps()->create(['level' => 2, 'action' => 'approve']);

        $user = ServiceTestUser::forceCreate(['name' => 'John', 'email' => 'john@example.com', 'password' => 'secret']);
        $this->actingAs($user);

        $model = TestModel::create(['name' => 'Trip to NY']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'expense']);

        $this->assertDatabaseHas('approval_requests', [
            'model_type' => TestModel::class,
            'model_id' => $model->id,
            'status' => \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING,
            'current_level' => 1,
        ]);
    }

    /** @test */
    public function it_advances_level_on_approval()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Expense Approval',
            'action_type' => 'expense',
            'is_active' => true
        ]);

        $flow->steps()->create(['level' => 1]);
        $flow->steps()->create(['level' => 2]);

        $user = ServiceTestUser::forceCreate(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Trip to London']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'expense', 'creator_id' => $user->id]);

        // Approve Level 1
        $service->approve($request, $user);

        $this->assertEquals(2, $request->fresh()->current_level);
        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, $request->fresh()->status);

        // Approve Level 2 (Final)
        $service->approve($request->fresh(), $user);

        $this->assertEquals(2, $request->fresh()->current_level); // stays at max or we could check status
        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::APPROVED, $request->fresh()->status);
    }

    /** @test */
    public function it_creates_a_pending_request_if_condition_is_met()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Conditional Flow True',
            'action_type' => 'conditional_true',
            'condition_class' => TrueCondition::class,
            'is_active' => true
        ]);
        $flow->steps()->create(['level' => 1, 'approver_id' => 99]);

        $model = TestModel::create(['name' => 'Should approve']);
        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'conditional_true', 'creator_id' => 1]);

        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, $request->status);
        $this->assertEquals(99, $request->current_approver_id);
    }

    /** @test */
    public function it_skips_approval_if_condition_is_not_met()
    {
        \Illuminate\Support\Facades\Event::fake([\Azeem\ApprovalWorkflow\Events\ApprovalSkipped::class]);

        $flow = ApprovalFlow::create([
            'name' => 'Conditional Flow False',
            'action_type' => 'conditional_false',
            'condition_class' => FalseCondition::class,
            'is_active' => true
        ]);
        $flow->steps()->create(['level' => 1, 'approver_id' => 99]);

        $model = TestModel::create(['name' => 'Should skip']);
        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'conditional_false', 'creator_id' => 1]);

        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::SKIPPED, $request->status);
        $this->assertNull($request->current_approver_id);

        \Illuminate\Support\Facades\Event::assertDispatched(\Azeem\ApprovalWorkflow\Events\ApprovalSkipped::class);
        $this->assertDatabaseHas('approval_request_logs', [
            'approval_request_id' => $request->id,
            'action' => 'skipped',
        ]);
    }

    /** @test */
    public function it_can_request_changes()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Expense Approval',
            'action_type' => 'expense',
            'is_active' => true
        ]);
        $flow->steps()->create(['level' => 1]);

        $approver = ServiceTestUser::forceCreate(['name' => 'Approver', 'email' => 'approver2@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Trip to London']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'expense', 'creator_id' => 1]);

        $service->requestChanges($request, $approver, 'Please attach receipt', ['receipt']);

        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED, $request->fresh()->status);
        $this->assertEquals(['receipt'], $request->fresh()->requested_changes);

        $this->assertDatabaseHas('approval_request_logs', [
            'approval_request_id' => $request->id,
            'action' => 'returned',
            'comment' => 'Please attach receipt'
        ]);
    }

    /** @test */
    public function it_can_remove_approver_and_auto_advances()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Multi-step Flow',
            'action_type' => 'multi_step',
            'is_active' => true
        ]);

        $flow->steps()->create(['level' => 1, 'approver_id' => 10]);
        $flow->steps()->create(['level' => 2, 'approver_id' => 20]);

        $admin = ServiceTestUser::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Need Approval']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'multi_step']);

        $this->assertEquals(10, $request->current_approver_id);

        // Remove the current approver (10)
        $service->removeApprover($request, 10, $admin);

        // It should auto-advance to level 2 (approver 20)
        $request->refresh();
        $this->assertEquals(2, $request->current_level);
        $this->assertEquals(20, $request->current_approver_id);
        $this->assertContains(10, $request->removed_approvers);

        $this->assertDatabaseHas('approval_request_logs', [
            'approval_request_id' => $request->id,
            'action' => 'approver_removed'
        ]);
    }

    /** @test */
    public function it_advances_immediately_if_strategy_is_any()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Any Strategy Flow',
            'action_type' => 'any_strategy',
            'is_active' => true
        ]);

        $flow->steps()->create([
            'level' => 1,
            'approvers' => [10, 11, 12],
            'strategy' => 'any'
        ]);
        $flow->steps()->create(['level' => 2, 'approver_id' => 20]);

        $approver = ServiceTestUser::forceCreate(['id' => 11, 'name' => 'Approver B', 'email' => 'b@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Any Test']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'any_strategy']);

        $this->assertEquals([10, 11, 12], $request->pending_approvers);

        // One person approves
        $service->approve($request, $approver);

        // It should advance
        $request->refresh();
        $this->assertEquals(2, $request->current_level);
        $this->assertEquals(20, $request->current_approver_id);
    }

    /** @test */
    public function it_waits_for_everyone_if_strategy_is_all()
    {
        $flow = ApprovalFlow::create([
            'name' => 'All Strategy Flow',
            'action_type' => 'all_strategy',
            'is_active' => true
        ]);

        $flow->steps()->create([
            'level' => 1,
            'approvers' => [10, 11],
            'strategy' => 'all'
        ]);
        $flow->steps()->create(['level' => 2, 'approver_id' => 20]);

        $approverA = ServiceTestUser::forceCreate(['id' => 10, 'name' => 'Approver A', 'email' => 'a@example.com', 'password' => 'secret']);
        $approverB = ServiceTestUser::forceCreate(['id' => 11, 'name' => 'Approver B', 'email' => 'b2@example.com', 'password' => 'secret']);

        $model = TestModel::create(['name' => 'All Test']);

        $service = new ApprovalService();
        $request = $service->submit($model, ['action_type' => 'all_strategy']);

        $this->assertEquals([10, 11], $request->pending_approvers);

        // Person A approves
        $service->approve($request, $approverA);

        // It should NOT advance yet
        $request->refresh();
        $this->assertEquals(1, $request->current_level);
        $this->assertEquals([11], $request->pending_approvers);
        $this->assertEquals([10], $request->approved_by);

        // Person B approves
        $service->approve($request, $approverB);

        // NOW it should advance
        $request->refresh();
        $this->assertEquals(2, $request->current_level);
        $this->assertEquals(20, $request->current_approver_id);
    }

    /** @test */
    public function it_auto_approves_when_no_flow_exists()
    {
        $user = ServiceTestUser::forceCreate(['name' => 'User', 'email' => 'noflow@example.com', 'password' => 'secret']);
        $this->actingAs($user);

        $model = TestModel::create(['name' => 'No Flow Model']);
        $service = new ApprovalService();

        // Submit with an action_type that has no flow configured at all
        $request = $service->submit($model, ['action_type' => 'unknown_action', 'creator_id' => $user->id]);

        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::APPROVED, $request->status);
        $this->assertNotNull($request->approved_at);
        $this->assertDatabaseHas('approval_request_logs', [
            'approval_request_id' => $request->id,
            'action' => 'approved',
        ]);
    }

    /** @test */
    public function it_auto_approves_when_flow_has_no_steps()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Empty Flow',
            'action_type' => 'empty_flow',
            'is_active' => true
        ]);
        // No steps created

        $user = ServiceTestUser::forceCreate(['name' => 'User', 'email' => 'nosteps@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Empty Flow Model']);
        $service = new ApprovalService();

        $request = $service->submit($model, ['action_type' => 'empty_flow', 'creator_id' => $user->id]);

        $this->assertEquals(\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::APPROVED, $request->status);
        $this->assertNotNull($request->approved_at);
    }

    /** @test */
    public function it_executes_callback_on_approve()
    {
        $flow = ApprovalFlow::create([
            'name' => 'Callback Flow',
            'action_type' => 'callback_flow',
            'is_active' => true
        ]);
        $flow->steps()->create(['level' => 1]);

        $approver = ServiceTestUser::forceCreate(['name' => 'Approver', 'email' => 'cb@example.com', 'password' => 'secret']);
        $model = TestModel::create(['name' => 'Callback Model']);
        $service = new ApprovalService();

        $request = $service->submit($model, ['action_type' => 'callback_flow', 'creator_id' => $approver->id]);

        $callbackFired = false;
        $service->approve($request, $approver, null, function ($req) use (&$callbackFired) {
            $callbackFired = true;
        });

        $this->assertTrue($callbackFired, 'Callback should be executed after approval.');
    }
}

class TestModel extends Model
{
    use HasApprovals;
    protected $guarded = [];
    protected $table = 'test_models';
}

class ServiceTestUser extends AuthUser
{
    protected $table = 'users';
    protected $guarded = [];
}

class TrueCondition implements \Azeem\ApprovalWorkflow\Contracts\ApprovalCondition
{
    public function requiresApproval(\Illuminate\Database\Eloquent\Model $model, array $attributes): bool
    {
        return true;
    }
}

class FalseCondition implements \Azeem\ApprovalWorkflow\Contracts\ApprovalCondition
{
    public function requiresApproval(\Illuminate\Database\Eloquent\Model $model, array $attributes): bool
    {
        return false;
    }
}
