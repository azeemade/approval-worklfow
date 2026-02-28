<?php

namespace Azeem\ApprovalWorkflow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ApprovalCondition
{
    /**
     * Determine if the approval flow should be triggered.
     *
     * @param Model $model The model being submitted for approval
     * @param array $attributes Additional attributes passed to the submit method
     * @return bool True if approval is required, false to bypass
     */
    public function requiresApproval(Model $model, array $attributes): bool;
}
