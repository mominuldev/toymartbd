<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

/**
 * Bengali digits and stray spaces from an SMS autofill are the same code (P4-STO-11). For the
 * requests that carry a 6-digit `code`.
 */
trait NormalisesOtpCode
{
    protected function prepareForValidation(): void
    {
        $code = $this->input('code');
        if (is_string($code)) {
            $code = str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $code);
            $this->merge(['code' => preg_replace('/\s+/u', '', $code)]);
        }
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
