<?php

namespace Azeem\ApprovalWorkflow\Services;

use Azeem\ApprovalWorkflow\Models\ApprovalFlow;
use Azeem\ApprovalWorkflow\Models\ApprovalRequest;
use Azeem\ApprovalWorkflow\Models\ApprovalRequestLog;
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

        // 2. Create the request
        return DB::transaction(function () use ($model, $flow, $attributes) {
            $firstStep = $flow->steps->where('level', 1)->first();
            $approverId = $firstStep ? $firstStep->approver_id : null;

            $request = ApprovalRequest::create([
                'approval_flow_id' => $flow->id,
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
                'current_level' => 1,
                'status' => 'pending',
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

            if ($currentLevel < $totalLevels) {
                // Move to next level
                $nextLevel = $currentLevel + 1;
                $request->increment('current_level');

                // Update current approver logic
                $nextStep = $flow->steps()->where('level', $nextLevel)->first();
                $nextApproverId = $nextStep ? $nextStep->approver_id : null;
                $request->update(['current_approver_id' => $nextApproverId]);

                \Azeem\ApprovalWorkflow\Events\ApprovalRequested::dispatch($request->refresh());
            } else {
                // Final approval
                $request->update([
                    'status' => 'approved',
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
                'status' => 'rejected',
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
}
