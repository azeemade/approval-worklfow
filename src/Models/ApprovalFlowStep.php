<?php

namespace Azeem\ApprovalWorkflow\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalFlowStep extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (config('approval-workflow.use_uuid')) {
            $this->incrementing = false;
            $this->keyType = 'string';
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (config('approval-workflow.use_uuid') && empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getTable()
    {
        return config('approval-workflow.tables.approval_flow_steps', parent::getTable());
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function approver()
    {
        return $this->belongsTo(config('approval-workflow.user_model'), 'approver_id');
    }
}
