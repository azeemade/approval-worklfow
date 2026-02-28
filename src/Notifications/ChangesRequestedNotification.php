<?php

namespace Azeem\ApprovalWorkflow\Notifications;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangesRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $approvalRequest;

    /**
     * Create a new notification instance.
     *
     * @param ApprovalRequest $approvalRequest
     */
    public function __construct(ApprovalRequest $approvalRequest)
    {
        $this->approvalRequest = $approvalRequest;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return config('approval-workflow.notifications.channels', ['mail']);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $theme = config('approval-workflow.notifications.theme', 'default');

        $fields = $this->approvalRequest->requested_changes ?? [];
        $fieldsText = empty($fields) ? '' : 'Please update the following fields: ' . implode(', ', $fields) . '.';

        if ($theme !== 'default' && view()->exists($theme)) {
            return (new MailMessage)->view($theme, [
                'request' => $this->approvalRequest,
                'notifiable' => $notifiable,
                'type' => 'changes_requested'
            ]);
        }

        return (new MailMessage)
            ->subject("Changes Requested: Approval Workflow")
            ->line("The approver has requested changes to your {$this->approvalRequest->model_type} submission.")
            ->line($fieldsText)
            ->line('Please review the request, make the necessary adjustments, and resubmit.')
            ->action('View Request', url('/'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'approval_request_id' => $this->approvalRequest->id,
            'model_type' => $this->approvalRequest->model_type,
            'requested_changes' => $this->approvalRequest->requested_changes,
        ];
    }
}
