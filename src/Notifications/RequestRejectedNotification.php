<?php

namespace Azeem\ApprovalWorkflow\Notifications;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $request;

    /**
     * Create a new notification instance.
     *
     * @param ApprovalRequest $request
     */
    public function __construct(ApprovalRequest $request)
    {
        $this->request = $request;
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
        $metadata = $this->request->metadata ?? [];
        $actionUrl = $metadata['redirect_url'] ?? url('/approval-requests/' . $this->request->id);

        $mail = (new MailMessage)
            ->subject('Request Rejected')
            ->line('Your request has been rejected.')
            ->line('Type: ' . $this->request->model_type)
            ->line('Status: ' . $this->request->status->value)
            ->action('View Request', $actionUrl);

        if ($theme && $theme !== 'default') {
            $mail->view($theme, ['request' => $this->request, 'notifiable' => $notifiable]);
        }

        return $mail;
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
            'request_id' => $this->request->id,
            'message' => 'Request rejected.',
        ];
    }
}
