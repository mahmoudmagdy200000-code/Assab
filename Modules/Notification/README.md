# Notification System Module

## Overview

A comprehensive notification system for the ASSAB application that supports multiple channels (In-App, Email, SMS), role-based preferences, and real-time delivery.

## Features

- **Multi-Channel Support**: In-App, Email, and SMS notifications
- **Role-Based Preferences**: Default notification settings per role (Branch Manager, Cashier, Supplier, Brand Owner)
- **Priority Levels**: Low, Medium, High, Critical
- **Notification Categories**: Operational, Financial, Compliance, System
- **Rate Limiting**: SMS rate limiting (max 10 per user per day)
- **Event-Driven**: Automatically triggered by system events
- **Queue Support**: All notifications are queued for better performance

## Architecture

```
Controller → Service → Repository → Model
     ↓
Events & Listeners
```

### Key Components

1. **NotificationService**: Main service for sending notifications
2. **ChannelService**: Handles multi-channel delivery
3. **NotificationPreferenceService**: Manages user preferences
4. **SMS/Email Channels**: External channel implementations

## Notification Types

### Operational Notifications

- Shift Management: Start reminders, overdue alerts, end reminders, handover notifications
- Sales & Payment: Cash variance alerts, payment discrepancies
- Purchase Orders: Order created, status changed, variance detected

### Financial Notifications

- Expense Management: Submitted, approved, rejected, pre-approval requests
- Custody Management: Request status, low balance warnings, cash transfers
- Compliance: VAT deadlines, tax documents, audit trails

## Installation

1. Run migrations:
```bash
php artisan migrate
```

2. Publish config (optional):
```bash
php artisan vendor:publish --tag=notification-config
```

3. Configure SMS provider in `.env`:
```env
SMS_PROVIDER=stc
STC_SMS_API_URL=your_api_url
STC_SMS_API_KEY=your_api_key
STC_SMS_SENDER_ID=your_sender_id
```

## Usage

### Sending Notifications

```php
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Enums\NotificationPriority;

// Inject service
public function __construct(
    private NotificationServiceInterface $notificationService
) {}

// Send notification
$this->notificationService->send(
    $user,
    NotificationType::EXPENSE_APPROVED,
    ['expense_id' => $expense->id, 'amount' => 1000],
    NotificationPriority::MEDIUM
);
```

### Managing Preferences

```php
use Modules\Notification\Services\NotificationPreferenceService;

// Initialize defaults for new user
$preferenceService->initializeDefaults($user, 'branch_manager');

// Update preference
$preferenceService->updatePreference(
    $user,
    NotificationType::SHIFT_START_REMINDER,
    ['app', 'email'],
    'medium',
    true
);
```

## API Endpoints

### Notifications

- `GET /api/notifications` - Get all notifications
- `GET /api/notifications/unread` - Get unread notifications
- `GET /api/notifications/{id}` - Get specific notification
- `POST /api/notifications/{id}/read` - Mark as read
- `POST /api/notifications/read-all` - Mark all as read
- `DELETE /api/notifications/{id}` - Delete notification

### Preferences

- `GET /api/notification-preferences` - Get all preferences
- `POST /api/notification-preferences` - Create/update preference
- `PUT /api/notification-preferences/{id}` - Update preference
- `DELETE /api/notification-preferences/{id}` - Delete preference

## Event Integration

The notification system is automatically connected to events in:

- **Shift Module**: `ShiftEndedEvent`
- **Expense Module**: `ExpenseSubmittedEvent`, `ExpenseApprovedEvent`, `ExpenseRejectedEvent`
- **Custody Module**: `HandoverApproved`
- **Purchase Module**: `OrderCreated`, `OrderStatusChanged`, `VarianceDetected`

## Role-Based Defaults

### Branch Manager
- All operational notifications enabled
- Financial alerts: High priority only
- Email summaries: Daily and weekly

### Cashier
- Shift-related notifications only
- Handover alerts: All
- Sales variance: High priority
- SMS: Critical alerts only

### Supplier
- Order management: All notifications
- Payment alerts: Medium and high
- Performance metrics: Weekly summary

### Brand Owner
- Financial summaries: All
- Performance alerts: High and critical
- Compliance notifications: All
- Operational issues: Medium and high

## Performance Considerations

- All notifications are queued for async processing
- SMS rate limiting prevents abuse (10 per day per user)
- Database indexes on notification preferences for fast lookups
- Eager loading relationships when sending to multiple users

## Configuration

See `config/notification.php` for:
- SMS provider settings (STC, Mobily, Zain)
- Email settings
- In-app notification settings
- Delivery timeouts

## Testing

```bash
php artisan test --filter Notification
```

## Security

- User preferences are scoped to authenticated users
- SMS rate limiting prevents spam
- Email notifications include unsubscribe options
- All notifications are logged for audit purposes

## Future Enhancements

- Push notifications for mobile apps
- WhatsApp integration
- Notification templates
- Scheduled notifications
- Notification analytics dashboard

