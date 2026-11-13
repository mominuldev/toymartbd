<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Finance;

use App\Domain\Finance\DTOs\SettlementLine;
use App\Domain\Finance\Models\CourierSettlement;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/**
 * `POST /finance/settlements` — import one courier remittance: `provider` (courier code),
 * `reference` (the courier's payment id), `received_at` (Dhaka date, not in the future),
 * optional `period_start`/`period_end` (both or neither; enables MISSING-line detection) and
 * `note`; then EITHER `file` (the portal export, CSV or XLSX, ≤ 5 MB) OR `lines[]` typed in
 * (`consignment_id`, `cod_amount`, optional `delivery_charge`, `cod_fee`, `return_charge`,
 * `net_amount`; ≤ 500). Needs finance.reconcile_cod and an `Idempotency-Key`.
 */
final class ImportSettlementRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CourierSettlement::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'];

        return [
            'provider' => ['required', 'string', 'exists:courier_providers,code'],
            'reference' => ['required', 'string', 'max:120'],
            'received_at' => ['required', 'date_format:Y-m-d', 'before:'.CarbonImmutable::now('Asia/Dhaka')->addDay()->toDateString()],
            'period_start' => ['nullable', 'date_format:Y-m-d', 'required_with:period_end'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'required_with:period_start', 'after_or_equal:period_start'],
            'note' => ['nullable', 'string', 'max:500'],
            'file' => ['required_without:lines', 'prohibits:lines', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
            'lines' => ['required_without:file', 'array', 'min:1', 'max:500'],
            'lines.*.consignment_id' => ['required', 'string', 'max:64'],
            'lines.*.cod_amount' => ['required', ...$money],
            'lines.*.delivery_charge' => ['nullable', ...$money],
            'lines.*.cod_fee' => ['nullable', ...$money],
            'lines.*.return_charge' => ['nullable', ...$money],
            'lines.*.net_amount' => ['nullable', 'string', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    public function providerCode(): string
    {
        return (string) $this->validated('provider');
    }

    public function reference(): string
    {
        return trim((string) $this->validated('reference'));
    }

    public function receivedAt(): CarbonImmutable
    {
        return $this->dhakaDate('received_at') ?? CarbonImmutable::now('Asia/Dhaka');
    }

    public function periodStart(): ?CarbonImmutable
    {
        return $this->dhakaDate('period_start');
    }

    public function periodEnd(): ?CarbonImmutable
    {
        return $this->dhakaDate('period_end');
    }

    public function note(): ?string
    {
        $v = $this->validated('note');

        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    public function statement(): ?UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function statementFormat(): string
    {
        return strtolower((string) $this->statement()?->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
    }

    /** @return list<SettlementLine> typed-in lines (manual entry) */
    public function manualLines(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines') ?? [];
        $m = static fn (mixed $v): Money => is_string($v) && $v !== '' ? Money::of($v) : Money::zero();
        $out = [];
        foreach ($lines as $i => $line) {
            $out[] = new SettlementLine(
                lineNo: $i + 1,
                consignmentId: trim((string) $line['consignment_id']),
                codAmount: $m($line['cod_amount'] ?? null),
                charges: $m($line['delivery_charge'] ?? null),
                codFee: $m($line['cod_fee'] ?? null),
                returnCharge: $m($line['return_charge'] ?? null),
                net: isset($line['net_amount']) && is_string($line['net_amount']) && $line['net_amount'] !== '' ? Money::of($line['net_amount']) : null,
            );
        }

        return $out;
    }

    private function dhakaDate(string $key): ?CarbonImmutable
    {
        $v = $this->validated($key);

        return is_string($v) ? CarbonImmutable::createFromFormat('!Y-m-d', $v, 'Asia/Dhaka') ?: null : null;
    }
}
