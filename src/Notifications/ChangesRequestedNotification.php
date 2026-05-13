<?php

namespace Azeem\ApprovalWorkflow\Notifications;

/**
 * Thin wrapper around BaseChangesRequestedNotification.
 *
 * Extend BaseChangesRequestedNotification directly if you need to customise
 * the notification, or swap this class out entirely via:
 *
 *   config: approval-workflow.notifications.classes.changes_requested
 *   fluent: ApprovalWorkflow::useNotification('changes_requested', MyClass::class)
 */
class ChangesRequestedNotification extends BaseChangesRequestedNotification {}
