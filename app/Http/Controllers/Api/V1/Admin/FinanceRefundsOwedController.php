<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Finance\Services\RefundsOwed;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\FinanceRefundsOwedResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** "Owed back to a customer" — the cash card's refunds-approved-but-unpaid line (finance.view). */
final class FinanceRefundsOwedController extends ApiController
{
    /** `GET /finance/reports/refunds-owed` — refunds approved but not paid out, as at `as_of` (default today, Dhaka). */
    #[QueryParameter('as_of', 'A Dhaka date, YYYY-MM-DD.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'as_of is not a date, or an unknown parameter (`bad-request`)')]
    public function __invoke(Request $request, RefundsOwed $owed): FinanceRefundsOwedResource
    {
        $this->authorize('viewReceivables', FinancialTransaction::class);

        $unknown = array_diff(array_keys($request->query()), ['as_of']);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown parameter: '.implode(', ', $unknown).'.');
        }

        $asOf = $request->query('as_of');
        if ($asOf !== null && (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || ! checkdate((int) substr($asOf, 5, 2), (int) substr($asOf, 8, 2), (int) substr($asOf, 0, 4)))) {
            throw new BadRequestHttpException('as_of must be a date, YYYY-MM-DD.');
        }
        $asOf = is_string($asOf) ? $asOf : DhakaDate::today();

        return new FinanceRefundsOwedResource(['as_of' => $asOf, ...$owed->asOf($asOf)]);
    }
}
