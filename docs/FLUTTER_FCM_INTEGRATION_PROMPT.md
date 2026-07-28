# Flutter FCM integration — implementation brief

Hand this to the Flutter developer (or paste it into a coding agent) as the task
spec for the mobile side of push notifications.

---

## Task

Implement Firebase Cloud Messaging push notifications in the Assab Flutter app,
against a Laravel backend that is already built and live. Do not change the API
contract — implement against it exactly as specified below.

## Context

- **Base URL:** `{{BASE_URL}}/api/v1`
- **Auth:** Laravel Sanctum bearer token — `Authorization: Bearer <token>`
- **Locale:** send `Accept-Language: ar` or `en` on every request
- **Backend push transport:** FCM HTTP v1 (server-side; nothing for you to configure there)
- Every response uses this envelope:
  ```json
  { "success": true, "message": "...", "data": {}, "meta": {} }
  ```
  Errors: `{ "success": false, "message": "...", "errors": {} }`

## Packages

```yaml
dependencies:
  firebase_core: ^3.x
  firebase_messaging: ^15.x
  flutter_local_notifications: ^18.x
  device_info_plus: ^11.x     # for a stable device_id
  package_info_plus: ^8.x     # for app_version
```

---

## 1. Firebase project setup

- Add `android/app/google-services.json` and `ios/Runner/GoogleService-Info.plist`
  (ask the backend team for the Firebase project — the server uses the same one).
- iOS: upload the APNs auth key in the Firebase console, enable Push Notifications
  and Background Modes → Remote notifications capability.
- Android 13+: request the `POST_NOTIFICATIONS` runtime permission.
- iOS: call `FirebaseMessaging.instance.requestPermission()`.

### Android notification channel — required

The server sends `android.notification.channel_id = "assab_default"`. Android 8+
**silently drops** notifications whose channel does not exist. Create it at startup
with `flutter_local_notifications`:

```dart
const channel = AndroidNotificationChannel(
  'assab_default',            // must match exactly
  'Assab Notifications',
  importance: Importance.high,
);
```

---

## 2. Registering the device

There are two paths. Implement **both**.

### 2a. At login (preferred)

Every login endpoint accepts the device fields in the same body. Send them and
the user is push-addressable immediately — no second call.

```http
POST /api/v1/cashier/auth/login
Content-Type: application/json

{
  "identifier": "cashier@example.sa",
  "password": "...",

  "fcm_token": "<await FirebaseMessaging.instance.getToken()>",
  "platform": "android",        // android | ios | web
  "app": "mobile",
  "locale": "ar",               // en | ar
  "device_id": "<stable per-install id>",
  "device_name": "Galaxy S23",
  "app_version": "2.4.1"
}
```

Login endpoints by role — use the one your app screen already uses:

| Role | Endpoint |
|---|---|
| Cashier | `POST /api/v1/cashier/auth/login` |
| Branch manager | `POST /api/v1/branch-manager/auth/login` |
| Brand owner | `POST /api/v1/brand-owner/auth/login` |
| Brand manager | `POST /api/v1/brand-manager/auth/login` |
| Supplier | `POST /api/v1/supplier/auth/login` |
| ASAB dashboard user | `POST /api/v1/auth/login` |

All device fields are **optional** — omitting them keeps the current login
behaviour, and the login response body is unchanged. If push registration fails
server-side, the login still succeeds.

Getting the FCM token can be slow or fail (no Play Services, no network, denied
permission). **Never block or fail login on it.** Wrap it:

```dart
String? fcmToken;
try {
  fcmToken = await FirebaseMessaging.instance.getToken()
      .timeout(const Duration(seconds: 3));
} catch (_) {
  fcmToken = null; // just log in without it; the standalone call will retry later
}
```

### 2b. Standalone endpoint

```http
POST /api/v1/device-tokens
Authorization: Bearer <token>

{
  "token": "<fcm token>",       // required
  "platform": "android",        // REQUIRED here (unlike login, where it defaults)
  "app": "mobile",              // optional
  "locale": "ar",               // optional, en|ar
  "device_id": "...",           // optional
  "device_name": "...",         // optional
  "app_version": "..."          // optional
}
```

Call it:
- on **every `onTokenRefresh`** event — FCM rotates tokens independently of login
- on app launch when the session was restored from storage without a fresh login
- after the user grants notification permission, if they had denied it before

It is idempotent and throttled to 30 requests/minute. Note the field is named
`token` here, but `fcm_token` on login.

```dart
FirebaseMessaging.instance.onTokenRefresh.listen((token) {
  if (isLoggedIn) api.registerDeviceToken(token);
});
```

### 2c. `device_id` matters

Send a stable per-install identifier. FCM re-issues a registration token on
reinstall and on some OS upgrades; `device_id` lets the server retire the old
token instead of pushing to the same handset twice. Use `device_info_plus`
(`androidId` / `identifierForVendor`) or a UUID persisted in secure storage.

### 2d. Shared handsets

If user B logs in on a phone where user A was registered, the server **moves**
the token to B automatically. You do not need to handle this — but you must send
`fcm_token` on login for it to work.

---

## 3. Logout

```http
POST /api/v1/cashier/logout
Authorization: Bearer <token>

{ "fcm_token": "<same token>" }
```

Send `fcm_token` on logout, on whichever logout endpoint your role uses. **If you
omit it, the handset keeps receiving that user's notifications.**

Order matters: the API call must go out *before* you discard the bearer token
locally. Optionally follow with `FirebaseMessaging.instance.deleteToken()`.

Other endpoints:
- `DELETE /api/v1/device-tokens` with `{"token": "..."}` — revoke one device
- `DELETE /api/v1/device-tokens/all` — sign out of push everywhere
- `GET /api/v1/device-tokens` — list this user's registered devices (raw tokens
  are never returned, only a masked `token_preview`)

---

## 4. Message payload

The server sends **both** a `notification` block and a `data` block:

```json
{
  "notification": {
    "title": "Shift starting soon",
    "body": "Your shift starts in 15 minutes."
  },
  "data": {
    "notification_id": "019fa2a3-19e1-733a-ab50-df6bb63ebfa6",
    "type": "shift_start_reminder",
    "category": "operational",
    "priority": "low",
    "sent_at": "2026-07-27T11:14:10+03:00",
    "shift_id": "...",
    "branch_id": "..."
  }
}
```

Rules you must code against:

- **Every `data` value is a String.** FCM forbids anything else. Booleans arrive
  as `"1"` / `"0"`. Parse, do not cast blindly.
- **Route on `data['type']`**, never on the title text — the title is already
  localized and will differ between Arabic and English.
- **`notification_id`** is the id of the in-app record. Use it to deep-link and to
  mark the notification read.
- **`priority`** is `low | medium | high | critical`.
- Title/body arrive **already translated** into the locale you registered for that
  device. Do not translate them client-side.
- The server sets a **collapse key equal to `type`**, so a device that was offline
  through five identical reminders wakes to one, not five. Expected behaviour.

### Because a `notification` block is present

On Android, when the app is **backgrounded or terminated**, the system tray shows
the notification itself and `onMessage` does **not** fire. Handle all three states:

```dart
// 1. Foreground — nothing is shown automatically; render it yourself
FirebaseMessaging.onMessage.listen((msg) => showLocalNotification(msg));

// 2. Background, user tapped the tray notification
FirebaseMessaging.onMessageOpenedApp.listen((msg) => routeFrom(msg.data));

// 3. Terminated, launched by tapping the notification
final initial = await FirebaseMessaging.instance.getInitialMessage();
if (initial != null) routeFrom(initial.data);

// 4. Background data handler — MUST be a top-level function
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  // keep this light: no UI, no heavy I/O
}
FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
```

### Notification types → routing

Full list lives in `Modules/Notification/app/Enums/NotificationType.php`. Grouped:

| Group | `type` values |
|---|---|
| Shift | `shift_start_reminder`, `shift_start_overdue`, `shift_end_reminder`, `shift_handover_pending`, `shift_handover_request`, `shift_handover_approval_required`, `shift_handover_approved`, `shift_handover_rejected`, `shift_handover_variance`, `shift_handover_completed`, `shift_unapproved_handover`, `shift_sales_rejected` |
| Variance | `cash_variance_high`, `card_payment_discrepancy`, `delivery_settlement_mismatch`, `high_variance_trend` |
| Expense | `expense_submitted`, `expense_approved`, `expense_rejected`, `expense_pre_approval_request`, `expense_limit_exceeded` |
| Custody | `custody_request_created`, `custody_request_approved`, `custody_request_rejected`, `custody_low_balance`, `custody_cash_transfer`, `custody_reconciliation_reminder` |
| Compliance | `vat_reporting_deadline`, `tax_document_required`, `compliance_violation`, `audit_trail_notification` |
| Purchase | `order_created`, `order_status_changed`, `order_variance_detected`, `goods_received`, `return_order_submitted`, `return_order_approved` |
| Account | `cashier_account_created`, `cashier_account_activated`, `cashier_account_deactivated`, `branch_manager_account_created`, `branch_manager_suspended` |
| Approvals | `operation_final_approved`, `operation_rejected` |
| Fixed assets | `asset_handover_started`, `asset_handover_signature_required`, `asset_handover_completed` |
| Inventory | `inventory_session_updated` |

Handle unknown types gracefully — new ones get added server-side. Default to
opening the notifications list rather than crashing or ignoring.

---

## 5. In-app notification centre

```http
GET    /api/v1/notifications          # paginated (?per_page=15)
GET    /api/v1/notifications/unread   # { count, notifications }
GET    /api/v1/notifications/{id}
POST   /api/v1/notifications/{id}/read
POST   /api/v1/notifications/read-all
DELETE /api/v1/notifications/{id}
```

Each record's `data` contains `type`, `title`, `message`, `priority`, `category`,
a nested `data` object with the domain fields, and `created_at`.

Wire the badge count to `/notifications/unread`, and mark a notification read when
the user opens it from a push tap using `data['notification_id']`.

---

## 6. Preferences screen

```http
GET    /api/v1/notification-preferences
POST   /api/v1/notification-preferences
PUT    /api/v1/notification-preferences/{id}
DELETE /api/v1/notification-preferences/{id}
```

```json
{
  "notification_type": "shift_start_reminder",
  "channels": ["app", "push"],       // app | email | sms | push
  "priority_level": "low",           // low | medium | high | critical
  "enabled": true
}
```

- `push` = device push (what you are building). `app` = the in-app record.
- A user with **no** preference row gets the defaults (`app` + `push`) — so an
  empty preferences response does not mean notifications are off.
- A few types ignore the toggle entirely (compliance violation, account
  suspension, account deactivation). Show them as non-editable rather than
  letting the user think they disabled them.

---

## 7. Testing

`POST /api/v1/device-tokens/test` fires a real push at your own devices. Available
in staging only (returns 403 in production), throttled to 5/minute.

Ask the backend team to confirm `FCM_DRIVER=http_v1` is set in the environment
you are testing against — with the default `null` driver the server logs pushes
instead of sending them, and you will see nothing on the device.

## 8. Acceptance criteria

- [ ] Notification permission requested on first launch; denial handled gracefully
- [ ] `assab_default` Android channel created; notifications visible on Android 13+
- [ ] `fcm_token` sent on login for every role
- [ ] `onTokenRefresh` re-registers via `POST /device-tokens`
- [ ] `fcm_token` sent on logout
- [ ] Foreground, background-tap and terminated-launch all route correctly
- [ ] Deep link from a push opens the right screen and marks it read
- [ ] Arabic device shows Arabic copy without any client-side translation
- [ ] Login still succeeds with notifications denied, no network for FCM, or no Play Services
- [ ] Log out user A, log in user B on the same device → B gets pushes, A does not
