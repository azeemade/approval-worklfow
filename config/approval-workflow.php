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
    */
    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure the notification settings for the approval workflow.
    |
    */
    'notifications' => [
        'enabled' => true,
        'channels' => ['mail'],
        // Options: 'default', or your own view/markdown path
        'theme' => 'default',
        // Whether to queue notifications
        'use_queue' => true,
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
