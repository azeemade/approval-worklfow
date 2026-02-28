<?php

namespace Azeem\ApprovalWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRequest extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'requested_changes' => 'array',
        'removed_approvers' => 'array',
        'status' => \Azeem\ApprovalWorkflow\Enums\ApprovalStatus::class,
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

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
        return config('approval-workflow.tables.approval_requests', parent::getTable());
    }

    public function flow()
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function model()
    {
        return $this->morphTo();
    }

    public function logs()
    {
        return $this->hasMany(ApprovalRequestLog::class);
    }

    public function creator()
    {
        return $this->belongsTo(config('approval-workflow.user_model'), 'creator_id');
    }

    public function currentApprover()
    {
        return $this->belongsTo(config('approval-workflow.user_model'), 'current_approver_id');
    }
}
