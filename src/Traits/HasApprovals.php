<?php

namespace Azeem\ApprovalWorkflow\Traits;

use Azeem\ApprovalWorkflow\Models\ApprovalRequest;

trait HasApprovals
{
    /**
     * Get the approval request for the model.
     */
    public function approvalRequest()
    {
        return $this->morphOne(ApprovalRequest::class, 'model');
    }

    /**
     * Get the latest approval request (if multiple allowed/history kept).
     * For now, we assume one active request per model instance, or we just look at the relationship.
     */

    public function isPendingApproval(): bool
    {
        return $this->approvalRequest?->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->approvalRequest?->status === 'rejected';
    }
}
