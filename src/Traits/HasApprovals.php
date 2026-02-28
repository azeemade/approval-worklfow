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
        return $this->approvalRequest?->status === \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->approvalRequest?->status === \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::REJECTED;
    }

    public function isReturned(): bool
    {
        return $this->approvalRequest?->status === \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED;
    }

    public function isSkipped(): bool
    {
        return $this->approvalRequest?->status === \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::SKIPPED;
    }

    /**
     * Submit this model for an approval workflow.
     */
    public function submitForApproval(array $attributes = []): ApprovalRequest
    {
        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->submit($this, $attributes);
    }

    /**
     * Approve the current pending request.
     */
    public function approveRequest($approver, ?string $comment = null, ?callable $callback = null): bool
    {
        $request = $this->approvalRequest()
            ->whereIn('status', [\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED])
            ->firstOrFail();

        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->approve($request, $approver, $comment, $callback);
    }

    /**
     * Reject the current pending request.
     */
    public function rejectRequest($approver, ?string $comment = null, ?callable $callback = null): bool
    {
        $request = $this->approvalRequest()
            ->whereIn('status', [\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED])
            ->firstOrFail();

        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->reject($request, $approver, $comment, $callback);
    }

    /**
     * Request changes for the current pending request.
     */
    public function requestApprovalChanges($approver, ?string $comment = null, array $fields = [], ?callable $callback = null): bool
    {
        $request = $this->approvalRequest()
            ->whereIn('status', [\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED])
            ->firstOrFail();

        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->requestChanges($request, $approver, $comment, $fields, $callback);
    }

    /**
     * Reroute the current pending request from one approver to another.
     */
    public function rerouteApproval($oldApproverId, $newApproverId, $adminUser): bool
    {
        $request = $this->approvalRequest()
            ->whereIn('status', [\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED])
            ->firstOrFail();

        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->reroute($request, $oldApproverId, $newApproverId, $adminUser);
    }

    /**
     * Remove a specific approver from the current pending request.
     */
    public function removeApprover($approverIdToRemove, $adminUser): bool
    {
        $request = $this->approvalRequest()
            ->whereIn('status', [\Azeem\ApprovalWorkflow\Enums\ApprovalStatus::PENDING, \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::RETURNED])
            ->firstOrFail();

        return app(\Azeem\ApprovalWorkflow\Services\ApprovalService::class)->removeApprover($request, $approverIdToRemove, $adminUser);
    }
}
