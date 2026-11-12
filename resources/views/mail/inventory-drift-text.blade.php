{{ __('inventory.mail.drift_intro', ['count' => count($drifts)], $mailLocale) }}
{{ __('inventory.mail.drift_body', [], $mailLocale) }}

@foreach ($drifts as $drift)
- {{ $drift['product_name'] }} ({{ $drift['sku'] }}) {{ strtoupper($drift['location_code']) }} · {{ __('inventory.mail.kind.'.$drift['kind'], [], $mailLocale) }}: {{ __('inventory.mail.col_expected', [], $mailLocale) }} {{ $drift['expected'] }}, {{ __('inventory.mail.col_actual', [], $mailLocale) }} {{ $drift['actual'] }}
@endforeach

{{ $adminUrl }}
