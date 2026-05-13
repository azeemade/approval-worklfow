<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Tables
    |--------------------------------------------------------------------------
    |
    | Here you can configure the table names used by the package.
    |
    */
    'tables' => [
        'approval_flows' => 'approval_flows',
        'approval_flow_steps' => 'approval_flow_steps',
        'approval_requests' => 'approval_requests',
        'approval_request_logs' => 'approval_request_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | The model that represents the users in your application.
    |
    */
    'user_model' => App\Models\User::class,

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure the notification settings for the approval workflow.
    |
    | classes: Swap any individual notification class with your own
    |   implementation. Your class must accept an ApprovalRequest as its
    |   first constructor argument. Set a key to null to use the default.
    |
    | You can also override classes at runtime using the Fluent API:
    |   ApprovalWorkflow::useNotification('approval_requested', MyClass::class);
    |   ApprovalWorkflow::useNotificationFor('request_approved', Invoice::class, MyClass::class);
    |
    */
    'notifications' => [
        'enabled'   => true,
        'channels'  => ['mail'],
        // Options: 'default', or your own view/markdown path
        'theme'     => 'default',
        // Whether to queue notifications
        'use_queue' => true,
        // Override individual notification classes (null = use package default)
        'classes'   => [
            'approval_requested' => null,
            'request_approved'   => null,
            'request_rejected'   => null,
            'changes_requested'  => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Use UUIDs
    |--------------------------------------------------------------------------
    |
    | Whether to use UUIDs for primary keys.
    |
    */
    'use_uuid' => false,

    
];
