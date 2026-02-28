# approval-workflow

A flexible, configurable approval workflow package for Laravel. Attach multi-level approval processes to any Eloquent model with full audit logging, event-driven notifications, and team-based configuration.

---

## Features

- **Multi-level Approvals**: Define any number of sequential approval steps.
- **Team-based Workflows** (`team_id`): Run separate flows per team in multi-tenant apps.
- **Action-based Flows**: Distinct workflows per action type (e.g. `expense_report`, `onboarding`).
- **Configurable UUIDs**: Toggle between auto-increment integers or UUIDs as primary keys.
- **Request Metadata**: Pass arbitrary JSON data with a request (e.g. a frontend redirect URL).
- **Approver Tracking**: The model always knows who the `current_approver` is.
- **Approver Rerouting**: Reassign a pending request to a different approver mid-flow.
- **Action Timestamps**: Automatically captures `approved_at` and `rejected_at`.
- **Audit Logs**: Full history of every action with user, action type, and comment.
- **Event-Driven Notifications**: Fires `ApprovalRequested`, `RequestApproved`, `RequestRejected` events. Built-in listeners send notifications.
- **Flexible Notification Channels**: Configure `mail`, `database`, or any other Laravel channel.
- **Sync or Queued Notifications**: Toggle between sync (`sendNow`) and async (queued) dispatch.
- **Notification Themes**: Point to a custom Blade view for fully branded emails.

---

## Installation

```bash
composer require azeem/approval-workflow
```

Publish the config file and migrations:
```bash
php artisan vendor:publish --provider="Azeem\ApprovalWorkflow\ApprovalWorkflowServiceProvider"
```

Run the migrations:
```bash
php artisan migrate
```

---

## Configuration

All settings live in `config/approval-workflow.php`.

```php
return [
    /**
     * Table names - override if you have naming conflicts.
     */
    'tables' => [
        'approval_flows'        => 'approval_flows',
        'approval_flow_steps'   => 'approval_flow_steps',
        'approval_requests'     => 'approval_requests',
        'approval_request_logs' => 'approval_request_logs',
    ],

    /**
     * The User model in your application.
     */
    'user_model' => App\Models\User::class,

    /**
     * Set to true to use UUIDs as primary/foreign keys in all workflow tables.
     * Note: requires re-publishing and re-running migrations.
     */
    'use_uuid' => false,

    /**
     * Notification settings.
     */
    'notifications' => [
        'enabled'   => true,

        // Laravel notification channels: 'mail', 'database', etc.
        'channels'  => ['mail'],

        // 'default' uses built-in MailMessage. Set to a Blade view path for custom themes.
        // e.g. 'emails.approvals.requested'
        'theme'     => 'default',

        // true = notifications are dispatched as queued jobs (recommended for production).
        // false = notifications are sent synchronously (useful for testing / simple setups).
        'use_queue' => true,
    ],
];
```

---

## Usage

### 1. Define an Approval Flow

Create flows via seeders or an admin UI:

```php
use Azeem\ApprovalWorkflow\Models\ApprovalFlow;

$flow = ApprovalFlow::create([
    'name'         => 'Expense Approval',
    'action_type'  => 'expense_report',
    'team_id'      => 1,       // null for non-tenant setups
    'is_active'    => true,
]);

$flow->steps()->create(['level' => 1, 'approver_id' => $managerId]);
$flow->steps()->create(['level' => 2, 'approver_id' => $financeId]);
```

### 2. Submit a Model for Approval

```php
use Azeem\ApprovalWorkflow\Services\ApprovalService;

$service = app(ApprovalService::class);

$request = $service->submit($expense, [
    'action_type' => 'expense_report',
    'creator_id'  => auth()->id(),

    // Optional: metadata is stored as JSON and can include a redirect URL
    // that notifications will use as the "View Request" action link.
    'metadata' => [
        'redirect_url' => route('expenses.show', $expense->id),
        'amount'       => $expense->total,
    ],
]);
```

### 3. Approve a Request

```php
$service->approve($request, $approverUser, 'Looks good!');
```

The package automatically:
- Moves to the **next level** if more steps exist, notifying the next approver.
- Sets `status = approved` and records `approved_at` on the **final approval**.

### 4. Reject a Request

```php
$service->reject($request, $approverUser, 'Receipt is missing.');
```

Sets `status = rejected`, records `rejected_at`, and notifies the creator.

### 5. Reroute a Request

Replace the current approver for any pending request:

```php
$service->reroute($request, $newApproverId, $adminUser);
```

This updates `current_approver_id`, logs the action, and sends a new notification to the replacement approver.

### 6. Request Changes (Return to Creator)

Instead of outright rejecting a request, an approver can ask the creator to modify their submission.

```php
$service->requestChanges($request, $approverUser, 'Please attach the missing receipt', ['receipt_file']);
```

This sets `status = returned` and fires a `ChangesRequestedNotification` to the creator. The optional 4th parameter `['receipt_file']` allows you to store the specific fields that need to be changed in a `requested_changes` JSON column, which your frontend can use to highlight errors.

### 7. Remove an Approver

You can dynamically remove a specific approver from a request without removing them entirely from the workflow template.

```php
$service->removeApprover($request, $approverIdToRemove, $adminUser);
```

If the removed approver is currently the active approver holding up the request, the package will automatically auto-advance the request to the next level. If it was the final level, it will auto-approve.

### 8. Conditional Workflows

You can configure an approval flow to run only if certain conditions are met (e.g., amount > $5000, or specifically tailored business rules). If the condition fails, the request is instantly created as `status = skipped` and processing continues immediately.

**Step 1:** Create a condition class implementing `ApprovalCondition`:
```php
use Azeem\ApprovalWorkflow\Contracts\ApprovalCondition;
use Illuminate\Database\Eloquent\Model;

class HighAmountCondition implements ApprovalCondition
{
    public function requiresApproval(Model $model, array $attributes): bool
    {
        return $attributes['amount'] > 5000;
    }
}
```

**Step 2:** Attach it to your workflow flow when creating it:
```php
$flow = ApprovalFlow::create([
    'name' => 'High Value Expense Approval',
    'action_type' => 'expense_report',
    'condition_class' => HighAmountCondition::class,
    'is_active' => true,
]);
```

When you call `$service->submit(...)`, your condition is automatically evaluated!

---

## Notifications

Notifications are dispatched automatically via events. The built-in `SendApprovalNotifications` listener handles all three events.

| Event | Who is notified |
|---|---|
| `ApprovalRequested` | The current approver (`current_approver_id`) |
| `RequestApproved` | The request creator |
| `RequestRejected` | The request creator |

### Custom Themes

Set `'theme'` to a Blade view path to fully control email content:

```php
// config/approval-workflow.php
'notifications' => [
    'theme' => 'emails.approvals.custom',
],
```

Your view receives `$request` (the `ApprovalRequest` model) and `$notifiable` (the user).

### Adding Custom Channels (e.g. Database, Firebase)

Add channels to the `channels` array. Any standard Laravel notification channel is supported out of the box:

```php
'channels' => ['mail', 'database'],
```

For third-party channels (e.g. `firebase`, `slack`), install the corresponding package and add its channel driver name:

```php
'channels' => ['mail', 'fcm'],
```

Then extend the notification classes to add `toFcm()` or `toSlack()` methods, or publish and override them.

### Sync vs Queued Dispatch

```php
// Async (default, recommended for production - uses Laravel Queue)
'use_queue' => true,

// Sync (fires immediately in the same request cycle)
'use_queue' => false,
```

---

## Events

You can listen to these events in your own application to add custom business logic:

```php
// In your EventServiceProvider
use Azeem\ApprovalWorkflow\Events\ApprovalRequested;
use Azeem\ApprovalWorkflow\Events\RequestApproved;
use Azeem\ApprovalWorkflow\Events\RequestRejected;

Event::listen(RequestApproved::class, function ($event) {
    $expense = $event->request->model;
    $expense->update(['approved' => true]);
});
```

---

## Recommendations

These features are planned or recommended for future versions:

1. **Multiple Approvers per Step**: Support `any` (first to approve) or `all` (everyone must approve) strategies per step.
2. **Role-based Approvers**: Assign steps to roles (e.g. via Spatie Permissions) instead of specific user IDs.
3. **Conditional Step Skipping**: Skip steps based on request attributes (e.g. skip finance step if amount < $50).
4. **Due Dates & Reminders**: Track `due_at` and send reminder notifications via a scheduled job.
5. **Callback on Final Approval**: Register a closure/invokable class in config to run after final approval (e.g. update the model).
6. **Drag-and-Drop Flow Builder UI**: A first-party Filament or Nova resource for managing flows visually.
7. **Model-driven Flow Trigger**: Automatically submit a model on save via a trait, configured via `trigger_type = 'model_save'`.
8. **Audit Trail UI Component**: A Blade/Livewire component to render the approval log timeline.

---

## License

MIT
