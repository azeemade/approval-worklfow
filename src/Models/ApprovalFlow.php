<?php

namespace Azeem\ApprovalWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalFlow extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
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
        return config('approval-workflow.tables.approval_flows', parent::getTable());
    }

    public function steps()
    {
        return $this->hasMany(ApprovalFlowStep::class)->orderBy('level');
    }

    public function requests()
    {
        return $this->hasMany(ApprovalRequest::class);
    }
}
