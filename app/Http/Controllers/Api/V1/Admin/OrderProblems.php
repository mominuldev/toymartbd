<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The create / edit order endpoints' refusals as RFC 9457 problems with field-level
 * `errors[]` (rule 18) — the same types storefront checkout uses: `price-changed`,
 * `insufficient-stock` (409) and `validation-error` (422).
 *
 * @internal
 */
final class OrderProblems
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T|JsonResponse
     */
    public static function guard(Request $request, Closure $action): mixed
    {
        try {
            return $action();
        } catch (PriceChanged $e) {
            return self::problem($request, ProblemType::PriceChanged, [[
                'field' => 'expected_total',
                'message' => (string) __('checkout.price_changed', ['total' => $e->actual->toDecimal()]),
            ]]);
        } catch (InsufficientStock $e) {
            $errors = [];
            foreach ($e->lines as $variantId => $line) {
                $errors[] = [
                    'field' => 'lines.'.$variantId,
                    'message' => $line['available'] > 0
                        ? (string) __('cart.stock.only_left', ['available' => $line['available']])
                        : (string) __('cart.stock.out_of_stock'),
                ];
            }

            return self::problem($request, ProblemType::InsufficientStock, $errors);
        } catch (CheckoutNotReady $e) {
            return self::problem($request, ProblemType::ValidationError, $e->errors);
        } catch (CouponRejected $e) {
            return self::problem($request, ProblemType::ValidationError, [['field' => 'coupon_code', 'message' => $e->getMessage()]]);
        }
    }

    /** @param  list<array{field: string, message: string}>  $errors */
    private static function problem(Request $request, ProblemType $type, array $errors): JsonResponse
    {
        $detail = $type === ProblemType::ValidationError ? $type->defaultDetail() : ($errors[0]['message'] ?? $type->defaultDetail());

        return (new ProblemDetails($type, $type->status(), $detail, $errors))->toResponse($request);
    }
}
