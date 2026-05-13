<?php

namespace Azeem\ApprovalWorkflow\Notifications;

/**
 * Thin wrapper around BaseApprovalRequestedNotification.
 *
 * Extend BaseApprovalRequestedNotification directly if you need to customise
 * the notification, or swap this class out entirely via:
 *
 *   config: approval-workflow.notifications.classes.approval_requested
 *   fluent: ApprovalWorkflow::useNotification('approval_requested', MyClass::class)
 */
class ApprovalRequestedNotification extends BaseApprovalRequestedNotification {}
