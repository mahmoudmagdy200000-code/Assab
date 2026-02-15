# Cashier API – OpenAPI (ApiDog)

## الملف

- **`cashier-openapi.json`** – OpenAPI 3.0 لجميع اند بوينتس الكاشير (Auth، Profile، Settings).

## الـ Base URL

- المسارات مكتوبة بدون بادئة (مثل `/cashier/auth/login`).
- في ApiDog حدّد **Server URL** حسب تطبيقك:
  - إذا الـ API تحت `/api`: استخدم `https://your-domain.com/api`
  - إذا تحت `api/v1`: استخدم `https://your-domain.com/api/v1`

الملف الحالي يستخدم `servers[0].url = "/api"`؛ يمكنك تعديله أو تجاوزه في ApiDog.

## الـ Endpoints الموثقة

| Method | Path | وصف |
|--------|------|-----|
| **Auth (Public)** | | |
| POST | `/cashier/auth/login` | تسجيل الدخول (identifier, password, remember_me) |
| POST | `/cashier/auth/activate` | تفعيل الحساب (identifier, default_password, password) |
| POST | `/cashier/auth/forgot-password` | إرسال OTP (identifier, type: email\|phone) |
| POST | `/cashier/auth/verify-otp` | التحقق من OTP (identifier, otp) → reset_token |
| POST | `/cashier/auth/reset-password` | تعيين كلمة مرور جديدة (identifier, reset_token, password) |
| **Protected (Bearer)** | | |
| POST | `/cashier/logout` | تسجيل الخروج |
| GET  | `/cashier/profile` | عرض الملف الشخصي |
| PUT  | `/cashier/profile` | تحديث الملف (name, phone, password) |
| POST | `/cashier/profile/image` | رفع صورة الملف (multipart: image) |
| GET  | `/cashier/profile/statistics` | إحصائيات الكاشير |
| GET  | `/cashier/settings` | كل الإعدادات (profile, account, branch, notifications, system) |
| GET  | `/cashier/settings/account` | تفاصيل الحساب والفرع فقط |
| PUT  | `/cashier/settings/notifications` | تحديث إعدادات الإشعارات |
| PUT  | `/cashier/settings/system` | تحديث اللغة والثيم (language, theme) |

## الاستيراد في ApiDog

1. افتح المشروع في ApiDog.
2. استورد الملف: **Import** → **OpenAPI** → اختر `cashier-openapi.json`.
3. تأكد من **Server** في الوثيقة أو في ApiDog أن الـ Base URL صحيح.
4. للاند بوينتس المحمية: أضف **Authorization** نوع **Bearer Token** وقيمة التوكن بعد تسجيل الدخول.

## ملاحظة

اند بوينتس **Branch Manager** (مثل `branch-manager/cashiers/*`) غير مضمنة في هذا الملف؛ الملف يوثّق فقط اند بوينتس **الكاشير** حسب `Modules/Cashier/Routes/api.php`.
