<?php

declare(strict_types=1);

namespace App\Http\Problems;

/**
 * Stable, machine-readable RFC 9457 problem types (CLAUDE.md rule 18).
 *
 * Clients branch on `type`, never on `title` or `detail`. The URIs are part of the
 * v1 contract: add new cases freely, but never rename or remove one.
 */
enum ProblemType: string
{
    case BadRequest = 'bad-request';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not-found';
    case MethodNotAllowed = 'method-not-allowed';
    case Conflict = 'conflict';
    case CsrfTokenMismatch = 'csrf-token-mismatch';
    case ValidationError = 'validation-error';
    case TooManyRequests = 'too-many-requests';
    case ServiceUnavailable = 'service-unavailable';
    /**
     * `php artisan down` (planned downtime, docs/ops/DEPLOYMENT.md): every route answers this 503 with
     * `Retry-After`. Distinct from `service-unavailable` (a 503 for one feature, e.g. no courier rate
     * card): the storefront shows its maintenance page for this type only (Gate 5).
     */
    case Maintenance = 'maintenance';
    /** Idempotency-Key (P3-API-03), statuses per draft-ietf-httpapi-idempotency-key-header. */
    case IdempotencyKeyMissing = 'idempotency-key-missing';
    case IdempotencyKeyInvalid = 'idempotency-key-invalid';
    case IdempotencyKeyReused = 'idempotency-key-reused';
    case RequestInProgress = 'request-in-progress';
    /** Checkout (P4-ORD-02, docs/api/checkout.md): the re-priced total differs from the one shown. */
    case PriceChanged = 'price-changed';
    /** Checkout: the stock reservation failed for at least one line; `errors[]` lists each. */
    case InsufficientStock = 'insufficient-stock';
    /** Media library (docs/api/media-library.md): delete refused, the file is used; `usages[]` lists where. */
    case MediaInUse = 'media-in-use';
    /** Admin: the signed-in person can see money and has not set up two-factor yet (owner decision 2026-11-14). */
    case TwoFactorRequired = 'two-factor-required';
    /** Store settings › Branch setup: switching to a single shop refused while other branches are active; `blockers[]` lists them. */
    case BranchModeBlocked = 'branch-mode-blocked';
    /** Adding or switching on a branch refused while the store runs as a single shop (Branch setup). */
    case SingleBranchMode = 'single-branch-mode';
    /** AI (owner request 2026-11-17): no provider key for the feature, the monthly cap, or the provider failed. */
    case AiNotConfigured = 'ai-not-configured';
    case AiCapReached = 'ai-cap-reached';
    case AiUnavailable = 'ai-unavailable';
    case ServerError = 'server-error';
    /** Any other 4xx/5xx HttpException without a dedicated type. */
    case HttpError = 'http-error';

    public const string BASE_URI = 'https://toymartbd.com/problems/';

    public function uri(): string
    {
        return self::BASE_URI.$this->value;
    }

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated => 401,
            self::Forbidden, self::TwoFactorRequired => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::CsrfTokenMismatch => 419,
            self::ValidationError => 422,
            self::TooManyRequests => 429,
            self::ServiceUnavailable, self::Maintenance => 503,
            self::IdempotencyKeyMissing, self::IdempotencyKeyInvalid => 400,
            self::IdempotencyKeyReused => 422,
            self::RequestInProgress => 409,
            self::PriceChanged, self::InsufficientStock, self::MediaInUse, self::BranchModeBlocked, self::SingleBranchMode => 409,
            self::AiNotConfigured, self::AiCapReached => 409,
            self::AiUnavailable => 503,
            self::ServerError, self::HttpError => 500,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::BadRequest => 'Bad request',
            self::Unauthenticated => 'Unauthenticated',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::Conflict => 'Conflict',
            self::CsrfTokenMismatch => 'CSRF token mismatch',
            self::ValidationError => 'Validation failed',
            self::TooManyRequests => 'Too many requests',
            self::ServiceUnavailable => 'Service unavailable',
            self::Maintenance => 'Down for maintenance',
            self::IdempotencyKeyMissing => 'Idempotency-Key required',
            self::IdempotencyKeyInvalid => 'Idempotency-Key invalid',
            self::IdempotencyKeyReused => 'Idempotency-Key reused',
            self::RequestInProgress => 'Request in progress',
            self::PriceChanged => 'Price changed',
            self::InsufficientStock => 'Insufficient stock',
            self::MediaInUse => 'Media in use',
            self::TwoFactorRequired => 'Two-factor sign-in required',
            self::BranchModeBlocked => 'Other branches are still active',
            self::SingleBranchMode => 'Single-shop mode',
            self::AiNotConfigured => 'AI is not set up',
            self::AiCapReached => 'AI spending cap reached',
            self::AiUnavailable => 'AI is unavailable',
            self::ServerError => 'Server error',
            self::HttpError => 'HTTP error',
        };
    }

    /** Safe default `detail`, used whenever the exception message could leak internals. */
    public function defaultDetail(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be understood.',
            self::Unauthenticated => 'Authentication is required to access this resource.',
            self::Forbidden => 'You are not allowed to perform this action.',
            self::NotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'This HTTP method is not supported for this resource.',
            self::Conflict => 'The request conflicts with the current state of the resource.',
            self::CsrfTokenMismatch => 'The CSRF token is missing or has expired. Refresh and try again.',
            self::ValidationError => 'The given data was invalid.',
            self::TooManyRequests => 'Too many requests. Please wait before trying again.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
            self::Maintenance => 'The shop is being updated. Please try again in a few minutes.',
            self::IdempotencyKeyMissing => 'This endpoint requires an Idempotency-Key header.',
            self::IdempotencyKeyInvalid => 'The Idempotency-Key must be 8 to 255 printable ASCII characters without spaces.',
            self::IdempotencyKeyReused => 'This Idempotency-Key was already used with a different request.',
            self::RequestInProgress => 'A request with this Idempotency-Key is still being processed. Retry shortly.',
            self::PriceChanged => 'The order total has changed. Review the cart and place the order again.',
            self::InsufficientStock => 'Some items are no longer available in the quantity requested.',
            self::MediaInUse => 'This file is in use and cannot be deleted.',
            self::TwoFactorRequired => 'Your role can see money: set up two-factor sign-in before continuing.',
            self::BranchModeBlocked => 'Switch the other branches off before running the store as a single shop.',
            self::SingleBranchMode => 'The store runs as a single shop. Turn on multiple branches first.',
            self::AiNotConfigured => 'Connect an AI provider for this feature in Integrations › AI assistant.',
            self::AiCapReached => 'This month’s AI spending cap is reached. Raise it in Integrations › AI assistant, or wait until the 1st.',
            self::AiUnavailable => 'The AI provider did not give a usable answer. Try again in a moment.',
            self::ServerError => 'An unexpected error occurred. Please try again later.',
            self::HttpError => 'The request could not be completed.',
        };
    }

    public static function fromStatus(int $status): self
    {
        foreach (self::cases() as $case) {
            if ($case !== self::HttpError && $case !== self::ServerError && $case !== self::Maintenance && $case->status() === $status) {
                return $case;
            }
        }

        return $status >= 500 ? self::ServerError : self::HttpError;
    }
}
