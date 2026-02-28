<?php

namespace Azeem\ApprovalWorkflow\Tests\Feature;

use Azeem\ApprovalWorkflow\Models\ApprovalFlow;
use Azeem\ApprovalWorkflow\Services\ApprovalService;
use Azeem\ApprovalWorkflow\Tests\TestCase;
use Azeem\ApprovalWorkflow\Notifications\ApprovalRequestedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestApprovedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Config;

class TestUser extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'users';
    protected $guarded = [];
}

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('approval-workflow.user_model', TestUser::class);
    }

    /** @test */
    public function it_sends_notification_to_approver_when_request_is_submitted()
    {
        Notification::fake();

        $user = TestUser::create(['name' => 'Creator', 'email' => 'creator@example.com', 'password' => 'password']);
        $approver = TestUser::create(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'password']);

        $flow = ApprovalFlow::create(['name' => 'Test Flow', 'action_type' => 'test_action', 'is_active' => true]);
        $flow->steps()->create(['level' => 1, 'approver_id' => $approver->id]);

        $service = new ApprovalService();

        $service->submit($flow, [
            'action_type' => 'test_action',
            'creator_id' => $user->id,
        ]);

        Notification::assertSentTo(
            [$approver],
            ApprovalRequestedNotification::class
        );
    }

    /** @test */
    public function it_sends_notification_to_creator_when_request_is_approved()
    {
        Notification::fake();

        $user = TestUser::create(['name' => 'Creator', 'email' => 'creator@example.com', 'password' => 'password']);
        $approver = TestUser::create(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'password']);

        $flow = ApprovalFlow::create(['name' => 'Test Flow', 'action_type' => 'test_action', 'is_active' => true]);
        $flow->steps()->create(['level' => 1, 'approver_id' => $approver->id]);

        $service = new ApprovalService();
        $request = $service->submit($flow, [
            'action_type' => 'test_action',
            'creator_id' => $user->id,
        ]);

        $service->approve($request, $approver);

        Notification::assertSentTo(
            [$user],
            RequestApprovedNotification::class
        );
    }

    /** @test */
    public function it_sends_notification_to_creator_when_request_is_rejected()
    {
        Notification::fake();

        $user = TestUser::create(['name' => 'Creator', 'email' => 'creator@example.com', 'password' => 'password']);
        $approver = TestUser::create(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'password']);

        $flow = ApprovalFlow::create(['name' => 'Test Flow', 'action_type' => 'test_action', 'is_active' => true]);
        $flow->steps()->create(['level' => 1, 'approver_id' => $approver->id]);

        $service = new ApprovalService();
        $request = $service->submit($flow, [
            'action_type' => 'test_action',
            'creator_id' => $user->id,
        ]);

        $service->reject($request, $approver);

        Notification::assertSentTo(
            [$user],
            RequestRejectedNotification::class
        );
    }
}
