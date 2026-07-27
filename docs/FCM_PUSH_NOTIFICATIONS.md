# Real-time notifications — FCM push

Device push for every user of the system, across both worlds (ASAB dashboard and
the legacy mobile backend) and every module.

**Base URL:** `/api/v1` · **Auth:** `Authorization: Bearer <sanctum token>` ·
**Locale:** `Accept-Language: ar | en`

---

## 1. Two transports, on purpose

| | Pusher broadcast | FCM |
|---|---|---|
| Reaches | a client that is **open right now** | a **backgrounded or killed** app |
| Channel | `private-user.{id}`, event `notification.received` | OS notification tray |
| Needs | an active websocket | a registered device token |
| Config | `BROADCAST_CONNECTION` | `FCM_DRIVER` |

Both fire for the same notification. A web dashboard that is open updates its
badge over Pusher; the phone in the user's pocket buzzes over FCM. Neither
replaced the other.

---

## 2. Backend setup

### 2.1 Firebase project

1. Firebase console → **Project settings → Service accounts → Generate new
   private key**. You get a JSON file.
2. Put it somewhere **outside version control**, e.g.
   `storage/app/firebase/service-account.json` (already git-ignored).
3. Set the env:

```dotenv
FCM_DRIVER=http_v1
FIREBASE_CREDENTIALS=/var/www/assab/storage/app/firebase/service-account.json
FCM_ANDROID_CHANNEL_ID=assab_default
```

Leave `FCM_DRIVER=null` (the default) and nothing breaks — sends are logged and
reported successful, so local and CI run without Firebase at all.

> The legacy `key=AAAA…` server key is **not** supported. Google shut that API
> down in 2024; this integration uses HTTP v1 with an OAuth2 service-account
> assertion, minted and cached by the backend.

### 2.2 Queue worker

Push is delivered from a queued job. The worker must consume the queue:

```bash
php artisan queue:work --queue=notifications,default
```

### 2.3 Scheduled maintenance

`notification:prune-device-tokens` runs weekly (Mondays 04:15 Asia/Riyadh) and
deletes tokens unused for `FCM_STALE_TOKEN_DAYS` (default 180). Dead tokens are
*also* pruned reactively whenever Firebase rejects one.

---

## 3. Client integration

### 3.1 Register the device

Call this **on every app launch** and **on every FCM token refresh**. It is
idempotent.

```http
POST /api/v1/device-tokens
Authorization: Bearer <token>
Content-Type: application/json

{
  "token": "<FCM registration token>",
  "platform": "android",          // android | ios | web   (required)
  "app": "mobile",                // mobile | dashboard    (default: mobile)
  "locale": "ar",                 // en | ar               (default: app locale)
  "device_id": "a1b2c3d4",        // stable per install — see below
  "device_name": "Galaxy S23",
  "app_version": "2.4.1"
}
```

```json
{
  "success": true,
  "message": "Device registered for push notifications",
  "data": {
    "id": "019fa2a3-…",
    "token_preview": "fMEP8t…9Xk2",
    "platform": "android",
    "app": "mobile",
    "locale": "ar",
    "device_id": "a1b2c3d4",
    "last_used_at": "2026-07-27T11:14:10+03:00"
  }
}
```

Notes that matter:

- **The raw token is never returned.** It is a device credential; anyone holding
  it can push to that handset.
- **`device_id` is important.** FCM re-issues a registration token on reinstall
  and on some OS upgrades. Sending a stable per-install id lets the backend
  retire the superseded token instead of addressing the same handset twice.
- **Handset handover is handled.** If user B registers a token that user A still
  holds, the token is *moved* to B and A's topic subscriptions are stripped.
  A token has exactly one owner at a time.
- Throttled to 30 requests/minute.

### 3.2 Revoke on sign-out

```http
DELETE /api/v1/device-tokens
{ "token": "<FCM registration token>" }
```

Always call this on logout. Skipping it leaves the previous user receiving
notifications on a shared handset.

Sign out everywhere:

```http
DELETE /api/v1/device-tokens/all
```

### 3.3 List / test

```http
GET    /api/v1/device-tokens          # the caller's own devices only
POST   /api/v1/device-tokens/test     # fires a real push at your devices (non-production only)
```

---

## 4. Notification payload

Every push carries a `notification` block (title/body, already localized to the
device's registered locale) and a flat `data` block:

```json
{
  "notification": { "title": "Shift starting soon", "body": "Your shift starts in 15 minutes." },
  "data": {
    "notification_id": "019fa2a3-19e1-…",
    "type": "shift_start_reminder",
    "category": "operational",
    "priority": "low",
    "sent_at": "2026-07-27T11:14:10+03:00",
    "shift_id": "…"
  }
}
```

- **`data` values are always strings.** FCM rejects anything else; booleans
  arrive as `"1"`/`"0"` and nulls are dropped.
- **Route on `type`**, not on the title. The full list is
  `Modules/Notification/app/Enums/NotificationType.php`.
- **`notification_id`** matches the in-app record, so tapping a push can deep-link
  straight to `GET /api/v1/notifications/{id}` and mark it read.
- `priority` is `low | medium | high | critical`. `high`/`critical` are sent with
  Android priority `HIGH` and `apns-priority: 10`, which wakes a dozing device.

### Android

Create a notification channel whose id matches `FCM_ANDROID_CHANNEL_ID`
(`assab_default`). Android 8+ silently drops notifications on an unknown channel.

### iOS

APNs auth key must be uploaded in the Firebase console, and the app needs the
`content-available` background mode to process `data` while backgrounded.

---

## 5. Preferences

`GET|POST|PUT|DELETE /api/v1/notification-preferences`

A preference row is per (user, notification type) and stores `channels`
(`app`, `email`, `sms`, `push`), a minimum `priority_level` and an `enabled` flag.

- **No row = defaults apply** (`app` + `push`). Users who never open the
  preferences screen still get notified.
- Removing `push` from `channels` disables the device push but keeps the in-app
  record.
- A handful of types are **mandatory** and ignore the enabled/priority gate —
  compliance violations, account suspension, account deactivation. See
  `NotificationType::isMandatory()`.

---

## 6. Server-side usage

```php
use Modules\Notification\Contracts\NotificationServiceInterface;

public function __construct(private NotificationServiceInterface $notifications) {}

// One recipient — applies preferences, writes the in-app row and delivery logs,
// and mirrors push to their account in the other world.
$this->notifications->send($cashier, NotificationType::SHIFT_START_REMINDER, ['shift_id' => $id]);

// Everyone holding a role. `asab:{role_key}` addresses dashboard users;
// bare names address legacy logins.
$this->notifications->sendToRole('branch_manager', $type, $data, null, $branchId);
$this->notifications->sendToRole('asab:accountant', $type, $data);

// Everyone attached to a branch, both worlds.
$this->notifications->sendToBranch($branchId, $type, $data);

// Device-only broadcast — no in-app row, no preference gating.
$this->notifications->broadcastToTopic('branch_'.$branchId, $type, $data);
```

Or, for a standalone notification that wants none of the above:

```php
public function via($notifiable): array { return ['database', 'fcm']; }
public function toFcm($notifiable): FcmMessage { return new FcmMessage(title: '…', body: '…'); }
```

### Copy

Titles and bodies live in `Modules/Notification/resources/lang/{en,ar}/push.php`,
keyed by notification type. They are rendered in the **recipient's** locale (the
one their most recent device reported), not the request locale — the sender is
normally a queue worker. `:placeholders` are filled from the notification's data
array; only use one the dispatching listener actually supplies.

### Topics

A device is auto-subscribed on registration to `all`, `app_{mobile|dashboard}`,
`role_{…}`, `branch_{id}` and `company_{id}`. Membership is re-asserted on every
registration, so a role or branch change lands the next time the app boots.

---

## 7. Wired events

| Module | Event | Notification |
|---|---|---|
| Shift | `ShiftEndedEvent` | handover pending → next cashier |
| Shift | `VarianceRecorded` | cash variance → cashier + branch managers |
| Expense | submitted / approved / rejected | → approver / submitter |
| Custody | `HandoverApproved` | → requester |
| Purchase | `OrderCreated`, `OrderStatusChanged`, `VarianceDetected` | → supplier |
| Purchase | `GoodsReceived` | → supplier + branch managers |
| Purchase | `ReturnOrderSubmitted` / `ReturnOrderApproved` | → supplier / raiser |
| Cashier | created / activated / deactivated | → cashier (+ branch manager on deactivation) |
| BranchManagers | created / suspended | → the manager |
| Admin | `OperationFinalApproved` / `OperationRejected` | → the submitter |
| FixedAssets | handover started / signed / completed | → the counterparty |
| Inventory | `MonthlyInventorySessionUpdated` (submitted only) | → branch managers |

Account-creation events deliberately carry **no credentials** in the payload — a
push payload lands in the OS notification log.

---

## 8. Failure behaviour

| Firebase says | We do |
|---|---|
| `UNREGISTERED`, `NOT_FOUND`, `SENDER_ID_MISMATCH` | delete the token |
| `INVALID_ARGUMENT` naming `message.token` | delete the token |
| `INVALID_ARGUMENT` naming anything else | log; **keep** the token (our payload is at fault) |
| `429`, `5xx` | retry — 3 attempts, 10s/60s/180s backoff |
| `401` | re-mint the access token and retry once (handles key rotation) |

Delivery outcomes are written to `notification_logs` (`channel = push`).
