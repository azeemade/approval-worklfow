<?php

namespace Azeem\ApprovalWorkflow\Notifications;

/**
 * Thin wrapper around BaseRequestApprovedNotification.
 *
 * Extend BaseRequestApprovedNotification directly if you need to customise
 * the notification, or swap this class out entirely via:
 *
 *   config: approval-workflow.notifications.classes.request_approved
 *   fluent: ApprovalWorkflow::useNotification('request_approved', MyClass::class)
 */
class RequestApprovedNotification extends BaseRequestApprovedNotification {}
