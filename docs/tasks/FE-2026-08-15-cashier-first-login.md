# 2026-08-15 — the cashier joins the shared mobile first-login contract

The cashier was the only one of the four mobile account types with **no
`first-login` step and no first-login token**. Its activation screen had to post
`identifier + default_password + password` in one shot, so an app build that
implements the two-step flow the branch manager / brand owner / supplier use
could not activate a cashier at all.

Nothing that already worked changed shape.

## New

```
POST /api/v1/cashier/auth/first-login
{ "identifier": "cashier@… | 05…", "password": "<the password the branch manager issued>",
  "fcm_token": "…optional" }

200 { "data": { "user": {id,name,email,phone,image,created_at},
                "token": "<first-login-token>",
                "requires_password_reset": false } }
401  wrong identifier OR wrong password (one message for both — no enumeration)
403  the account is deactivated
```

`requires_password_reset` comes from `features.mobile_force_first_login_reset`
(default **false**): the sign-in itself completes activation
(`status: pending → active`, `activated_at` stamped), so the app must **not**
route to «activate Account». Identical to the other three surfaces.

```
POST /api/v1/cashier/auth/reset-password-first-login
```

An alias of `/activate` — same handler — so a build that only knows the shared
route name works too.

## Unchanged

```
POST /api/v1/cashier/auth/activate
{ identifier, default_password, password, password_confirmation }
200 { "data": { "message": "…", "redirect_to_login": true } }
```

The shipped screen keeps working byte-for-byte. What it now **also** accepts is
the first-login token instead of `default_password` — in the `Authorization`
header (`Bearer …`, or bare) or as a `token` / `access_token` / `api_token` body
field, via the shared `FirstLoginActivationResolver`.

Password rules are the ones the cashier screen always had: `min:8`, `confirmed`,
at least one uppercase letter and one digit.

## Behaviour that was aligned

| | before | now |
|---|---|---|
| unknown identifier on activate | **404** (account enumeration) | 401, same message as a wrong password |
| no proof at all | 422 «default_password is required» | **401** with a recoverable message |
| already-activated account | 400 always | 400 for a bare session token; a request carrying the **current** password is a change-password and succeeds — same rule as the other three |
| deactivated account | not checked on activate | 403 |
| throttling | **none on any cashier auth route** | `throttle:cashier-auth` on all of them — 20/min per IP + 8/min per identifier (same shape as `supplier-auth`) |

## Files

- `Modules/Cashier/app/Services/CashierAuthService.php` (new) — first-login +
  password set; the controller stays thin.
- `Modules/Cashier/app/Http/Controllers/Auth/ActivationController.php` —
  `firstLogin()` added, `activate()` routed through the shared resolver.
- `Modules/Cashier/app/Http/Requests/Auth/{FirstLoginRequest,ResetPasswordFirstLoginRequest}.php`
  (new; the old `ActivationRequest` is gone — every proof field is `sometimes`
  now, which is what lets the token shape through).
- `Modules/Cashier/app/Models/Cashier.php` — `isFirstLogin()` ≡ `isPending()`
  (the cashier has no `is_first_login` column; `status` is the same fact).
- `Modules/Cashier/routes/api.php`, `app/Providers/AppServiceProvider.php` —
  routes + the `cashier-auth` limiter.
- `tests/Feature/CashierFirstLoginTest.php` (new) — 11 tests.

## Still open (not cashier-specific)

`branch-manager/auth/login`, `branch-manager/auth/first-login`,
`brand-owner/auth/login` and `brand-owner/auth/first-login` still carry **no
throttle middleware**, against the `CLAUDE.md` rule that every public endpoint
is throttled. Say the word and they get the same treatment.
