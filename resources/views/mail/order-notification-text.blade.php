{{ __('notifications.mail.greeting', ['name' => $customerName], $mailLocale) }}

@foreach ($paragraphs as $paragraph)
{{ $paragraph }}

@endforeach
{{ __('notifications.mail.order_number', [], $mailLocale) }} {{ $orderNumber }}
@foreach ($items as $item)
- {{ $item['name'] }}{{ $item['variant'] ? ' · '.$item['variant'] : '' }} × {{ $item['quantity'] }}: {{ $item['total'] }}
@endforeach
{{ __('notifications.mail.total', [], $mailLocale) }}: {{ $totals['total'] }}
{{ __('notifications.mail.due', [], $mailLocale) }}: {{ $totals['due'] }}

{{ __('notifications.mail.track', [], $mailLocale) }}: {{ $trackUrl }}
{{ __('notifications.mail.help', ['hotline' => $hotline], $mailLocale) }}
