@if ($isTest)
{{ __('monitoring.mail.test_note', [], $mailLocale) }}

@endif
{{ __('monitoring.mail.intro_'.$kind, [], $mailLocale) }}

{{ __('monitoring.names.'.$alertName, [], $mailLocale) }} · {{ __('monitoring.severity.'.$severity, [], $mailLocale) }}
{{ $summary }}

{{ __('monitoring.mail.checked_at', [], $mailLocale) }}: {{ $checkedAt }} · {{ $alertName }}
{{ __('monitoring.mail.runbook', [], $mailLocale) }}
