<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Jobs;

use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\DispatchPolicy;
use App\Domain\Tracking\Services\TrackingDestinations;
use App\Domain\Tracking\Services\TrackingEventDataFactory;
use App\Domain\Tracking\Support\SentPayloadScrubber;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sends one recorded event to every destination the {@see DispatchPolicy} allows, on the
 * `tracking` queue (SYSTEM-ARCHITECTURE §7): 3 tries with backoff, and NEVER past one hour —
 * a conversion that old is dropped as `skipped_stale` (a stale conversion skews the ad
 * platform's optimisation more than a missing one).
 *
 * Each destination is settled independently: a destination already `sent` / skipped is
 * never touched again, so a retry after GA4 succeeded and Meta hit a 503 sends only to Meta.
 * A 4xx is a terminal `failed` (the driver returns it); only a transport error, 429 or 5xx
 * ({@see RetryableTrackingFailure}) is retried. Runs outside any DB transaction (rule 4).
 *
 * The client context (IP, user agent, click ids) rides in this job's payload only — it is
 * never written to `tracking_events`.
 */
final class DispatchTrackingEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $trackingEventId,
        public readonly ?TrackingClientContext $client = null,
        public readonly ?string $eventSourceUrl = null,
    ) {
        $this->onQueue((string) config('tracking.queue', 'tracking'));
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** Never retried past the staleness window, whatever `tries` says. */
    public function retryUntil(): DateTimeInterface
    {
        $created = TrackingEvent::query()->whereKey($this->trackingEventId)->value('created_at');

        return Carbon::parse(is_string($created) || $created instanceof DateTimeInterface ? $created : now())
            ->addSeconds(self::staleAfter());
    }

    public function handle(TrackingDestinations $destinations, DispatchPolicy $policy, TrackingEventDataFactory $factory): void
    {
        $row = TrackingEvent::query()->find($this->trackingEventId);
        if ($row === null) {
            return;
        }

        if ($row->created_at !== null && $row->created_at->lt(now()->subSeconds(self::staleAfter()))) {
            $this->settlePending($row, DispatchStatus::SkippedStale, 'Older than '.self::staleAfter().' s: dropped, never retried (SYSTEM-ARCHITECTURE §7).');

            return;
        }

        $data = $factory->make($row, $this->client, $this->eventSourceUrl);
        $retry = null;

        foreach (TrackingDestinations::KEYS as $key) {
            if ($row->statusFor($key) !== DispatchStatus::Pending) {
                continue;
            }
            $destination = $destinations->get($key);
            $decision = $policy->decide($row->event_name, $row->source, $key, $destination, $data->consent);
            if ($decision !== null || $destination === null) {
                $row->setAttribute($key.'_status', $decision ?? DispatchStatus::SkippedDisabled);
                $row->save();

                continue;
            }

            $row->setAttribute($key.'_attempts', $row->getAttribute($key.'_attempts') + 1);
            try {
                $result = $destination->send($data);
            } catch (RetryableTrackingFailure $e) {
                $row->setAttribute($key.'_response', self::response(null, $e->httpStatus, null, $e->getMessage()));
                $row->save();
                $retry ??= $e;

                continue;
            }
            $this->record($row, $key, $result);
        }

        if ($retry !== null) {
            // retryUntil() takes precedence over $tries in Laravel, so the 3-try cap is
            // enforced here: the third retryable failure is final.
            if ($this->attempts() >= $this->tries) {
                $this->settlePending($row, DispatchStatus::Failed, $retry->getMessage());

                return;
            }

            // Honour the provider's Retry-After (429 / Meta throttling) when it gave one.
            if ($retry->retryAfterSeconds !== null && $this->job !== null) {
                $this->release(min(max($retry->retryAfterSeconds, 1), 600));

                return;
            }

            throw $retry;
        }

        $this->markDispatchedIfSettled($row);
    }

    /** Out of tries (or past retryUntil): whatever is still pending is `failed`. */
    public function failed(?Throwable $e): void
    {
        $row = TrackingEvent::query()->find($this->trackingEventId);
        if ($row !== null) {
            $this->settlePending($row, DispatchStatus::Failed, $e instanceof RetryableTrackingFailure ? $e->getMessage() : 'Dispatch failed after retries.');
        }
    }

    private function record(TrackingEvent $row, string $key, DispatchResult $result): void
    {
        $status = $result->status === DispatchStatus::Pending ? DispatchStatus::Failed : $result->status;
        $row->setAttribute($key.'_status', $status);
        $row->setAttribute($key.'_response', self::response($status, $result->httpStatus, $result->responseExcerpt, $result->error));
        if ($result->sentPayload !== []) {
            $payload = $row->payload ?? [];
            $sent = is_array($payload['sent'] ?? null) ? $payload['sent'] : [];
            $sent[$key] = SentPayloadScrubber::scrub($result->sentPayload);
            $payload['sent'] = $sent;
            $row->payload = $payload;
        }
        $row->save();
    }

    private function settlePending(TrackingEvent $row, DispatchStatus $status, string $reason): void
    {
        foreach (TrackingDestinations::KEYS as $key) {
            if ($row->statusFor($key) === DispatchStatus::Pending) {
                $row->setAttribute($key.'_status', $status);
                $existing = $row->getAttribute($key.'_response');
                $row->setAttribute($key.'_response', (is_array($existing) ? $existing : []) + ['error' => $reason]);
            }
        }
        $row->dispatched_at ??= now();
        $row->save();
    }

    private function markDispatchedIfSettled(TrackingEvent $row): void
    {
        foreach (TrackingDestinations::KEYS as $key) {
            if ($row->statusFor($key) === DispatchStatus::Pending) {
                return;
            }
        }
        $row->dispatched_at ??= now();
        $row->save();
    }

    /** @return array{status: string|null, http_status: int|null, excerpt: string|null, error: string|null, at: string} */
    private static function response(?DispatchStatus $status, ?int $http, ?string $excerpt, ?string $error): array
    {
        return [
            'status' => $status?->value,
            'http_status' => $http,
            'excerpt' => $excerpt === null ? null : mb_substr($excerpt, 0, 2048),
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'at' => now()->toIso8601String(),
        ];
    }

    private static function staleAfter(): int
    {
        return (int) config('tracking.stale_after_seconds', 3600);
    }
}
