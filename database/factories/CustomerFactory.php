<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customers with Bangla names and valid 013–019 numbers stored E.164 (rule 30).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    private const array NAMES = ['রহিম উদ্দিন', 'ফাতেমা বেগম', 'নুসরাত জাহান', 'আরিফ হোসেন', 'Tanvir Ahmed', 'সাদিয়া ইসলাম'];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(self::NAMES),
            'phone' => self::phone(),
            'preferred_language' => 'bn',
            'source' => 'web',
            'status' => 'active',
        ];
    }

    /** A unique valid Bangladeshi mobile in E.164 (prefixes 013–019 only). */
    public static function phone(): string
    {
        return '+8801'.fake()->numberBetween(3, 9).fake()->unique()->numerify('########');
    }
}
