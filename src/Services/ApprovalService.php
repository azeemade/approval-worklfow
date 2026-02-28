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

            $request = ApprovalRequest::create([
                'approval_flow_id' => $flow->id,
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
                'current_level' => 1,
                'status' => ApprovalStatus::PENDING,
                'creator_id' => $attributes['creator_id'] ?? auth()->id(),
                'metadata' => $attributes['metadata'] ?? null,
                'current_approver_id' => $approverId,
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

            // Log the approval
            $this->logAction($request, $approver->id, 'approved', $comment);

            $removedApprovers = $request->removed_approvers ?? [];

            if ($currentLevel < $totalLevels) {
                // Move to next level, skipping any removed approvers
                $nextLevel = $currentLevel;
                $nextApproverId = null;
                $foundNext = false;

                while ($nextLevel < $totalLevels) {
                    $nextLevel++;
                    $nextStep = $flow->steps()->where('level', $nextLevel)->first();
                    $potentialApproverId = $nextStep ? $nextStep->approver_id : null;

                    if (!in_array($potentialApproverId, $removedApprovers)) {
                        $nextApproverId = $potentialApproverId;
                        $foundNext = true;
                        break;
                    }
                }

                if ($foundNext) {
                    $request->update([
                        'current_level' => $nextLevel,
                        'current_approver_id' => $nextApproverId
                    ]);
                    \Azeem\ApprovalWorkflow\Events\ApprovalRequested::dispatch($request->refresh());
                } else {
                    // All remaining approvers were removed, auto-approve
                    $request->update([
                        'status' => ApprovalStatus::APPROVED,
                        'approved_at' => now(),
                        'current_approver_id' => null,
                    ]);
                    \Azeem\ApprovalWorkflow\Events\RequestApproved::dispatch($request);
                }
            } else {
                // Final approval
                $request->update([
                    'status' => ApprovalStatus::APPROVED,
                    'approved_at' => now(),
                    'current_approver_id' => null,
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

    /**
     * Reroute the request to a new approver.
     *
     * @param ApprovalRequest $request
     * @param mixed $newApproverId
     * @param mixed $adminUser
     * @return bool
     */
    public function reroute(ApprovalRequest $request, $newApproverId, $adminUser): bool
    {
        return DB::transaction(function () use ($request, $newApproverId, $adminUser) {
            $oldApproverId = $request->current_approver_id;

            $request->update(['current_approver_id' => $newApproverId]);

            // Log the reroute
            $this->logAction(
                $request,
                $adminUser->id,
                'rerouted',
                "Rerouted from user {$oldApproverId} to {$newApproverId}"
            );

            // Notify the new approver
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

            if (!in_array($approverIdToRemove, $removedApprovers)) {
                $removedApprovers[] = $approverIdToRemove;

                $request->update([
                    'removed_approvers' => $removedApprovers
                ]);
            }

            $this->logAction($request, $adminUser->id, 'approver_removed', "Removed approver {$approverIdToRemove} from the request");

            \Azeem\ApprovalWorkflow\Events\ApproverRemoved::dispatch($request, $approverIdToRemove);

            // If the person we removed is the CURRENT approver, we need to advance the request
            if ($request->current_approver_id == $approverIdToRemove) {
                // Act as if it was approved to move to the next valid person (or finish)
                // Passing the admin logic to an internal advance method is better, 
                // but for now we can just call approve() programmatically with the admin as the actor
                $this->approve($request, $adminUser, "System Auto-Advance: Current Approver Removed");
            }

            return true;
        });
    }
}
