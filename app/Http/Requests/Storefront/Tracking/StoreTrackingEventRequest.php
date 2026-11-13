<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Tracking;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use App\Domain\Tracking\DTOs\TrackingEventRecord;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Http\Requests\ApiFormRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * `POST /storefront/tracking/events` (docs/api/tracking.md §2.2): the server twin of a
 * browser event. Only `view_item`, `add_to_cart` and `begin_checkout` are accepted —
 * `purchase` and `refund` are server-originated only, and `page_view` is the browser's alone.
 * An unlisted name is a 422 `validation-error`.
 */
final class StoreTrackingEventRequest extends ApiFormRequest
{
    public const array ALLOWED = [
        TrackingEventName::ViewItem,
        TrackingEventName::AddToCart,
        TrackingEventName::BeginCheckout,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** UUID generated in the browser and used for the Pixel event too (Meta dedup). */
            'event_id' => ['required', 'string', 'uuid'],
            /** @var 'view_item'|'add_to_cart'|'begin_checkout' */
            'event_name' => ['required', 'string', Rule::in(array_map(static fn (TrackingEventName $n): string => $n->value, self::ALLOWED))],
            /** ISO 8601, when the browser saw it. */
            'occurred_at' => ['required', 'date'],
            'event_source_url' => ['required', 'string', 'max:2048', 'url:http,https'],
            /** Decimal string, e.g. "1250.00". */
            'value' => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'currency' => ['nullable', 'string', Rule::in(['BDT'])],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*' => ['array:item_id,item_name,price,quantity,item_brand,item_category,item_variant'],
            'items.*.item_id' => ['required', 'string', 'max:64'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.item_brand' => ['nullable', 'string', 'max:255'],
            'items.*.item_category' => ['nullable', 'string', 'max:255'],
            'items.*.item_variant' => ['nullable', 'string', 'max:255'],
            ...TrackingContextRules::rules('', true),
        ];
    }

    public function toRecord(): TrackingEventRecord
    {
        $occurred = CarbonImmutable::parse($this->string('occurred_at')->toString());
        // A browser clock in the future is clamped to now.
        if ($occurred->isFuture()) {
            $occurred = CarbonImmutable::now();
        }

        return new TrackingEventRecord(
            eventId: $this->string('event_id')->lower()->toString(),
            name: TrackingEventName::from($this->string('event_name')->toString()),
            source: TrackingSource::Browser,
            occurredAt: $occurred,
            consent: $this->consent(),
            value: $this->filled('value') ? $this->string('value')->toString() : null,
            currency: $this->filled('currency') ? $this->string('currency')->toString() : ($this->filled('value') ? 'BDT' : null),
            items: $this->items(),
            eventSourceUrl: $this->string('event_source_url')->toString(),
            sessionId: $this->client()->gaSessionId,
        );
    }

    public function client(): TrackingClientContext
    {
        $client = $this->input('client');

        return TrackingContextRules::client(is_array($client) ? $client : []);
    }

    public function consent(): ConsentState
    {
        $consent = $this->input('consent');

        return TrackingContextRules::consent(is_array($consent) ? $consent : []);
    }

    /** @return list<array{item_id:string,item_name:string,price:string,quantity:int,item_brand?:string,item_category?:string,item_variant?:string}> */
    private function items(): array
    {
        $items = [];
        foreach ((array) $this->input('items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $line = [
                'item_id' => (string) $item['item_id'],
                'item_name' => (string) $item['item_name'],
                'price' => (string) $item['price'],
                'quantity' => (int) $item['quantity'],
            ];
            foreach (['item_brand', 'item_category', 'item_variant'] as $key) {
                if (isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '') {
                    $line[$key] = $item[$key];
                }
            }
            $items[] = $line;
        }

        return $items;
    }
}
