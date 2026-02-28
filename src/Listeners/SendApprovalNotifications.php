<?php

namespace Azeem\ApprovalWorkflow\Listeners;

use Azeem\ApprovalWorkflow\Events\ApprovalRequested;
use Azeem\ApprovalWorkflow\Events\RequestApproved;
use Azeem\ApprovalWorkflow\Events\RequestRejected;
use Azeem\ApprovalWorkflow\Notifications\ApprovalRequestedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestApprovedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestRejectedNotification;
use Azeem\ApprovalWorkflow\Events\ChangesRequested;
use Azeem\ApprovalWorkflow\Notifications\ChangesRequestedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendApprovalNotifications implements ShouldQueue
{
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
                $this->sendNotification($users, new ApprovalRequestedNotification($request));
            }
        }
    }

    protected function handleRequestApproved(RequestApproved $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification($request->creator, new RequestApprovedNotification($request));
        }
    }

    protected function handleRequestRejected(RequestRejected $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification($request->creator, new RequestRejectedNotification($request));
        }
    }

    protected function handleChangesRequested(ChangesRequested $event)
    {
        $request = $event->request;

        if ($request->creator) {
            $this->sendNotification($request->creator, new ChangesRequestedNotification($request));
        }
    }

    protected function sendNotification($notifiable, $notification)
    {
        if (config('approval-workflow.notifications.use_queue', true)) {
            Notification::send($notifiable, $notification);
        } else {
            Notification::sendNow($notifiable, $notification);
        }
    }
}
