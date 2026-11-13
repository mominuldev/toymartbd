<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Actions\SetCustomerDateOfBirth;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Customer\UpdateCustomerDateOfBirthRequest;
use App\Http\Resources\Admin\Customers\CustomerDateOfBirthResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * Staff record a customer's date of birth on the profile (P8, birthday messages; Figma
 * B · 18 241:41374 has no frame for it). The birthday consent is a separate endpoint
 * (`PUT /notifications/customers/{customer}/consents`).
 */
final class CustomerDateOfBirthController extends ApiController
{
    /** `PUT /customers/{customer}/date-of-birth` — needs customers.update and customers.view_pii. */
    #[OpenApiResponse(403, 'Missing customers.update or customers.view_pii (`forbidden`)')]
    #[OpenApiResponse(422, 'Not a past Y-m-d date (`validation-error`)')]
    public function update(UpdateCustomerDateOfBirthRequest $request, Customer $customer, SetCustomerDateOfBirth $set): CustomerDateOfBirthResource
    {
        return new CustomerDateOfBirthResource($set->handle($customer, $request->dateOfBirth()));
    }
}
