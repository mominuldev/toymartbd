<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Finance\Listeners\Purchasing\BookThirdPartyLandedCosts;
use App\Domain\Finance\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Default expense categories (P5 finance; checklist §2.13: ads, rent, salaries, packaging,
 * utilities). English and Bangla names (rule 34). Idempotent: matched on the English name.
 */
final class ExpenseCategoriesSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string|null, 4: bool, 5?: string}> name, name_bn, type, ledger_type, is_fixed, parent */
    public const array CATEGORIES = [
        ['Rent', 'ভাড়া', 'operating', 'rent', true],
        ['Salaries', 'বেতন', 'operating', 'salary', true],
        ['Utilities', 'ইউটিলিটি বিল', 'operating', 'utility', true],
        ['Packaging', 'প্যাকেজিং', 'logistics', 'packaging', false],
        ['Courier bills', 'কুরিয়ার বিল', 'logistics', 'courier_charge', false],
        ['Advertising', 'বিজ্ঞাপন', 'marketing', 'marketing', false],
        ['Facebook ads', 'ফেসবুক বিজ্ঞাপন', 'marketing', 'marketing', false, 'Advertising'],
        ['Google ads', 'গুগল বিজ্ঞাপন', 'marketing', 'marketing', false, 'Advertising'],
        ['Import duty & freight', 'আমদানি শুল্ক ও পরিবহন', 'cogs', 'cogs', false],
        ['Other', 'অন্যান্য', 'other', null, false],
        // Freight and customs paid to a third party on stock purchases. Capitalised (set in
        // run()): the cost is already in the goods' landed cost, so these expenses are cash
        // out only and never a P&L line — it reaches the P&L as COGS on sale.
        [BookThirdPartyLandedCosts::CATEGORY, BookThirdPartyLandedCosts::CATEGORY_BN, 'cogs', null, false],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $row) {
            $parentId = isset($row[5]) ? ExpenseCategory::query()->where('name', $row[5])->whereNull('parent_id')->value('id') : null;
            ExpenseCategory::query()->firstOrCreate(
                ['name' => $row[0], 'parent_id' => $parentId],
                ['name_bn' => $row[1], 'type' => $row[2], 'ledger_type' => $row[3], 'is_fixed' => $row[4], 'capitalised' => $row[0] === BookThirdPartyLandedCosts::CATEGORY, 'is_active' => true],
            );
        }

    }
}
