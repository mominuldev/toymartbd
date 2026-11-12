// Account cookie names (P4-STO-11). Names only — client- and proxy-safe; no values or secrets.

/** The customer's Sanctum token. httpOnly + Secure + SameSite=Lax; read only on the server (rule 28). */
export const ACCOUNT_COOKIE = 'tm_account';
/** The phone between "send code" and "enter the code" (httpOnly, 10 minutes). */
export const OTP_COOKIE = 'tm_otp';
/** One-shot notice / field errors / typed values after a form post; proxy.ts expires it once a page has read it. */
export const ACCOUNT_FLASH_COOKIE = 'tm_account_flash';

export const OTP_COOKIE_MAX_AGE = 60 * 10;
