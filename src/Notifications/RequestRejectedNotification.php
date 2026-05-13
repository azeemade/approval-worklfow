<?php

namespace Azeem\ApprovalWorkflow\Notifications;

/**
 * Thin wrapper around BaseRequestRejectedNotification.
 *
 * Extend BaseRequestRejectedNotification directly if you need to customise
 * the notification, or swap this class out entirely via:
 *
 *   config: approval-workflow.notifications.classes.request_rejected
 *   fluent: ApprovalWorkflow::useNotification('request_rejected', MyClass::class)
 */
class RequestRejectedNotification extends BaseRequestRejectedNotification {}
