<?php

declare(strict_types=1);

use App\Support\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;

it('masks a Bangladeshi mobile number in any prefix form', function (string $input, string $expected): void {
    expect(RedactSensitiveData::maskPhones($input))->toBe($expected);
})->with([
    'national' => ['call 01712345678 now', 'call 017****5678 now'],
    'e164' => ['+8801812345678', '018****5678'],
    'no plus' => ['8801912345678', '019****5678'],
    'two in one string' => ['01312345678,01412345678', '013****5678,014****5678'],
    'inside json' => ['{"phone":"+8801512345678"}', '{"phone":"015****5678"}'],
]);

it('leaves numbers that are not BD mobiles alone', function (string $input): void {
    expect(RedactSensitiveData::maskPhones($input))->toBe($input);
})->with([
    'order number' => ['TMB-20261111-0042'],
    '012 prefix' => ['01212345678'],
    'part of a longer digit run' => ['9901712345678123'],
    'too short' => ['0171234567'],
]);

it('redacts sensitive keys case-insensitively and at any depth', function (): void {
    $clean = RedactSensitiveData::redactArray([
        'Password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'laravel_session=x', 'X-Api-Key' => 'k', 'accept' => 'json'],
        'gateway' => ['credentials' => ['store_password' => 's'], 'client_secret' => 's', 'access_token' => 't'],
        'customer' => ['name' => 'রহিম', 'phone' => '+8801712345678', 'address_line' => 'House 12, Road 5', 'shipping_address' => ['line' => 'x']],
        'address' => 'Dhanmondi',
        'items' => [['sku' => 'LEGO-1', 'qty' => 2]],
    ]);

    expect($clean['Password'])->toBe('[redacted]')
        ->and($clean['password_confirmation'])->toBe('[redacted]')
        ->and($clean['headers'])->toBe(['Authorization' => '[redacted]', 'Cookie' => '[redacted]', 'X-Api-Key' => '[redacted]', 'accept' => 'json'])
        ->and($clean['gateway'])->toBe(['credentials' => '[redacted]', 'client_secret' => '[redacted]', 'access_token' => '[redacted]'])
        ->and($clean['customer'])->toBe(['name' => 'রহিম', 'phone' => '017****5678', 'address_line' => '[redacted]', 'shipping_address' => '[redacted]'])
        ->and($clean['address'])->toBe('[redacted]')
        ->and($clean['items'])->toBe([['sku' => 'LEGO-1', 'qty' => 2]]);
});

it('scrubs a whole Monolog record: message, context and extra', function (): void {
    $record = new LogRecord(
        new DateTimeImmutable,
        'test',
        Level::Info,
        'OTP sent to 01712345678',
        ['token' => 'abc', 'to' => '01712345678'],
        ['request_id' => '01J0000000000000000000000A', 'secret' => 'x'],
    );

    $clean = (new RedactSensitiveData)($record);

    expect($clean->message)->toBe('OTP sent to 017****5678')
        ->and($clean->context)->toBe(['token' => '[redacted]', 'to' => '017****5678'])
        ->and($clean->extra)->toBe(['request_id' => '01J0000000000000000000000A', 'secret' => '[redacted]']);
});

it('redacts secrets embedded in free text', function (string $input, string $expected): void {
    expect(RedactSensitiveData::scrubText($input))->toBe($expected);
})->with([
    'raw json body' => ['{"phone":"01812345678","password":"p@ss","address_line":"House 12","qty":2}', '{"phone":"018****5678","password":"[redacted]","address_line":"[redacted]","qty":2}'],
    'json inside a json string' => ['{"body":"{\"api_key\":\"k-123\"}"}', '{"body":"{\"api_key\":\"[redacted]\"}"}'],
    'query string' => ['/callback?token=abc123&page=2', '/callback?token=[redacted]&page=2'],
    'bearer header' => ['Authorization: Bearer tmbd_abc.def', 'Authorization: Bearer [redacted]'],
    'nothing sensitive' => ['order TMB-0042 shipped', 'order TMB-0042 shipped'],
]);
