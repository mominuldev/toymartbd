# Customer account sign-in API

## 1. Overview

P4-STO-11, extended by the owner's decision of 2026-11-20. A storefront customer signs in with **phone + password** (the default tab) or **phone + SMS code**. They register with **name + phone + password**, proved by an SMS code, and recover a forgotten password with an SMS code.

The phone is the identity (rule 30, E.164). Codes go **by SMS only, never WhatsApp** (rule 32b). Every endpoint is called by the Next.js server: the `auth/*` ones with its storefront token (`storefront:read`), the `account/*` ones with the customer's own Sanctum token (ability `customer`, 60 days). The customer token goes only to the Next.js server, which keeps it in the httpOnly `tm_account` cookie (rule 28).

Code: `app/Domain/Customer/Actions/` (`RequestLoginOtp`, `ConsumeOtp`, `VerifyLoginOtp`, `SignInWithPassword`, `StartCustomerRegistration`, `RegisterCustomer`, `ResetCustomerPassword`, `ChangeCustomerPassword`), `Services/OtpCodes`, `Services/CustomerSessions`, `Http/Controllers/Api/V1/Storefront/Account/AuthController` and `AccountController`. Tests: `tests/Feature/Storefront/Account/OtpAuthTest.php` and `PasswordAuthTest.php`.

## 2. Data

- `customers.password`: nullable, Laravel `hashed` cast, `$hidden`. It is never returned, and never logged (the log redactor drops any key containing `password`).
- `customers.password_set_at`: when the password was last set.
- `customer_otps.purpose`: `login` | `register` | `password_reset`. A code verifies only for its own purpose. Only HMACs of the phone and the code are stored.
- **Registered** (`Customer::isRegistered()`) means a password is set, or the phone was proved by an SMS sign-in. A guest-checkout row is *not* registered, so its owner can still register. The row becomes the account, and its existing name is kept.

## 3. Endpoints (`/api/v1/storefront/…`)

| Method & path | Body | Success | Errors | Limits |
|---|---|---|---|---|
| `POST auth/otp` | `phone` | 202 `{expires_in, resend_in, length}` | 422, 429 | `otp` 3/10 min per phone, `otp-ip` 30/h per IP, plus 60 s cooldown and 5/h in the domain |
| `POST auth/otp/verify` | `phone`, `code` (+ `X-Cart-Token`) | 200 session | 422 `code`, 403 blocked, 429 | `otp-verify` 10/10 min per phone |
| `POST auth/password` | `phone`, `password` (+ `X-Cart-Token`) | 200 session | 422 `password`, the **same** error for an unknown phone, no password set, or a wrong password; 403 blocked (only after the right password); 429 | `password-login` 5/10 min per phone **and** 30/h per IP |
| `POST auth/register` | `phone` | 202 code sent (`register`) | **409 `phone-already-registered`**; 422; 429 | `otp` + `otp-ip` (as code requests) |
| `POST auth/register/verify` | `phone`, `code`, `name`, `password`, `password_confirmation` (+ `X-Cart-Token`) | 200 session (`is_new_customer` true unless a guest-checkout row became the account) | 422 (`code`, `name`, `password`); 409 if the phone became registered meanwhile; 403 blocked | `otp-verify` |
| `POST auth/password/forgot` | `phone` | 202 code sent (`password_reset`), the same answer for an unknown phone | 422, 429 | `otp` + `otp-ip` |
| `POST auth/password/reset` | `phone`, `code`, `password`, `password_confirmation` (+ `X-Cart-Token`) | 200 session; **every earlier session is signed out** | 422 (`code`, `password`); 403 blocked | `otp-verify` |
| `PUT account/password` | `current_password` (required when a password exists), `password`, `password_confirmation` | 200 `StorefrontAccount`; every other session signed out | 401; 422 `current_password` / `password` | `account-password` 5/10 min per customer |
| `GET account` | — | 200 `StorefrontAccount`, now with `has_password` | 401 | — |

A **session** (`StorefrontAccountSession`) is `{token, expires_at, is_new_customer, customer, cart}`. The guest cart named by `X-Cart-Token` is merged into the customer's cart, exactly as for an SMS sign-in.

**Password rule:** at least 8 characters (`Password::min(8)`), at most 128, typed twice (`password_confirmation`).

**`phone-already-registered`** is an RFC 9457 problem (`type` `https://toymartbd.com/problems/phone-already-registered`, status 409). It tells the caller the phone has an account: the owner accepted that enumeration trade-off on 2026-11-20. The check sits behind the same per-phone and per-IP limits as any code request.

**Reset for an unknown phone:** a proved phone with no customer gets one, with an empty name, exactly as an SMS sign-in would make it.

## 4. Storefront rules (`../toymartbd-storefront`)

- Sign-in opens on the **Password** tab; the **SMS code** tab is one click away.
- After any sign-in, a customer whose `has_password` is false is sent to **Set your password** (required, no skip) before the account pages. Guest checkout is unaffected.
- "Create an account" answers a 409 with "This number is already registered", with links to Sign in and Forgot password.
- Changing a password while signed in needs the current one. A customer who forgot it uses Forgot password (a fresh SMS code).
