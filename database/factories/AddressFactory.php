<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A Dhaka address in Bangla, with a landmark (rule 31). Creates the Dhaka division and
 * district rows if the geography seeder has not run.
 *
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    protected $model = Address::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $division = Division::query()->firstOrCreate(['name' => 'Dhaka'], ['name_bn' => 'ঢাকা']);
        $district = District::query()->firstOrCreate(['division_id' => $division->id, 'name' => 'Dhaka'], ['name_bn' => 'ঢাকা']);

        return [
            'customer_id' => Customer::factory(),
            'recipient_name' => 'ফাতেমা বেগম',
            'phone' => CustomerFactory::phone(),
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => null,
            'area' => 'ধানমন্ডি',
            'address_line' => 'বাড়ি 12, রোড 5',
            'landmark' => 'জামে মসজিদের পাশে',
            'type' => 'home',
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ];
    }
}
