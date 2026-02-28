<?php

namespace Azeem\ApprovalWorkflow;

use Illuminate\Support\ServiceProvider;

class ApprovalWorkflowServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/approval-workflow.php' => config_path('approval-workflow.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        \Illuminate\Support\Facades\Event::listen(
            \Azeem\ApprovalWorkflow\Events\ApprovalRequested::class,
            \Azeem\ApprovalWorkflow\Listeners\SendApprovalNotifications::class
        );

        \Illuminate\Support\Facades\Event::listen(
            \Azeem\ApprovalWorkflow\Events\RequestApproved::class,
            \Azeem\ApprovalWorkflow\Listeners\SendApprovalNotifications::class
        );

        \Illuminate\Support\Facades\Event::listen(
            \Azeem\ApprovalWorkflow\Events\RequestRejected::class,
            \Azeem\ApprovalWorkflow\Listeners\SendApprovalNotifications::class
        );
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/approval-workflow.php',
            'approval-workflow'
        );
    }
}
