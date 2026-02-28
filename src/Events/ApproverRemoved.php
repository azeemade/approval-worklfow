<?php

namespace Azeem\ApprovalWorkflow\Events;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApproverRemoved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $request;
    public $removedApproverId;

    /**
     * Create a new event instance.
     *
     * @param  ApprovalRequest  $request
     * @param  mixed  $removedApproverId
     * @return void
     */
    public function __construct(ApprovalRequest $request, $removedApproverId)
    {
        $this->request = $request;
        $this->removedApproverId = $removedApproverId;
    }
}
