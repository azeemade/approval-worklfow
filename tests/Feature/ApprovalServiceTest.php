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
            'status' => 'pending',
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
        $this->assertEquals('pending', $request->fresh()->status);

        // Approve Level 2 (Final)
        $service->approve($request->fresh(), $user);

        $this->assertEquals(2, $request->fresh()->current_level); // stays at max or we could check status
        $this->assertEquals('approved', $request->fresh()->status);
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

        $this->assertEquals('pending', $request->status);
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

        $this->assertEquals('skipped', $request->status);
        $this->assertNull($request->current_approver_id);

        \Illuminate\Support\Facades\Event::assertDispatched(\Azeem\ApprovalWorkflow\Events\ApprovalSkipped::class);
        $this->assertDatabaseHas('approval_request_logs', [
            'approval_request_id' => $request->id,
            'action' => 'skipped',
        ]);
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
