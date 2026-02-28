<?php

namespace Azeem\ApprovalWorkflow\Listeners;

use Azeem\ApprovalWorkflow\Events\ApprovalRequested;
use Azeem\ApprovalWorkflow\Events\RequestApproved;
use Azeem\ApprovalWorkflow\Events\RequestRejected;
use Azeem\ApprovalWorkflow\Notifications\ApprovalRequestedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestApprovedNotification;
use Azeem\ApprovalWorkflow\Notifications\RequestRejectedNotification;
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
        }
    }

    protected function handleApprovalRequested(ApprovalRequested $event)
    {
        $request = $event->request;

        // Use the current_approver relationship if set
        $approver = $request->currentApprover;

        if (!$approver) {
            // Fallback to flow step logic (mainly for backward compat or if not set)
            $flow = $request->flow;
            $step = $flow->steps->where('level', $request->current_level)->first();
            $approver = $step ? $step->approver : null;
        }

        if ($approver) {
            $this->sendNotification($approver, new ApprovalRequestedNotification($request));
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

    protected function sendNotification($notifiable, $notification)
    {
        if (config('approval-workflow.notifications.use_queue', true)) {
            Notification::send($notifiable, $notification);
        } else {
            Notification::sendNow($notifiable, $notification);
        }
    }
}
