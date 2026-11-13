{{ __('inventory.mail.digest_intro', ['date' => $digest['date']], $mailLocale) }}

@foreach (['out_of_stock', 'low_stock', 'drift'] as $kind)
{{ __('inventory.mail.kind.'.$kind, [], $mailLocale) }}: {{ $digest['raised'][$kind] }} / {{ $digest['open'][$kind] }}
@endforeach
{{ __('inventory.mail.pending_adjustments', [], $mailLocale) }}: {{ $digest['pending_adjustments'] }}

@foreach ($digest['rows'] as $row)
- {{ $row['product_name'] }}{{ $row['variant_name'] ? ' · '.$row['variant_name'] : '' }} ({{ $row['sku'] }}) {{ strtoupper($row['location_code']) }}: {{ $row['available'] }} / {{ $row['reorder_point'] }}{{ $row['raised_on_date'] ? ' · '.__('inventory.mail.new', [], $mailLocale) : '' }}
@endforeach

{{ $adminUrl }}
