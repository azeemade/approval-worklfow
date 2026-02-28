<?php

namespace Azeem\ApprovalWorkflow\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequestLog extends Model
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
        return config('approval-workflow.tables.approval_request_logs', parent::getTable());
    }

    public function request()
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function user()
    {
        return $this->belongsTo(config('approval-workflow.user_model'), 'user_id');
    }
}
