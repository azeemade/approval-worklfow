<?php

namespace Azeem\ApprovalWorkflow\Events;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a rejection is recorded but the rejection_threshold has NOT yet been met.
 * The request stays PENDING; the rejecting approver is removed from pending_approvers.
 */
class RejectionRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ApprovalRequest $request,
        public readonly mixed $rejectorId,
        public readonly int $rejectedCount,
        public readonly int $rejectionThreshold
    ) {}
}
