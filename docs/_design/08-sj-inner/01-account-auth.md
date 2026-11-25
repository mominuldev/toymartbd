# 08 · 01 — Sign-in and account set-up (Smart Junior, Vivid · বাংলা)

**Status:** spec for Figma, not built · **Written:** 2026-11-25 · **Batch:** `docs/_handoff/briefs/brief-p10-sj-inner-pages.md`, row 1
**Figma:** `2b6PVOOapPbcaKYIdHWZup`, page `34:5658` (Vivid · বাংলা). Read-only research; nothing was written to the file.
**Flow source:** owner decision 2026-11-20 (password **and** SMS code, password tab first; forgot → SMS code → new password;
"Set your password" required, no skip; "already registered" on register), `docs/api/account.md` §3–4, and the storefront code
listed per page. Every form is a plain POST to a Route Handler: **all five pages work without JavaScript**. The frames show
the server-rendered page; the JS-only extras (OTP auto-advance, resend countdown) are noted where they differ.

## Placement on page `34:5658`
The last row is Order confirmed (`34:7265`, y 23,512, ends ≈ 25,305). Follow the existing grid: one row per page, a label frame
(`Label — NN · …`, as `34:7363`) 150 above the row, Desktop at x 0, Mobile at x 1840, state frames at x 2340, 2840 (390 apart
+ 110 gap). Rows start ≈ 450 below the previous row's tallest frame, so this batch's first row is at **y ≈ 25,760**. Order:
07 Sign in (Password pair on the row, its error state at x 2340; the SMS-code pair on a sub-row directly below, same x
columns, 400 gap) · 08 Create account · 09 Enter the code · 10 Set your password · 11 Reset your password. Frame numbers
07–11 are provisional; the lead renumbers across the five specs. Compute bounding boxes after building and assert no overlap (Figma rule 3).

## 0. Shared pieces (used on every page below)

Frame chrome, from the existing Vivid frames:
- **Desktop 1440:** `Header` instance (as in Cart `34:6774`, 1440 × 210) · **Body** · `Footer — Vivid · বাংলা` Desktop instance
  (as `41:12460`). No bottom tab bar, no announcement change.
- **Mobile 390:** `Header` Mobile instance (as `34:7308`, 390 × 161) · **Body** · Footer Mobile instance. **No** Bottom Tab Bar
  (`PageShell` default `bottomBar=false` on these routes) and **no** Mobile Top Bar (the site header stays; see Q6).
- Body fill `bg/canvas`. **No confetti** on any of these pages: the owner limited `Pattern / Confetti` to the sections in 07 §7.4,
  and a sign-in form is a decision surface like checkout (see Q5).

**AuthLayout — Desktop body.** Horizontal auto-layout, content width 1200 at x 120, padding top `space/32` bottom `space/32`
(code uses 80 below; `SJ · Space` showed nothing above 32 in the Vivid frames — use the largest step that exists), gap `space/32`,
cross-axis **stretch** (left panel is as tall as the card).
- **Left: "Benefits panel"** (fill width, ≈ 688). Fill `pastel/sky`, radius `radius/xl`, no stroke. Vertical, padding `space/32`
  (top/sides 40 in code → `space/32`), gap `space/24`.
  - **AuthHero:** vertical, gap `space/16`, left-aligned. Smiley badge: 72 circle `bg/surface`, `Icon/Smiley` 40 `icon/brand`.
    Title `SJ Vivid/Heading/H1` `text/primary`. Body `SJ Vivid/Body/L` `text/secondary`, max 560.
  - **Benefits list:** a `bg/surface` card, radius `radius/lg`, padding `space/24`, vertical gap `space/20`. Four **Benefit rows**
    (horizontal, gap `space/16`, top-aligned): 48 circle `action/primary-soft` with a 24 icon `icon/brand`; text column gap 2:
    title `SJ Vivid/Label/L` `text/primary`, line `SJ Vivid/Body/M` `text/secondary`. Icons: `Cube`, `MapPin`, `Heart`, `Gift`.
    Heading "অ্যাকাউন্টে যা পাবেন" is screen-reader-only on desktop — don't draw it.
  - **Trust line:** horizontal gap `space/8`: `Icon/ShieldCheck` 18 `text/success` + `SJ Vivid/Body/M` `text/secondary`.
- **Right: "Form card"** (fixed 480, hug height, top-aligned). `bg/surface`, 1 px `border/subtle`, radius `radius/xl`, padding
  `space/32`, vertical gap `space/20`. Same card language as Checkout's Contact card `34:7035`.
  - **Card heading:** vertical gap `space/8`: `SJ Vivid/Heading/H2` `text/primary` + `SJ Vivid/Body/M` `text/muted`.
  - **Fields:** `Input` (`7:178`) at **Size Large (52 tall)**. Label `SJ Vivid/Label/M`, value `SJ Vivid/Body/L`, help
    `SJ Vivid/Caption` `text/muted`. Phone values and placeholders set in Fredoka (digits).
  - **Primary button:** `Button` `5:124` Primary / L, full width. Label `text/on-primary`.
  - **Small print:** `SJ Vivid/Caption` `text/muted`, centred.
  - **Divider:** 1 px `border/default`, full width.
  - **Switch link row:** centred, `SJ Vivid/Body/M` `text/secondary` + link `SJ Vivid/Label/M` `text/brand`, underlined.
  - **Guest note:** fill `pastel/mint`, radius `radius/md`, padding `space/16`, gap `space/6`: title `SJ Vivid/Label/M`
    `text/primary`, line `SJ Vivid/Caption` `text/secondary` + inline link "কার্টে যান" `text/brand` underlined.

**AuthLayout — Mobile body.** Vertical, padding `space/16` sides, top `space/16`, bottom `space/32`, gap `space/16`.
1. **Hero card** (full width 358): fill `pastel/sky`, radius `radius/lg`, padding `space/24`/`space/20`, centred, gap `space/10`.
   Smiley 72 circle as desktop. Title `SJ Vivid/Heading/H2` (mobile title), body `SJ Vivid/Body/M` `text/secondary`.
2. **Form card:** `bg/surface`, 1 px `border/subtle`, radius `radius/lg`, padding `space/16`, gap `space/12` (code 14). Inputs at
   **Size Medium (48, the component default)**; touch targets ≥ 44.
3. **Benefits card** (login and register only): `bg/surface`, 1 px `border/subtle`, radius `radius/lg`, padding `space/16`,
   gap `space/12`: heading `SJ Vivid/Label/M`; **three** rows (orders, addresses, wishlist — no birthdays, no trust line), 40
   circles with 20 icons, title `SJ Vivid/Label/M`, line `SJ Vivid/Caption`.
4. **Switch link row**, then **Guest note** (as desktop).

**Notice banner** (one-shot flash after a POST; first child under the card heading). Horizontal, gap `space/8`, padding
`space/10`/`space/12`, radius `radius/md`, text `SJ Vivid/Body/M` `text/primary`.
- Error: fill **`bg/danger-soft` (missing, §6)**, icon `WarningCircle` 18 **`icon/danger` (missing)**. `role=alert`.
- Success: fill `pastel/mint`, icon `CheckCircle` 18 `text/success`. `role=status`.

**Field error:** `Input` State=Error. The red help line **replaces** the help text in the same slot (one line, words, never
colour alone); the field gets `aria-invalid`. The Error variant today binds `text/sale` — see §6.

**Segmented method switch** ("Password | SMS code", login only). No SJ component exists (Chip `7:146` is not a tab). Build
as a local pattern: track `bg/muted`, radius `radius/full`, padding `space/4`, gap `space/4`; two equal items 44 tall, radius
`radius/full`; current = `bg/surface` + `SJ/Elevation/e1` + `SJ Vivid/Label/L` `text/primary`; other = no fill,
`SJ Vivid/Label/L` `text/muted`. They are **links** with `aria-current="page"` (own URLs), not ARIA tabs. Propose it as a
component (`Segmented`, Size L) if the builder prefers.

## 1. Sign in — `/[locale]/account/login`
Purpose: sign in with mobile + password (default) or mobile + SMS code (`?method=sms`); `?next=` carries the return path.
Code: `app/[locale]/account/login/page.tsx`, `components/account/AuthPanel.tsx`, `AccountNotice.tsx`; posts to
`/api/account/password` or `/api/account/otp`. Signed-in visitors are redirected to `next`.

**Frames:** `07 · Sign in — Password — Desktop` / `— Mobile`; `07 · Sign in — SMS code — Desktop` / `— Mobile`;
state `07 · Sign in — Password — Error — Mobile`.

**Desktop — Password tab.** Panel: title `login.passwordPanelTitle`, body `login.panelBody`, 4 benefits, trust line. Card,
top to bottom: heading `login.passwordTitle` / `login.passwordSub` → method switch (Password current) → phone field (label
`login.phone`, placeholder `01XXX XXX XXX`, no help) → password field (label `login.password`, masked value `••••••••`) →
"forgot" link row `login.forgot` (`SJ Vivid/Caption`, `text/brand`, underlined, left) → Primary L `login.passwordSubmit` →
small print `login.passwordNote` → divider → switch row `login.newHere` + `login.createLink` → guest note.

**Desktop — SMS code tab.** Panel title `login.panelTitle`. Card: heading `login.title` / `login.sub` → switch (SMS current) →
phone field with help `login.phoneHelp` → Primary L `login.submit` → small print `login.smsNote` → divider → switch row → guest note.

**Mobile.** Hero: Password tab shows `login.passwordTitle` + `login.passwordSub`; SMS tab shows `login.title` + `login.heroSub`.
No card heading (the hero is the heading). Form card holds the switch, fields, button and small print; the forgot link sits
directly under the password field as `login.forgotShort` (gap −6 to the field, i.e. it reads as the field's help line). Then
benefits card (`benefits.titleSignIn`), switch row, guest note.

**States.**
- Error (own mobile frame): notice `notices.passwordWrong` is a **field** error on password (Input Error, line under it), not a
  banner. Phone stays filled (`01712 345 678`); the password is always empty after a failed POST.
- Phone invalid: phone Input Error, `notices.phoneInvalid`. Password empty: `notices.passwordRequired`.
- Rate limit / blocked / failure: error banner with the API's own sentence (429/403) or `notices.failed` (contains the hotline,
  **placeholder**). Draw one banner example in the error frame only.
- Arriving signed out: success banner `notices.signedOut`; after a session expired: error banner `notices.sessionEnded`.
- Bangla long text: `login.panelBody` (≈ 4 lines at 560) and `login.forgotShort` (2 lines at 326) are the longest — size the
  hero and link to wrap, never truncate.

## 2. Create account — `/[locale]/account/register`
Purpose: name + mobile + password twice → SMS `register` code → `/account/verify` makes the account.
Code: `app/[locale]/account/register/page.tsx`, `PasswordFields.tsx`; POST `/api/account/otp` (`intent=register`).

**Frames:** `08 · Create account — Desktop` / `— Mobile`; state `08 · Create account — Already registered — Mobile`.

**Desktop.** Panel title `register.panelTitle`, body `login.panelBody`, 4 benefits, trust line. Card: heading `register.title` /
`register.sub` → name field (`register.name`, placeholder `register.namePlaceholder`, help `register.nameHelp`) → phone field
(`login.phone`, help `login.phoneHelp`) → password (`register.password` + red ` *`, help `register.passwordHelp`) → repeat
(`register.passwordRepeat` + ` *`, help `register.passwordRepeatHelp`) → Primary L `register.submit` → small print
`register.note` → divider → switch row `register.haveAccount` + `register.signInLink` → guest note.
Required mark: ` *` in **`text/danger` (missing)**; the screen reader gets "(আবশ্যক)" — nothing extra to draw.

**Mobile.** Hero `register.title` / `register.sub`; form card (no heading); benefits card titled `benefits.titleRegister`;
switch row; guest note.

**"Already registered" (own mobile frame; 409 `phone-already-registered`).** Error banner, but two-level: title
`register.alreadyTitle` (`SJ Vivid/Label/M`), body `register.alreadyBody`, then a wrap row (gap `space/16`) of two links
`register.alreadySignIn` → `/account/login` and `register.alreadyForgot` → `/account/reset-password` (`SJ Vivid/Label/M`,
`text/brand`, underlined, each ≥ 44 tall hit area on mobile). Phone field is in Error style **without** its own line (the banner
says it); name and phone stay filled; both password fields are empty.
Other errors: `notices.nameInvalid`, `notices.phoneInvalid`, `notices.passwordShort` (on password), `notices.passwordMismatch`
(on repeat). Long text: `register.note` and `register.passwordHelp` wrap to 2–3 lines in Bangla at 358.

## 3. Enter the code — `/[locale]/account/verify`
Purpose: the six-digit SMS code for sign-in, register or reset. The phone comes from an httpOnly cookie; without it the page
redirects to login (so there is no "empty" state). Code: `app/[locale]/account/verify/page.tsx`, `OtpBoxes.tsx`,
`ResendTimer.tsx`; POST `/api/account/verify`, resend POST `/api/account/otp` (`intent=resend`). SMS only, never WhatsApp (rule 32b).

**Frames:** `09 · Enter the code — Desktop` / `— Mobile`; states `09 · Enter the code — Wrong code — Mobile`,
`09 · Enter the code — Resend ready — Mobile`.

**Layout (both sizes).** One centred column, width **480** desktop / 358 mobile (no benefits panel). Vertical gap `space/16`.
1. **Title row:** horizontal gap `space/8`, centre-aligned: `Icon Button` `5:161` Surface / 40 with `Icon/ArrowLeft` (label
   `verify.back`; goes back to the form that sent the code) + column: `verify.title` `SJ Vivid/Heading/H2`, `verify.sentTo` with
   `{phone}` = `01712 345 678` `SJ Vivid/Caption` `text/muted`.
2. **Code card:** `bg/surface`, 1 px `border/subtle`, radius `radius/lg` (desktop `radius/xl`), padding `space/24`/`space/16`,
   vertical, centred, gap `space/16` (code 18).
   - **OTP row:** 6 boxes, gap `space/8`, centred. Box = 46 × 56, `bg/surface`, 1 px `border/input`, radius `radius/sm`, digit
     `SJ Vivid/Heading/H2` Fredoka centred. Show the first box **focused** (2 px `border/brand`, `SJ/Focus`) and three filled
     (`4 8 2` …) in the default frame. No OTP component exists: build these from the Input field styling (§6, Q4).
   - **Resend row:** horizontal gap `space/6`: `Icon/Clock` 18 `icon/muted` + `verify.resendIn` with `{time}` = `0:42`
     (`SJ Vivid/Body/M` `text/secondary`, Fredoka digits).
   - **Primary L** `verify.submit`, full width.
   - Small print by purpose: sign-in `verify.newAccount`, register `verify.registerNote`, reset `verify.resetNote`
     (draw the sign-in one; list the other two in the frame's note).
3. **Change link:** `Button` Ghost / M, centred, `verify.change`.
4. **Help card:** fill `pastel/sky`, radius `radius/md`, padding `space/16`, gap `space/4`: `verify.helpTitle` `SJ Vivid/Label/M`,
   `verify.helpBody` with `{phone}` = hotline (**placeholder** — owner unconfirmed) `SJ Vivid/Caption` `text/secondary`.

**States.**
- Wrong / expired code (own frame): all six boxes in error border (**`border/danger`, missing**), red line under the row
  `notices.codeInvalid` (or the API's sentence), `role=alert`. Expired: error banner `notices.expired` above the card.
- Resend ready (own frame): the clock row shows a text button `verify.resend` (`SJ Vivid/Label/M` `text/brand`, underlined)
  instead of the countdown; after a resend a success banner `notices.sent`.
- **No-JS:** there is no countdown — the resend button shows at once (the API enforces the cooldown and its 429 message comes
  back as a banner). Boxes don't auto-advance; the shopper tabs. Same visuals as "Resend ready".
- With JS: typing advances, paste / SMS autofill fills all six, Bengali digits are folded to Western.

## 4. Set your password — `/[locale]/account/set-password`
Purpose: **required** after any sign-in on an account without a password (SMS sign-in, guest-row account); every account page
redirects here until done; no skip (owner, 2026-11-20). Signed-in only. Code: `app/[locale]/account/set-password/page.tsx`,
`PasswordFields.tsx`; POST `/api/account/set-password` (`intent=set`).

**Frames:** `10 · Set your password — Desktop` / `— Mobile`.

**Desktop.** Panel: title `setPassword.panelTitle`, body `setPassword.panelBody`, 4 benefits, trust line. Card: heading
`setPassword.title` / `setPassword.sub` → password + repeat (labels/help as register) → Primary L `setPassword.submit` → small
print `setPassword.note`. **No** divider, switch row or guest note (the shopper is signed in).
**Mobile.** Hero `setPassword.title` / `setPassword.sub` → form card. No benefits card.
**States.** Field errors `notices.passwordShort` / `notices.passwordMismatch`; error banner for `notices.failed`. No success
banner on arrival (the subtitle already says "you're signed in"). Draw no extra frame.

## 5. Reset your password — `/[locale]/account/reset-password`
Purpose: forgot password → phone + new password twice → SMS `password_reset` code → `/account/verify` saves it and signs out
every other session. Works signed in or out. The API answers the same for an unknown phone (no enumeration here).
Code: `app/[locale]/account/reset-password/page.tsx`; POST `/api/account/otp` (`intent=reset`).

**Frames:** `11 · Reset your password — Desktop` / `— Mobile`.

**Desktop.** Panel reuses `login.passwordPanelTitle` + `login.panelBody`, 4 benefits, trust line. Card: heading
`resetPassword.title` / `resetPassword.sub` → phone (`login.phone`, help `login.phoneHelp`) → `profile.password.new` +
`profile.password.repeat` (help as register) → Primary L `resetPassword.submit` → small print `resetPassword.note` → divider →
switch row `register.haveAccount` + `register.signInLink` → guest note.
**Mobile.** Hero `resetPassword.title` / `resetPassword.sub` → form card → switch row → guest note (no benefits card).
**States.** As register (phone / password / mismatch errors, `notices.failed`). No extra frame.

## 6. Tokens and components — gaps

| Gap | Where | Note |
|---|---|---|
| **`text/danger`, `icon/danger`, `border/danger`, `bg/danger-soft`** missing in `SJ · Color` | every field error, OTP error, required `*`, error banner | `Input` State=Error binds `text/sale` today. Code keeps danger as its own red ramp, apart from sale pink (07 §16). Add the four tokens (both modes) and rebind Input Error before building these frames. |
| No **OTP box** component | verify | Propose `OTP Box` with State = Default · Focus · Filled · Error (46 × 56). |
| No **Segmented / method switch** | login | See §0. |
| No **Notice / Alert** component | all | Error · Success variants, optional title + links slot (for "already registered"). |
| `space` above 32 not seen in Vivid frames | page bottom padding | Builder: confirm `SJ · Space` steps (code uses 80 at desktop bottom). |
| `SJ Vivid/*` style names | all | The file exposes `SJ Vivid/Body/M`; the other names here assume the same role names as `SJ/*` (Heading/H1, H2, Label/L, Label/M, Body/L, Caption). Confirm in the styles panel. |

## 7. Copy table (from `lib/account/messages.ts`; Bangla is the shipped string)

| Key | বাংলা | English |
|---|---|---|
| meta.login | সাইন ইন | Sign in |
| login.passwordTitle | আবার স্বাগতম | Welcome back |
| login.passwordPanelTitle | আবার স্বাগতম। | Welcome back. |
| login.passwordSub | মোবাইল নম্বর আর পাসওয়ার্ড দিয়ে সাইন ইন করুন — অথবা যেকোনো সময় এসএমএস কোডে যান। | Sign in with your mobile number and password — or switch to an SMS code any time. |
| login.title | সাইন ইন করুন বা অ্যাকাউন্ট খুলুন | Sign in or create an account |
| login.sub | আপনার মোবাইল নম্বর দিন, আমরা এসএমএসে 6-সংখ্যার একটি কোড পাঠাব। | Enter your mobile number and we'll text you a 6-digit code. |
| login.heroSub | একটি মোবাইল নম্বর, একটি এসএমএস কোড। নতুন? যাচাই করলেই আপনার অ্যাকাউন্ট খুলে যাবে। | One mobile number, one SMS code. New here? Your account is made the moment you verify. |
| login.panelTitle | আপনার খেলনার ঝুড়ি, মনে রাখা থাকবে। | Your toy box, remembered. |
| login.panelBody | মোবাইল নম্বর দিয়ে সাইন ইন করে অর্ডার ট্র্যাক করুন, চেকআউটে সেভ করা ঠিকানা ব্যবহার করুন আর পছন্দের তালিকা রাখুন। নতুন? একই ফোন নম্বর আর এসএমএস কোডেই আপনার অ্যাকাউন্ট খুলে যাবে — আলাদা সাইন-আপ ফর্ম লাগবে না। | Sign in with your mobile number to track orders, reuse your address at checkout and keep a wishlist. New here? The same phone + SMS code creates your account — no separate sign-up form. |
| login.methodLabel (sr) | সাইন-ইনের পদ্ধতি | Sign-in method |
| login.tabPassword / tabSms | পাসওয়ার্ড / এসএমএস কোড | Password / SMS code |
| login.phone | মোবাইল নম্বর | Mobile number |
| login.phonePlaceholder | 01XXX XXX XXX | 01XXX XXX XXX |
| login.phoneHelp | এই নম্বরে একটি কোড পাঠানো হবে। | We'll text a code to this number. |
| login.password | পাসওয়ার্ড | Password |
| login.forgot | পাসওয়ার্ড ভুলে গেছেন? এর বদলে এসএমএস কোড দিয়ে সাইন ইন করুন। | Forgot your password? Sign in with an SMS code instead. |
| login.forgotShort | ভুলে গেছেন? এসএমএস কোড ব্যবহার করুন — পরে নতুন পাসওয়ার্ড দিতে পারবেন। | Forgot it? Use an SMS code instead — you can set a new password after. |
| login.passwordSubmit | সাইন ইন | Sign in |
| login.submit | এসএমএসে কোড পাঠান | Send code by SMS |
| login.passwordNote | এখনো পাসওয়ার্ড নেই? এসএমএস কোড দিয়ে সাইন ইন করে প্রোফাইল ও সেটিংসে একটি দিন। | No password yet? Sign in with an SMS code, then set one in Profile & settings. |
| login.smsNote | সাধারণ এসএমএস, কখনো হোয়াটসঅ্যাপ নয় — আপনার নিরাপত্তার জন্য। নম্বরটি নতুন হলে কোড যাচাই করলেই অ্যাকাউন্ট খুলে যাবে। | Standard SMS, never WhatsApp — for your safety. If this number is new, verifying the code creates your account. |
| login.newHere / createLink | Smart Junior-এ নতুন? / অ্যাকাউন্ট খুলুন | New to Smart Junior? / Create an account |
| login.guestTitle | শুধু কিনতে চান? | Just want to buy? |
| login.guestBody | অ্যাকাউন্ট ছাড়াই গেস্ট হিসেবে অর্ডার করতে পারেন। | You can check out as a guest — no account needed. |
| login.guestLink | কার্টে যান | Go to cart |
| benefits.title (sr) / titleSignIn / titleRegister | অ্যাকাউন্টে যা পাবেন / কেন সাইন ইন করবেন / আপনার অ্যাকাউন্টে যা থাকবে | What you get with an account / Why sign in / What your account gives you |
| benefits.ordersTitle / ordersBody | প্রতিটি অর্ডার ট্র্যাক করুন / প্যাকিং থেকে আপনার দরজা পর্যন্ত — পার্সেল কোথায় আছে দেখুন। | Track every order / See where each parcel is, from packing to your door. |
| benefits.addressesTitle / addressesBody | সেভ করা ঠিকানা / চেকআউটে আপনার ঠিকানা আর ল্যান্ডমার্ক নিজে থেকেই বসে যাবে। | Saved addresses / Checkout fills in your address and landmark for you. |
| benefits.wishlistTitle / wishlistBody | পছন্দের তালিকা / পছন্দের খেলনা সেভ করুন, যেকোনো ডিভাইসে খুঁজে পাবেন। | Your wishlist / Save toys you love and find them on any device. |
| benefits.birthdaysTitle / birthdaysBody | জন্মদিনের রিমাইন্ডার / সন্তানের জন্মদিন যোগ করলে আমরা অফারসহ মনে করিয়ে দিতে পারি — শুধু আপনি চাইলে। | Birthday reminders / Add your kids’ birthdays and we can remind you with an offer — only if you opt in. |
| benefits.trust | সাইন-ইন কোড শুধু এসএমএসে আসে — কখনো হোয়াটসঅ্যাপে নয়। পাসওয়ার্ড চান? প্রোফাইল ও সেটিংসে একটি দিন। | Sign-in codes come by SMS only — never WhatsApp. Prefer a password? Set one in Profile & settings. |
| register.title / panelTitle | আপনার অ্যাকাউন্ট খুলুন / এক মিনিটেই যোগ দিন। | Create your account / Join in one minute. |
| register.sub | শুধু আপনার নাম আর মোবাইল নম্বর। আপনিই কিনা নিশ্চিত করতে আমরা একটি কোড পাঠাব। | Your name and mobile number — that's all. We'll text a code to confirm it's you. |
| register.name / namePlaceholder | আপনার নাম / যেমন: সুমাইয়া আক্তার | Your name / e.g. Sumaiya Akter |
| register.nameHelp | আপনাকে কী নামে ডাকব, তা জানতে। পরে বদলাতে পারবেন। | So we know what to call you. You can change it later. |
| register.password / passwordHelp | একটি পাসওয়ার্ড দিন / অন্তত 8টি অক্ষর। ভুলে গেলেও এসএমএস কোড দিয়ে সাইন ইন করতে পারবেন। | Create a password / At least 8 characters. You can still sign in with an SMS code if you forget it. |
| register.passwordRepeat / passwordRepeatHelp | পাসওয়ার্ড আবার দিন / একই পাসওয়ার্ড আবার লিখুন। | Repeat password / Type the same password again. |
| register.required (sr) | আবশ্যক | required |
| register.submit | অ্যাকাউন্ট খুলুন · কোড পাঠান | Create account · send code |
| register.note | অ্যাকাউন্ট খোলার আগে আপনার নম্বর নিশ্চিত করতে আমরা একটি কোড পাঠাব। শুধু এসএমএস, কখনো হোয়াটসঅ্যাপ নয়। | We'll text a code to confirm your number before the account is made. SMS only, never WhatsApp. |
| register.alreadyTitle / alreadyBody | এই নম্বরে আগেই অ্যাকাউন্ট আছে / পাসওয়ার্ড দিয়ে সাইন ইন করুন, ভুলে গেলে এসএমএস কোড ব্যবহার করুন। | This number is already registered / Sign in with your password, or use an SMS code if you forgot it. |
| register.alreadySignIn / alreadyForgot | সাইন ইন করুন / পাসওয়ার্ড ভুলে গেছি | Sign in / Forgot password |
| register.haveAccount / signInLink | আগেই অ্যাকাউন্ট আছে? / সাইন ইন করুন | Already have an account? / Sign in |
| verify.title / sentTo | কোডটি দিন / {phone} নম্বরে এসএমএসে পাঠানো হয়েছে | Enter the code / Sent by SMS to {phone} |
| verify.back (aria) / change | নম্বর বদলান / ভুল নম্বর? বদলে নিন | Change number / Wrong number? Change it |
| verify.codeLabel (sr) / digit (aria) | এসএমএস-এর 6-সংখ্যার কোড / 6-এর মধ্যে {n} নম্বর সংখ্যা | The 6-digit code from the SMS / Digit {n} of 6 |
| verify.resendIn / resend | {time} পরে আবার কোড পাঠানো যাবে / আবার কোড পাঠান | Resend code in {time} / Resend code |
| verify.submit | যাচাই করে এগিয়ে যান | Verify & continue |
| verify.helpTitle / helpBody | কোড পাননি? / কিছু নেটওয়ার্কে কোড আসতে এক মিনিট লাগতে পারে। টাইমার শেষ হলে নতুন কোড চাইতে পারেন, অথবা {phone} নম্বরে ফোন করুন। | Didn't get it? / Codes can take a minute on some networks. You can ask for a new code when the timer ends, or call us on {phone}. |
| verify.newAccount | যাচাই করলে আপনি সাইন ইন হবেন — নম্বরটি নতুন হলে অ্যাকাউন্টও খুলে যাবে। | Verifying signs you in — or creates your account if this number is new. |
| verify.registerNote / resetNote | কোড যাচাই করলেই আপনার অ্যাকাউন্ট খুলে যাবে। / কোড যাচাই করলে নতুন পাসওয়ার্ড সেভ হবে আর আপনি সাইন ইন হবেন। | Verifying the code creates your account. / Verifying the code saves your new password and signs you in. |
| setPassword.title / panelTitle | আপনার পাসওয়ার্ড দিন / আপনি ঢুকে পড়েছেন। | Set your password / You’re in. |
| setPassword.sub | আপনি সাইন ইন করেছেন। একটি পাসওয়ার্ড বেছে নিন, যাতে পরেরবার মোবাইল নম্বর আর পাসওয়ার্ড দিয়ে সাইন ইন করতে পারেন। | You're signed in. Choose a password so next time you can sign in with your mobile number and password. |
| setPassword.panelBody | শেষ একটি ধাপ: পাসওয়ার্ড থাকলে এসএমএসের অপেক্ষা ছাড়াই সাইন ইন করতে পারবেন। আপনার অর্ডার, ঠিকানা আর পছন্দের তালিকা এই অ্যাকাউন্টে সেভ করাই আছে। | One last step: a password lets you sign in without waiting for an SMS. Your orders, addresses and wishlist are already saved to this account. |
| setPassword.submit | পাসওয়ার্ড সেভ করে এগিয়ে যান | Save password & continue |
| setPassword.note | পরে ভুলে গেলে? আপনার নম্বরে এসএমএস কোড সবসময় কাজ করবে, আর প্রোফাইল ও সেটিংসে পাসওয়ার্ড বদলাতে পারবেন। | Forget it later? An SMS code to your number always works, and you can change the password in Profile & settings. |
| resetPassword.title / sub | পাসওয়ার্ড নতুন করে দিন / আপনার মোবাইল নম্বর আর নতুন পাসওয়ার্ড দিন। আপনিই কিনা নিশ্চিত করতে আমরা একটি কোড পাঠাব। | Reset your password / Enter your mobile number and a new password. We'll text a code to confirm it's you. |
| profile.password.new / repeat | নতুন পাসওয়ার্ড / নতুন পাসওয়ার্ড আবার দিন | New password / Repeat new password |
| resetPassword.submit | এসএমএসে কোড পাঠান | Send code by SMS |
| resetPassword.note | নতুন পাসওয়ার্ড দিলে অন্য ডিভাইসগুলো থেকে সাইন আউট হয়ে যাবে। শুধু এসএমএস, কখনো হোয়াটসঅ্যাপ নয়। | Resetting signs you out on your other devices. SMS only, never WhatsApp. |
| notices.phoneInvalid | 013–019 দিয়ে শুরু হওয়া বাংলাদেশি মোবাইল নম্বর দিন। | Enter a Bangladeshi mobile number starting 013–019. |
| notices.nameInvalid | আপনার নাম দিন (অন্তত 2টি অক্ষর)। | Enter your name (at least 2 letters). |
| notices.codeInvalid | এসএমএস-এর 6-সংখ্যার কোডটি দিন। | Enter the 6-digit code from the SMS. |
| notices.passwordShort / passwordMismatch | অন্তত 8টি অক্ষর দিন। / দুটি পাসওয়ার্ড এক হয়নি। | Use at least 8 characters. / The two passwords are not the same. |
| notices.passwordRequired / passwordWrong | আপনার পাসওয়ার্ড দিন। / মোবাইল নম্বর আর পাসওয়ার্ড মিলছে না। | Enter your password. / That mobile number and password do not match. |
| notices.sent / expired | নতুন কোড পাঠানো হয়েছে। / কোডের মেয়াদ শেষ। নতুন কোড চান। | We sent a new code. / Your code expired. Ask for a new one. |
| notices.signedOut / sessionEnded | আপনি সাইন আউট করেছেন। / অনুগ্রহ করে আবার সাইন ইন করুন। | You're signed out. / Please sign in again. |
| notices.failed | কিছু একটা সমস্যা হয়েছে। আবার চেষ্টা করুন, অথবা {phone} নম্বরে ফোন করুন। | Something went wrong. Please try again, or call us on {phone}. |

Sample data in frames: phone `01712 345 678` (display form `01XXX XXX XXX`), name "সুমাইয়া আক্তার", timer `0:42`, hotline
`placeholder`. All numerals Western (rule 36); brand, WhatsApp and phone numbers in Fredoka (07 §7.3).

## 8. Deviations and open questions for the owner

Copy that no longer matches the 2026-11-20 decision (flagged, not rewritten — the owner or the copy pass decides):
1. **`login.passwordNote` and `benefits.trust`** say "set one in Profile & settings". Since 2026-11-20 the shopper is sent
   straight to the required **Set your password** page after an SMS sign-in. Suggest: "এখনো পাসওয়ার্ড নেই? এসএমএস কোড দিয়ে
   সাইন ইন করুন — তারপরই একটি পাসওয়ার্ড দিতে বলা হবে।" / "No password yet? Sign in with an SMS code — we'll ask you to set one
   right after."
2. **`register.sub`** says "Your name and mobile number — that's all", but the form also asks for a password twice.
3. **`login.panelBody`** says "no separate sign-up form", and there now is one (Create account). The SMS tab does still create
   an account for a new number (`docs/api/account.md` §3), so only that clause is stale.

Open questions:
4. **Forgot password link.** On the login page it opens the **SMS code tab** (sign in with a code, then the required set-password
   page); on "already registered" it opens **Reset your password**. Two routes to one task. Should login's link go to
   `/account/reset-password` too? (Design unchanged until answered; frames show today's link.)
5. **Confetti / panel colour.** The left panel was the candy-bloom gradient in the Toy Mart design. This spec uses flat
   `pastel/sky` with no confetti (07 §7.4 limits the pattern to listed sections). Confirm, or name a section to add it to.
6. **Mobile top bar.** Cart and checkout swap the site header for the Mobile Top Bar. These pages keep the full header (code
   today). Should "Enter the code" use the top bar (back + "কোডটি দিন") instead of its in-page back row?
7. **Hotline** in `verify.helpBody` and `notices.failed` is the sample number — `placeholder` until the owner confirms it.
8. **Voice-call fallback** after 3 failed codes (old Toy Mart design `237:9412`) is not built — no voice provider. Not drawn.

**Not designed — needs API:** nothing on these pages wants data the API lacks. The API sends no "code expires in" timer
(only `resend_in`), so no expiry countdown is drawn.
