<?php

namespace Azeem\ApprovalWorkflow\Services;

use Azeem\ApprovalWorkflow\Models\ApprovalFlow;
use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Azeem\ApprovalWorkflow\Models\ApprovalRequestLog;
use Azeem\ApprovalWorkflow\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Exception;

class ApprovalService
{
    /**
     * Submit a model for approval.
     *
     * @param Model $model The model instance (e.g. Expense)
     * @param array $attributes Additional attributes like 'creator_id', 'action_type'
     * @return ApprovalRequest
     * @throws Exception
     */
    public function submit(Model $model, array $attributes = []): ApprovalRequest
    {
        // 1. Find the appropriate flow
        // The attributes should contain enough info to find the flow (e.g. action_type)
        // Or the model itself might define it. Assume 'action_type' is passed or we default.
        $actionType = $attributes['action_type'] ?? null;
        $teamId = $attributes['team_id'] ?? $model->team_id ?? null;

        if (!$actionType) {
            throw new Exception("Action type must be specified to find an approval flow.");
        }

        $flow = ApprovalFlow::where('action_type', $actionType)
            ->where('is_active', true)
            ->when($teamId, function ($q) use ($teamId) {
                return $q->where('team_id', $teamId);
            })
            ->first();

        if (!$flow) {
            throw new Exception("No active approval flow found for action: {$actionType}");
        }

        // Evaluate conditional trigger if defined
        if ($flow->condition_class) {
            $condition = app($flow->condition_class);
            if (!$condition instanceof \Azeem\ApprovalWorkflow\Contracts\ApprovalCondition) {
                throw new Exception("Condition class {$flow->condition_class} must implement \Azeem\ApprovalWorkflow\Contracts\ApprovalCondition");
            }

            if (!$condition->requiresApproval($model, $attributes)) {
                // Approval is not required, log it as skipped and fire the event
                return DB::transaction(function () use ($model, $flow, $attributes) {
                    $request = ApprovalRequest::create([
                        'approval_flow_id' => $flow->id,
                        'model_type' => get_class($model),
                        'model_id' => $model->getKey(),
                        'current_level' => 1,
                        'status' => ApprovalStatus::SKIPPED,
                        'creator_id' => $attributes['creator_id'] ?? auth()->id(),
                        'metadata' => $attributes['metadata'] ?? null,
                        'current_approver_id' => null,
                    ]);

                    $this->logAction($request, $request->creator_id, 'skipped', 'Approval skipped by condition: ' . class_basename($flow->condition_class));

                    \Azeem\ApprovalWorkflow\Events\ApprovalSkipped::dispatch($request);

                    return $request;
                });
            }
        }

        // 2. Create the request
        return DB::transaction(function () use ($model, $flow, $attributes) {
            $firstStep = $flow->steps->where('level', 1)->first();
            $approverId = $firstStep ? $firstStep->approver_id : null;
            $approversArray = $firstStep ? ($firstStep->approvers ?? []) : [];

            // If the old approver_id is set but approvers array is empty, migrate it on the fly
            if (empty($approversArray) && $approverId) {
                $approversArray = [$approverId];
            }

            $request = ApprovalRequest::create([
                'approval_flow_id' => $flow->id,
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
                'current_level' => 1,
                'status' => ApprovalStatus::PENDING,
                'creator_id' => $attributes['creator_id'] ?? auth()->id(),
                'metadata' => $attributes['metadata'] ?? null,
                'current_approver_id' => $approverId, // Kept for backwards compatibility
                'pending_approvers' => $approversArray,
                'approved_by' => [],
            ]);

            // Log initialization
            $this->logAction($request, $request->creator_id, 'submitted', 'Submitted for approval');

            \Azeem\ApprovalWorkflow\Events\ApprovalRequested::dispatch($request);

            return $request;
        });
    }

    /**
     * Approve the request at the current level.
     */
    public function approve(ApprovalRequest $request, $approver, ?string $comment = null): bool
    {
        return DB::transaction(function () use ($request, $approver, $comment) {
            // Verify if user is allowed to approve (TODO: Role/User check against step)

            $currentLevel = $request->current_level;
            $flow = $request->flow;
            $totalLevels = $flow->steps()->count();
            $currentStep = $flow->steps()->where('level', $currentLevel)->first();

            // Log the approval
            $this->logAction($request, $approver->id, 'approved', $comment);

            // Update pending vs approved arrays
            $pendingApprovers = $request->pending_approvers ?? [];
            $approvedBy = $request->approved_by ?? [];

            // Remove approver from pending and add to approved_by
            $pendingApprovers = array_values(array_diff($pendingApprovers, [$approver->id]));
            if (!in_array($approver->id, $approvedBy)) {
                $approvedBy[] = $approver->id;
            }

            $request->update([
                'pending_approvers' => $pendingApprovers,
                'approved_by' => $approvedBy
            ]);

            $strategy = $currentStep ? ($currentStep->strategy ?? 'any') : 'any';

            // Check if we should advance to the next level
            $shouldAdvance = false;

            if ($strategy === 'any') {
                $shouldAdvance = true;
            } elseif ($strategy === 'all') {
                $shouldAdvance = empty($pendingApprovers);
            }

            if (!$shouldAdvance) {
                // We don't advance yet, still waiting on others.
                return true;
            }

            $removedApprovers = $request->removed_approvers ?? [];

            if ($currentLevel < $totalLevels) {
                // Move to next level, skipping any removed approvers
                $nextLevel = $currentLevel;
                $nextApproverId = null;
                $nextPendingApprovers = [];
                $foundNext = false;

                while ($nextLevel < $totalLevels) {
                    $nextLevel++;
                    $nextStep = $flow->steps()->where('level', $nextLevel)->first();
                    $potentialApproverId = $nextStep ? $nextStep->approver_id : null;
                    $potentialApproversArray = $nextStep ? ($nextStep->approvers ?? []) : [];

                    // Backwards compat check
                    if (empty($potentialApproversArray) && $potentialApproverId) {
                        $potentialApproversArray = [$potentialApproverId];
                    }

                    // Remove any approvers that were explicitly removed dynamically via removeApprover()
                    $validPending = array_values(array_diff($potentialApproversArray, $removedApprovers));

                    // Even if approver_id was null (e.g. role-based), we should stop searching and just set it up
                    // if there are approvers, or if it's explicitly null implying "any in role"
                    if (!empty($validPending) || !$potentialApproverId) {
                        $nextApproverId = $potentialApproverId;
                        $nextPendingApprovers = $validPending;
                        $foundNext = true;
                        break;
                    }
                }

                if ($foundNext) {
                    $request->update([
                        'current_level' => $nextLevel,
                        'current_approver_id' => $nextApproverId, // Backwards usage
                        'pending_approvers' => $nextPendingApprovers,
                        'approved_by' => []
                    ]);
                    \Azeem\ApprovalWorkflow\Events\ApprovalRequested::dispatch($request->refresh());
                } else {
                    // All remaining approvers were removed, auto-approve
                    $request->update([
                        'status' => ApprovalStatus::APPROVED,
                        'approved_at' => now(),
                        'current_approver_id' => null,
                        'pending_approvers' => [],
                    ]);
                    \Azeem\ApprovalWorkflow\Events\RequestApproved::dispatch($request);
                }
            } else {
                // Final approval
                $request->update([
                    'status' => ApprovalStatus::APPROVED,
                    'approved_at' => now(),
                    'current_approver_id' => null,
                    'pending_approvers' => [],
                ]);
                \Azeem\ApprovalWorkflow\Events\RequestApproved::dispatch($request);
            }

            return true;
        });
    }

    /**
     * Reject the request.
     */
    public function reject(ApprovalRequest $request, $approver, ?string $comment = null): bool
    {
        return DB::transaction(function () use ($request, $approver, $comment) {
            $request->update([
                'status' => ApprovalStatus::REJECTED,
                'rejected_at' => now(),
                'current_approver_id' => null,
            ]);

            $this->logAction($request, $approver->id, 'rejected', $comment);

            \Azeem\ApprovalWorkflow\Events\RequestRejected::dispatch($request);

            return true;
        });
    }

    public function reroute(ApprovalRequest $request, $oldApproverId, $newApproverId, $adminUser): bool
    {
        return DB::transaction(function () use ($request, $oldApproverId, $newApproverId, $adminUser) {
            $pendingApprovers = $request->pending_approvers ?? [];

            // If old approver isn't in the pending list but is the legacy current_approver_id...
            if (!in_array($oldApproverId, $pendingApprovers) && $request->current_approver_id == $oldApproverId) {
                $pendingApprovers[] = $oldApproverId;
            }

            if (!in_array($oldApproverId, $pendingApprovers)) {
                throw new Exception("User {$oldApproverId} is not a pending approver for this request.");
            }

            // Remove old, add new
            $pendingApprovers = array_values(array_diff($pendingApprovers, [$oldApproverId]));
            if (!in_array($newApproverId, $pendingApprovers)) {
                $pendingApprovers[] = $newApproverId;
            }

            $updateData = ['pending_approvers' => $pendingApprovers];

            // Maintain backwards compatibility for single-approver logic
            if ($request->current_approver_id == $oldApproverId) {
                $updateData['current_approver_id'] = $newApproverId;
            }

            $request->update($updateData);

            // Log the reroute
            $this->logAction(
                $request,
                $adminUser->id,
                'rerouted',
                "Rerouted from user {$oldApproverId} to {$newApproverId}"
            );

            // Notify the new approver
            // Note: ApprovalRequested specifically notifies $request->pending_approvers
            \Azeem\ApprovalWorkflow\Events\ApprovalRequested::dispatch($request->refresh());

            return true;
        });
    }

    protected function logAction(ApprovalRequest $request, $userId, $action, $comment = null)
    {
        ApprovalRequestLog::create([
            'approval_request_id' => $request->id,
            'user_id' => $userId,
            'action' => $action,
            'comment' => $comment
        ]);
    }

    /**
     * Request changes from the creator.
     */
    public function requestChanges(ApprovalRequest $request, $approver, ?string $comment = null, array $fields = []): bool
    {
        return DB::transaction(function () use ($request, $approver, $comment, $fields) {
            $request->update([
                'status' => ApprovalStatus::RETURNED,
                'requested_changes' => $fields,
            ]);

            $this->logAction($request, $approver->id, 'returned', $comment);

            \Azeem\ApprovalWorkflow\Events\ChangesRequested::dispatch($request);

            return true;
        });
    }

    /**
     * Remove a specific approver from a specific request.
     */
    public function removeApprover(ApprovalRequest $request, $approverIdToRemove, $adminUser): bool
    {
        return DB::transaction(function () use ($request, $approverIdToRemove, $adminUser) {
            $removedApprovers = $request->removed_approvers ?? [];
            $pendingApprovers = $request->pending_approvers ?? [];

            if (!in_array($approverIdToRemove, $removedApprovers)) {
                $removedApprovers[] = $approverIdToRemove;
            }

            // Remove from pending immediately
            $pendingApprovers = array_values(array_diff($pendingApprovers, [$approverIdToRemove]));

            $request->update([
                'removed_approvers' => $removedApprovers,
                'pending_approvers' => $pendingApprovers
            ]);

            $this->logAction($request, $adminUser->id, 'approver_removed', "Removed approver {$approverIdToRemove} from the request");

            \Azeem\ApprovalWorkflow\Events\ApproverRemoved::dispatch($request, $approverIdToRemove);

            $currentLevel = $request->current_level;
            $flow = $request->flow;
            $currentStep = $flow->steps()->where('level', $currentLevel)->first();
            $strategy = $currentStep ? ($currentStep->strategy ?? 'any') : 'any';

            $shouldAutoAdvance = false;

            // If the person we removed is the CURRENT legacy approver (backward compat check)...
            if ($request->current_approver_id == $approverIdToRemove) {
                $shouldAutoAdvance = true;
            }

            // Or if strategy is ALL, and removing this person empties the pending queue...
            if ($strategy === 'all' && empty($pendingApprovers)) {
                $shouldAutoAdvance = true;
            }

            if ($shouldAutoAdvance) {
                // Act as if it was approved to move to the next valid person (or finish)
                // Passing the admin logic to an internal advance method is better, 
                // but for now we can just call approve() programmatically with the admin as the actor
                $this->approve($request, $adminUser, "System Auto-Advance: Approver Removed");
            }

            return true;
        });
    }
}
