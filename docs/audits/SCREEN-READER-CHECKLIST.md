# Admin screen-reader checklist (VoiceOver and TalkBack)

**What this is:** a script the owner (or any staff member) can follow by hand. It covers six admin flows: sign in with two-factor, Staff & roles, the order board and an order, creating an order, editing a product, and booking a courier. Run each flow once in English and once in Bangla. Allow 45–60 minutes for the full set.

**Why by hand:** `e2e/admin-a11y/screen-reader.spec.ts` checks the accessibility tree that browsers pass to screen readers, and it runs in CI. It cannot check what a real reader actually says, how a Bangla voice pronounces the text, or whether the order of speech makes sense. Only a person listening can judge those.

## Before you start

| | VoiceOver on a Mac (Safari) | TalkBack on Android (Chrome) |
|---|---|---|
| Turn on | **⌘ F5**, or System Settings › Accessibility › VoiceOver | Settings › Accessibility › TalkBack, or hold both volume keys for 3 s |
| The "VO" key | **Control + Option** (written **VO** below) | — |
| Next / previous item | **VO →** / **VO ←** | Swipe right / left |
| Activate | **VO Space** | Double-tap |
| Headings list | **VO U**, then ← → to the Headings list | Swipe up then down to set "Headings", then swipe right |
| Landmarks list | **VO U** › Landmarks | Reading controls › "Landmarks" |
| Type in a field | **VO Space** on the field, then type | Double-tap, then use the keyboard |
| Stop speech | **Control** | Two-finger tap |
| Bangla voice | System Settings › Accessibility › VoiceOver › Open VoiceOver Utility › Speech › add **Bangla (India) – Piya** (or any Bangla voice), set Language to "Detect" | Settings › System › Languages › Text-to-speech › Google engine › install **Bangla (Bangladesh)** |

- **Desktop:** use the admin at its usual address, at full width, in Safari. **Phone:** use Chrome. The admin works at 390 px; the side menu opens from the ☰ button.
- **Account:** sign in as the owner. Two-factor needs an account that already has it on (see flow 1).
- **Switching to Bangla:** use **EN | বাং** in the top bar (on the sign-in page, top right). The page language changes with it. A Bangla voice should take over, and every number should still be read with Western digits (rule 36).

## How to report a failure

Copy one line per problem into the batch issue (or `docs/audits/ACCESSIBILITY-AND-BILINGUAL.md` › Findings):

```
SR-<n> | <VoiceOver/Safari or TalkBack/Chrome> | <EN/BN> | <flow + step> | Heard: "<exact words>" | Expected: "<words from this sheet>" | <blocker / annoying / cosmetic>
```

- **Blocker:** you cannot finish the task without seeing the screen.
- **Annoying:** you can finish, but it is slow, repeats itself or misleads.
- **Cosmetic:** wording or pronunciation only.

If you can, also note what the page showed at that moment. A screenshot is fine.

---

## 1 · Sign in and the two-factor code step

**Setup, once:** sign in as a staff member, open **Account › Two-factor** and turn it on with an authenticator app. Save the recovery codes. Sign out.

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 1.1 | Open `/admin/login` | "Sign in to Admin, heading level 1" somewhere in the first few items | "অ্যাডমিনে সাইন ইন করুন, শিরোনাম ১" |
| 1.2 | Move through the form | "Email, edit text" → "Password, secure edit text" → "Keep me signed in on this device, checkbox, unchecked" → "Sign in, button" | "ইমেইল…" → "পাসওয়ার্ড…" → "এই ডিভাইসে সাইন ইন রাখুন…" → "সাইন ইন, বোতাম" |
| 1.3 | Enter a wrong password and press Sign in | The error is spoken right away, without you moving: "Those details did not match an active staff account." Going back to Email gives "invalid data" and the same sentence | "এই তথ্যের সাথে কোনো সক্রিয় স্টাফ অ্যাকাউন্ট মেলেনি।" |
| 1.4 | Enter the right password | Focus jumps to the code field: "Code from the app, edit text". The heading "Enter your sign-in code" is nearby | "অ্যাপের কোড…", heading "সাইন-ইন কোড দিন" |
| 1.5 | Activate "Use a recovery code" | Focus stays in the field, and the field is now called "Recovery code" | "রিকভারি কোড" |
| 1.6 | Type `wrong-code-1234` and press Sign in | The error is spoken right away. The field reads as "invalid", with the error as its description | Same, in Bangla |
| 1.7 | Activate "Start again" | Focus returns to "Email, edit text" | "ইমেইল" |
| 1.8 | Enter a real code | You land on the Overview: "Overview, heading level 1" | "ওভারভিউ…" |

## 2 · Staff & roles

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 2.1 | On any admin page, press **Tab** once | "Skip to content, link". Activate it: focus moves into the page content | "মূল অংশে যান" |
| 2.2 | Open **Staff & roles**. Open the Landmarks list | Main navigation, banner, main, plus "Breadcrumb", "Staff & roles sections" (and on Roles, the save bar region once something has changed) | "প্রধান নেভিগেশন"… |
| 2.3 | Headings list | "Staff & roles" (level 1) is the only level 1 | "স্টাফ ও ভূমিকা" |
| 2.4 | Reach the section links | "People 7, current page, link" and "Roles & permissions 7, link" | |
| 2.5 | Move through the table (VO + arrows, or swipe) | Column names are read with each cell. Farhana's two-factor cell says "Off. Their role can see money: they must set up two-factor at their next sign-in." | Bangla sentence |
| 2.6 | Activate "Manage Farhana Akter · ফারহানা আক্তার" | "Manage Farhana…, dialog" and its heading. Focus is inside. **The two-factor sentence is spoken once, not twice** | "…পরিচালনা করুন" |
| 2.7 | Press **Escape** (on a phone, the Close/Cancel button) | The dialog closes and focus returns to "Manage Farhana…, button" | |
| 2.8 | Open **Roles & permissions**. Move into the table | "Permissions by role, table". The cells read like "View dashboard for Manager, checkbox, checked". The owner column reads "Owner role: every permission, always" | "ভূমিকা অনুযায়ী অনুমতি" |
| 2.9 | Tick one box | Without moving, you hear "Unsaved changes to 1 role(s)". That column's header now ends "(changed, not saved)" | "1টি ভূমিকায় পরিবর্তন সংরক্ষণ হয়নি" |
| 2.10 | Find **Discard** in the save bar and activate it | The box is back to how it was. **Do not press Save** unless you mean to change the role | |

## 3 · Orders board → order detail

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 3.1 | Open **Orders** | "Orders, heading level 1" | "অর্ডার" |
| 3.2 | Reach the status filters | "Order status, navigation", then "All 136, toggle button, selected", "Pending 6, toggle button"… | "অর্ডারের অবস্থা" |
| 3.3 | Activate "Delivered" | It now reads "selected", and "All" reads as not selected | |
| 3.4 | Move into the table, then to the first order number | "TMB-26-…, link". Phone numbers are masked (016****5124) and read with Western digits | Digits stay 0–9 |
| 3.5 | Open the order. Headings list | Level 1 is the order number. The level 2 headings are: Where this order is, Items, Payments, Returns & refunds, Shipment…, Activity, Customer, Delivery address, Toy Mart risk score, Courier-verified delivery record | |
| 3.6 | Move through "Where this order is" | Three lists. Each step reads like "Pending — done" or "Delivered — current". **You should not hear "orders dot status" or "payment underscore status"** | |
| 3.7 | Compare the risk regions | "Toy Mart risk score" and "Courier-verified delivery record" are separate regions. The second says "Provider not connected", never "0" (rule 33b) | |
| 3.8 | On a *Confirmed* order, activate **Hold** | "Hold TMB-26-…?, dialog", with focus inside. **Escape** closes it, and focus returns to "Hold, button". Do not submit | "…স্থগিত করবেন?" |
| 3.9 | (Optional, changes data) Activate **Start processing** | A toast is spoken without you moving: "TMB-26-… is processing" | |

## 4 · Create order

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 4.1 | Open **Orders › New order**. Headings list | "New order" (1), then "1 · Customer", "2 · Delivery address", "3 · Products", "4 · Notes", "Summary", "Payment" (2) | "নতুন অর্ডার", "3 · পণ্য"… |
| 4.2 | Type a phone number such as `01711000000` | Without moving, a customer card is spoken: either "New customer…" or "Returning customer…" | |
| 4.3 | Reach **Landmark** | "Landmark, edit text", then its hint: "Reduces failed deliveries — riders navigate by landmarks" | "ল্যান্ডমার্ক… ব্যর্থ ডেলিভারি কমায়…" |
| 4.4 | In "Search name, SKU or scan barcode" (a **search field**), type `lego` | After a moment, without moving: "5 matching products — press Tab to choose one" (the number varies) | "…টি পণ্য মিলেছে — বেছে নিতে Tab চাপুন" |
| 4.5 | Press **Tab** (on a phone, swipe right) | "Add LEGO …, button". Activate it | "… যোগ করুন" |
| 4.6 | Move through the added line | "One fewer …, dimmed button", "Quantity of …", "One more …, button", "Remove …, button" | |
| 4.7 | Type `zzzz` in the search field | "No sellable product matches “zzzz”." | Bangla sentence |
| 4.8 | Reach **Payment** | "Payment, radio group". "Cash on delivery…, selected". The other two are dimmed | |
| 4.9 | Leave without creating (or create a test order only if you mean to) | — | |

## 5 · Product edit

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 5.1 | Open **Products** and then any product | The product name is heading level 1. There is a "Product sections" navigation with links General, Media, Pricing… | |
| 5.2 | Reach "নাম (বাংলা)" | **A Bangla voice reads the Bangla label and the value**, even with the admin in English | |
| 5.3 | Reach the description | "Description formatting, toolbar". Bold, Italic… are buttons. Then "Description, edit text, multi-line" with the text read | |
| 5.4 | Reach "Search & social" | "Basics, tab, selected, 1 of 5". Move to "Google preview" and activate it | |
| 5.5 | In the Variants table, reach a price | "Price — 90 pcs / Box, edit text, 1450.00". Every cell names its variant | |
| 5.6 | Clear "Name (English)" | Without moving, from the save bar: "1 unsaved change" | "1টি পরিবর্তন সংরক্ষণ হয়নি" |
| 5.7 | Activate **Save changes** | The name field reads as "invalid", with the error as its description | |
| 5.8 | Activate **Discard** | The name comes back. Nothing was saved | |

## 6 · Courier booking

| # | Do | You should hear (EN) | (BN) |
|---|---|---|---|
| 6.1 | Open a **Ready to ship** order that has no shipment. Find "Shipment" | "Book courier, button" | "কুরিয়ার বুক করুন" |
| 6.2 | Activate it | "Book courier — TMB-26-…, dialog". Focus is inside the drawer | "কুরিয়ার বুক — …" |
| 6.3 | Move through the drawer | The note "Steadfast has never been tested against a live account". "Courier, group" with radio buttons named after each courier and its rates. "Weight, edit text, 900" with the hint "From variant weights…". "Note to courier, optional" | |
| 6.4 | Reach the footer | An alert says why booking is blocked: "No credentials — add them in courier settings". "Book with Steadfast · ৳167" is dimmed | |
| 6.5 | Press **Escape** | The drawer closes, and focus returns to "Book courier, button" | |
| 6.6 | (Once a courier account exists, on a low-value order) Book it | Without moving: "Sending to Steadfast…", then "…requested" with the shipment number. Focus lands on the result | |

---

## What the CI test already proves, and what only you can check

| Automated (`npm run test:a11y:admin`, CI job "Admin a11y") | Only a person can judge |
|---|---|
| Landmarks, the heading outline, control names, roles and states (pressed, checked, selected, disabled, invalid) | Whether the speech order makes sense, and whether the wording is natural in Bangla |
| Dialog names, focus inside on open, focus back on the trigger | Whether VoiceOver and TalkBack actually announce live regions (each one queues them differently) |
| The live regions exist before they change (roles matrix, product search, booking result) | Bangla pronunciation, and whether the reader switches voice on `lang="bn"` |
| Errors are tied to their field (`aria-describedby`) | TalkBack gestures in the drawer and dialogs on a real phone |
