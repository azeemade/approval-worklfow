<?php

namespace Azeem\ApprovalWorkflow\Listeners;

use Azeem\ApprovalWorkflow\ApprovalWorkflow;
use Azeem\ApprovalWorkflow\Events\ApprovalRequested;
use Azeem\ApprovalWorkflow\Events\ChangesRequested;
use Azeem\ApprovalWorkflow\Events\RequestApproved;
use Azeem\ApprovalWorkflow\Events\RequestRejected;
use Azeem\ApprovalWorkflow\Notifications\ApprovalRequestedNotification;
use Azeem\ApprovalWorkflow\Notifications\ChangesRequestedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestApprovedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class SendApprovalNotifications implements ShouldQueue
{
    /**
     * Package-default notification classes, keyed by event key.
     */
    private const DEFAULT_CLASSES = [
        'approval_requested' => ApprovalRequestedNotification::class,
        'request_approved'   => RequestApprovedNotification::class,
        'request_rejected'   => RequestRejectedNotification::class,
        'changes_requested'  => ChangesRequestedNotification::class,
    ];

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle($event)
    {
        if (!config('approval-workflow.notifications.enabled', true)) {
            return;
        }

        if ($event instanceof ApprovalRequested) {
            $this->handleApprovalRequested($event);
        } elseif ($event instanceof RequestApproved) {
            $this->handleRequestApproved($event);
        } elseif ($event instanceof RequestRejected) {
            $this->handleRequestRejected($event);
        } elseif ($event instanceof ChangesRequested) {
            $this->handleChangesRequested($event);
        }
    }

    protected function handleApprovalRequested(ApprovalRequested $event)
    {
        $request = $event->request;

        $pendingApproverIds = $request->pending_approvers ?? [];

        // Backwards compatibility check
        if (empty($pendingApproverIds) && $request->current_approver_id) {
            $pendingApproverIds = [$request->current_approver_id];
        }

        if (empty($pendingApproverIds)) {
            // Fallback to flow step logic (mainly for backward compat or if not set)
            $flow = $request->flow;
            $step = $flow->steps->where('level', $request->current_level)->first();
            if ($step && $step->approver_id) {
                $pendingApproverIds = [$step->approver_id];
            }
        }

        if (!empty($pendingApproverIds)) {
            $userModelClass = config('approval-workflow.user_model');
            $users = (new $userModelClass)->whereIn('id', $pendingApproverIds)->get();

            if ($users->isNotEmpty()) {
                $this->sendNotification(
                    $users,
                    $this->resolveNotification('approval_requested', $request)
                );
            }
        }
    }

    protected function handleRequestApproved(RequestApproved $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification(
                $request->creator,
                $this->resolveNotification('request_approved', $request)
            );
        }
    }

    protected function handleRequestRejected(RequestRejected $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification(
                $request->creator,
                $this->resolveNotification('request_rejected', $request)
            );
        }
    }

    protected function handleChangesRequested(ChangesRequested $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification(
                $request->creator,
                $this->resolveNotification('changes_requested', $request)
            );
        }
    }

    /**
     * Resolve the notification instance for a given key and approval request.
     *
     * Resolution priority:
     *   1. Per-model fluent override  (ApprovalWorkflow::useNotificationFor)
     *   2. Global fluent override     (ApprovalWorkflow::useNotification)
     *   3. Config override            (notifications.classes.*)
     *   4. Package default
     *
     * @param  string  $key
     * @param  \Azeem\ApprovalWorkflow\Models\ApprovalRequest  $request
     * @return \Illuminate\Notifications\Notification
     */
    protected function resolveNotification(string $key, $request): Notification
    {
        $modelType = $request->model_type ?? null;

        $class = ApprovalWorkflow::resolveNotificationClass($key, $modelType)
            ?? self::DEFAULT_CLASSES[$key];

        return new $class($request);
    }

    protected function sendNotification($notifiable, $notification)
    {
        if (config('approval-workflow.notifications.use_queue', true)) {
            NotificationFacade::send($notifiable, $notification);
        } else {
            NotificationFacade::sendNow($notifiable, $notification);
        }
    }
}
