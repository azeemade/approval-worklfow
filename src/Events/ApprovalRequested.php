<?php

namespace Azeem\ApprovalWorkflow\Events;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalRequested
{
    use Dispatchable, SerializesModels;

    public $request;

    /**
     * Create a new event instance.
     *
     * @param  ApprovalRequest  $request
     * @return void
     */
    public function __construct(ApprovalRequest $request)
    {
        $this->request = $request;
    }
}
