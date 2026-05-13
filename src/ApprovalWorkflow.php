<?php

namespace Azeem\ApprovalWorkflow;

/**
 * Fluent API for customising approval workflow notification classes at runtime.
 *
 * Register overrides in your AppServiceProvider::boot():
 *
 *   // Global override — used for all models
 *   ApprovalWorkflow::useNotification('approval_requested', MyApprovalNotification::class);
 *
 *   // Per-model override — used only when the request's model_type matches
 *   ApprovalWorkflow::useNotificationFor('request_approved', Invoice::class, InvoiceApprovedNotification::class);
 *
 * Resolution priority (highest → lowest):
 *   1. Per-model fluent override (useNotificationFor)
 *   2. Global fluent override    (useNotification)
 *   3. Config override           (notifications.classes.*)
 *   4. Package default
 *
 * Valid notification keys: approval_requested, request_approved, request_rejected, changes_requested
 */
class ApprovalWorkflow
{
    /**
     * Global notification class overrides keyed by notification key.
     *
     * @var array<string, class-string>
     */
    protected static array $overrides = [];

    /**
     * Per-model notification class overrides.
     * Structure: ['notification_key' => ['ModelClass' => 'NotificationClass']]
     *
     * @var array<string, array<class-string, class-string>>
     */
    protected static array $modelOverrides = [];

    /**
     * Override the notification class used for a given event, for all models.
     *
     * @param  string  $key              One of: approval_requested, request_approved, request_rejected, changes_requested
     * @param  class-string  $class      Fully-qualified notification class name
     */
    public static function useNotification(string $key, string $class): void
    {
        static::$overrides[$key] = $class;
    }

    /**
     * Override the notification class used for a given event, scoped to a specific model type.
     *
     * @param  string  $key              One of: approval_requested, request_approved, request_rejected, changes_requested
     * @param  class-string  $modelClass Fully-qualified model class name (e.g. App\Models\Invoice::class)
     * @param  class-string  $class      Fully-qualified notification class name
     */
    public static function useNotificationFor(string $key, string $modelClass, string $class): void
    {
        static::$modelOverrides[$key][$modelClass] = $class;
    }

    /**
     * Resolve the notification class for a given key and optional model type.
     *
     * Resolution order:
     *   1. Per-model fluent override
     *   2. Global fluent override
     *   3. Config override
     *   4. null (caller should fall back to the package default)
     *
     * @param  string       $key
     * @param  string|null  $modelType  The model_type string stored on the ApprovalRequest
     * @return class-string|null
     */
    public static function resolveNotificationClass(string $key, ?string $modelType = null): ?string
    {
        // 1. Per-model fluent override
        if ($modelType && isset(static::$modelOverrides[$key][$modelType])) {
            return static::$modelOverrides[$key][$modelType];
        }

        // 2. Global fluent override
        if (isset(static::$overrides[$key])) {
            return static::$overrides[$key];
        }

        // 3. Config override
        $configClass = config("approval-workflow.notifications.classes.{$key}");
        if ($configClass) {
            return $configClass;
        }

        return null;
    }

    /**
     * Reset all fluent overrides (useful in tests).
     */
    public static function reset(): void
    {
        static::$overrides      = [];
        static::$modelOverrides = [];
    }
}
