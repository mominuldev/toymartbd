<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Actions\AddToWishlist;
use App\Domain\Customer\Actions\RequestLoginOtp;
use App\Domain\Customer\Actions\SaveCustomerAddress;
use App\Domain\Customer\Actions\UpdateCustomerProfile;
use App\Domain\Customer\Actions\VerifyLoginOtp;
use App\Domain\Customer\DTOs\AddressData;
use App\Domain\Customer\Enums\AddressType;
use App\Domain\Customer\Jobs\SendLoginOtp;
use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\SetNotificationPreference;
use App\Domain\Notification\Enums\NotificationCategory;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoOrderPlaces;
use Database\Seeders\Demo\DemoOrderSandbox;
use Illuminate\Database\Seeder;
use Illuminate\Support\Testing\Fakes\BusFake;
use RuntimeException;

/**
 * Demo storefront accounts (local/testing only, from DemoDataSeeder): 30 invented
 * Bangladeshi customers, Dhaka-heavy but in every division, half with Bangla names and
 * Bangla addresses. Each one signs up the way a real customer does — RequestLoginOtp then
 * VerifyLoginOtp (the only path that creates an account; the code is read from the
 * recorded SMS job, which is never sent, see DemoOrderSandbox) — then fills in the profile
 * (UpdateCustomerProfile), the address book (SaveCustomerAddress: division → district →
 * upazila → area + line + landmark, rule 31; the first address is the default, a few
 * customers make their second one the default), a wishlist (AddToWishlist) and, for some,
 * notification preferences (SetNotificationPreference, from the storefront).
 *
 * Sign-ups are spread over the weeks before the order history starts, so every account
 * predates its first order. DemoOrdersSeeder places most of its orders for these phones
 * ({@see self::PHONES}); guests it adds itself become customers at checkout.
 *
 * Idempotent on phone: an existing customer is left exactly as it is.
 */
class DemoCustomersSeeder extends Seeder
{
    /**
     * The demo accounts' phones (local format, all 013–019 — rule 30), in CUSTOMERS order.
     * Other seeders find the demo customers through these.
     *
     * @var list<string>
     */
    public const array PHONES = [
        '01711458231', '01819233467', '01912784520', '01552346718', '01673905124',
        '01313672049', '01714906352', '01896021473', '01611384795', '01987350216',
        '01748261930', '01534079182', '01825913604', '01391728465', '01617049283',
        '01936582017', '01729304865', '01857146309', '01446273958', '01714629083',
        '01580391726', '01923018574', '01682457391', '01776350148', '01318402697',
        '01812675043', '01951243786', '01637820415', '01784092631', '01405918362',
    ];

    /**
     * phone → account. `addresses`: [type, label, district, upazila (null = city), area,
     * line, landmark]; the first is saved first (so it is the default) unless `default2`.
     * `wish`: product slugs. `prefs`: [channel, category, enabled] the customer set.
     *
     * @var array<string, array{name: string, email: string|null, lang: string, addresses: list<array{string, string|null, string, string|null, string, string, string}>, default2?: true, wish?: list<string>, prefs?: list<array{string, string, bool}>}>
     */
    public const array CUSTOMERS = [
        '01711458231' => ['name' => 'নুসরাত জাহান', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'ধানমন্ডি', 'বাড়ি ২৭, রোড ৯/এ, ৪র্থ তলা', 'রবীন্দ্র সরোবরের উল্টো দিকে'],
        ], 'wish' => ['lego-classic-creative-bricks-484', 'clementoni-galileo-my-first-microscope']],
        '01819233467' => ['name' => 'Tahmid Rahman', 'email' => 'tahmid.rahman@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Mirpur 10', 'House 14, Road 3, Block C', 'Behind Mirpur 10 roundabout, near Shah Ali Plaza'],
            ['office', 'Office', 'Dhaka', null, 'Gulshan 1', 'Level 6, Plot 42, Gulshan Avenue', 'Opposite Gulshan 1 DCC market'],
        ], 'wish' => ['bruder-man-tga-dump-truck', 'hot-wheels-10-car-gift-pack', 'rubiks-cube-3x3-original'], 'prefs' => [['sms', 'marketing', false]]],
        '01912784520' => ['name' => 'ফারজানা ইসলাম', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'উত্তরা সেক্টর ৭', 'বাড়ি ৫, রোড ১৮, ৩য় তলা', 'উত্তরা মডেল টাউন মসজিদের পাশে'],
        ], 'wish' => ['huanger-baby-activity-gym-play-mat']],
        '01552346718' => ['name' => 'Sadia Afrin', 'email' => 'sadia.afrin@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Bashundhara R/A', 'House 112, Road 7, Block D', 'Near Bashundhara Gate 8, beside Apollo pharmacy'],
        ], 'wish' => ['barbie-dreamhouse-pool-party-doll', 'little-tikes-first-slide']],
        '01673905124' => ['name' => 'মোহাম্মদ রফিকুল ইসলাম', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'মোহাম্মদপুর', 'বাড়ি ৯, তাজমহল রোড, ব্লক সি', 'মোহাম্মদপুর টাউন হল বাজারের পেছনে'],
        ]],
        '01313672049' => ['name' => 'Ayesha Siddiqua', 'email' => 'ayesha.siddiqua@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Banani', 'Flat 5B, House 31, Road 11', 'Next to Banani Kamal Ataturk Avenue Shell pump'],
        ], 'wish' => ['melissa-doug-wooden-doctor-kit', 'mideer-wooden-shape-sorting-puzzle', 'hape-pound-tap-bench-xylophone']],
        '01714906352' => ['name' => 'তানভীর হাসান', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'বাড্ডা', 'বাড়ি ৪৫, ময়নারবাগ, উত্তর বাড্ডা', 'বাড্ডা লিংক রোডের মোড়ে, আড়ং এর গলি'],
        ]],
        '01896021473' => ['name' => 'Nabila Chowdhury', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Dhanmondi 32', 'House 8, Road 32 (old), 2nd floor', 'Two buildings from the Bangabandhu museum lane'],
        ], 'wish' => ['play-doh-rainbow-starter-10-pack']],
        '01611384795' => ['name' => 'শারমিন আক্তার', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', 'Savar', 'সাভার বাজার', 'হোল্ডিং ১১২, থানা রোড', 'সাভার থানার সামনে, পূবালী ব্যাংকের উপরে'],
        ]],
        '01987350216' => ['name' => 'Imran Hossain', 'email' => 'imran.hossain@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Gazipur', 'Gazipur Sadar', 'Joydebpur', 'House 23, Rajbari Road', 'Near Joydebpur railway crossing'],
            ['office', 'Office', 'Dhaka', null, 'Tejgaon', 'Floor 4, 186 Tejgaon I/A', 'Opposite Tejgaon truck stand'],
        ], 'default2' => true],
        '01748261930' => ['name' => 'রুমানা পারভীন', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Narayanganj', 'Narayanganj Sadar', 'চাষাড়া', 'বাড়ি ১৭, বঙ্গবন্ধু সড়ক', 'চাষাড়া শহীদ মিনারের কাছে'],
        ], 'prefs' => [['whatsapp', 'order_updates', false]]],
        '01534079182' => ['name' => 'Fahim Ahmed', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Khilgaon', 'House 380, Khilgaon Taltola', 'Behind Taltola City Corporation market'],
        ], 'wish' => ['rubiks-cube-3x3-original']],
        '01825913604' => ['name' => 'সুমাইয়া খাতুন', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Chattogram', 'Hathazari', 'হাটহাজারী বাজার', 'হাজী বাড়ি, কলেজ রোড', 'হাটহাজারী কলেজ গেটের বিপরীতে'],
        ], 'wish' => ['intex-dinosaur-inflatable-kids-pool']],
        '01391728465' => ['name' => 'Rashed Karim', 'email' => 'rashed.karim@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Chattogram', 'Patiya', 'Patiya Sadar', 'Karim Manzil, Station Road', 'Beside Patiya bus terminal'],
        ]],
        '01617049283' => ['name' => 'মাহমুদা সুলতানা', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Comilla', 'Comilla Sadar', 'কান্দিরপাড়', 'বাড়ি ৩, রানীর বাজার রোড', 'কান্দিরপাড় পূবালী চত্বরের কাছে'],
        ]],
        '01936582017' => ['name' => 'Arif Mahmud', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Sylhet', 'Sylhet Sadar', 'Zindabazar', 'Flat 3A, Rose View Complex', 'Opposite Zindabazar Al-Hamra shopping centre'],
        ], 'wish' => ['winfun-baby-walker-push-car']],
        '01729304865' => ['name' => 'জান্নাতুল ফেরদৌস', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Rajshahi', 'Paba', 'নওদাপাড়া', 'বাড়ি ৬৪, নওদাপাড়া আবাসিক', 'নওদাপাড়া বাস টার্মিনালের পশ্চিমে'],
        ]],
        '01857146309' => ['name' => 'Shakil Ahmed', 'email' => 'shakil.ahmed@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Khulna', 'Rupsha', 'Rupsha Ghat', 'House 9, Ghat Road', 'Next to Rupsha bridge toll plaza'],
        ]],
        '01446273958' => ['name' => 'নাসরিন সুলতানা', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Barisal', 'Barisal Sadar', 'নতুন বাজার', 'বাড়ি ২১, সদর রোড', 'বরিশাল নতুন বাজার মসজিদের পাশে'],
        ], 'prefs' => [['sms', 'marketing', false]]],
        '01714629083' => ['name' => 'Mehedi Hasan', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Rangpur', 'Rangpur Sadar', 'Jahaj Company More', 'House 5, Station Road', 'Near Jahaj Company more, above Dutch-Bangla ATM'],
        ]],
        '01580391726' => ['name' => 'রিয়া মনি', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Mymensingh', 'Mymensingh Sadar', 'গাঙ্গিনারপাড়', 'বাড়ি ৪০, ছোট বাজার', 'গাঙ্গিনারপাড় মোড়ের দক্ষিণে'],
        ]],
        '01923018574' => ['name' => 'Tanjila Haque', 'email' => 'tanjila.haque@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Mohakhali DOHS', 'House 77, Road 23', 'Near DOHS Shoppers World'],
        ], 'wish' => ['fisher-price-smart-stages-puppy', 'huanger-baby-activity-gym-play-mat']],
        '01682457391' => ['name' => 'আবদুল্লাহ আল মামুন', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'যাত্রাবাড়ী', 'বাড়ি ১২০, শহীদ ফারুক সড়ক', 'যাত্রাবাড়ী ফ্লাইওভারের নিচে, ইসলামী ব্যাংকের পাশে'],
        ]],
        '01776350148' => ['name' => 'Priyanka Saha', 'email' => 'priyanka.saha@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Wari', 'House 18, Rankin Street', 'Near Wari police box'],
            ['other', "Parents' home", 'Narayanganj', 'Sonargaon', 'Mograpara', 'Saha Bari, Mograpara Chowrasta', 'Behind Mograpara high school'],
        ]],
        '01318402697' => ['name' => 'কামরুল হাসান', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', 'Keraniganj', 'জিনজিরা', 'বাড়ি ৭, প্যারাডাইস রোড', 'জিনজিরা প্যারাডাইস কমিউনিটি সেন্টারের পাশে'],
        ]],
        '01812675043' => ['name' => 'Lamia Akter', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Dhaka', null, 'Shyamoli', 'House 2/7, Ring Road', 'Opposite Shyamoli cinema hall'],
        ], 'wish' => ['crayola-washable-super-tips-20-markers', 'play-doh-rainbow-starter-10-pack']],
        '01951243786' => ['name' => 'সাকিব আল হাসান রনি', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Bogura', 'Bogra Sadar', 'সাতমাথা', 'বাড়ি ১৪, জলেশ্বরীতলা', 'সাতমাথা থেকে জলেশ্বরীতলা যাওয়ার পথে'],
        ]],
        '01637820415' => ['name' => 'Moumita Das', 'email' => 'moumita.das@example.com', 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Jashore', 'Jessore Sadar', 'Ghop', 'House 33, Ghop Central Road', 'Near Ghop Sebashangha club'],
        ]],
        '01784092631' => ['name' => 'হাবিবুর রহমান', 'email' => null, 'lang' => 'bn', 'addresses' => [
            ['home', 'বাসা', 'Dhaka', null, 'মালিবাগ', 'বাড়ি ৩৩৮, শান্তিবাগ', 'মালিবাগ মোড়ে আবুল হোটেলের গলি'],
            ['office', 'অফিস', 'Dhaka', null, 'মতিঝিল', '৭ম তলা, ৫৫ দিলকুশা বা/এ', 'শাপলা চত্বরের পূর্ব দিকে'],
        ]],
        '01405918362' => ['name' => 'Zarin Tasnim', 'email' => null, 'lang' => 'en', 'addresses' => [
            ['home', 'Home', 'Gazipur', 'Kaliakair', 'Safipur', 'House 4, Safipur Bazar Road', 'Opposite Safipur Ansar academy gate'],
        ], 'wish' => ['lego-classic-creative-bricks-484']],
    ];

    public function run(): void
    {
        DemoOrderPlaces::flush();
        $products = Product::query()->whereIn('slug', array_keys(DemoCatalogSeeder::PRODUCTS))->pluck('id', 'slug')->all();
        $start = CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays(max(14, (int) config('demo.history_days', 60)) + 45);

        DemoOrderSandbox::run(function (BusFake $bus) use ($products, $start): void {
            foreach (self::PHONES as $i => $local) {
                $phone = PhoneNumber::parse($local);
                if (Customer::query()->withTrashed()->where('phone', $phone->e164())->exists()) {
                    continue;
                }
                $row = self::CUSTOMERS[$local];
                // Spread over ~40 days before the order history, at a plausible evening hour.
                DemoOrderSandbox::at($start->addDays(intdiv($i * 4, 3))->setTime(19 + $i % 4, ($i * 7) % 60));

                $customer = $this->signUp($bus, $phone, $row['lang']);
                $customer = app(UpdateCustomerProfile::class)->handle($customer, array_filter([
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'preferred_language' => $row['lang'],
                ], static fn (?string $v): bool => $v !== null));

                foreach ($row['addresses'] as $n => [$type, $label, $district, $upazila, $area, $line, $landmark]) {
                    app(SaveCustomerAddress::class)->handle($customer, new AddressData(
                        type: AddressType::from($type),
                        label: $label,
                        recipientName: $row['name'],
                        phone: $phone,
                        district: DemoOrderPlaces::district($district),
                        upazila: DemoOrderPlaces::upazila($district, $upazila),
                        area: $area,
                        addressLine: $line,
                        landmark: $landmark,
                        makeDefault: $n === 1 && ($row['default2'] ?? false),
                    ));
                }

                foreach ($row['wish'] ?? [] as $slug) {
                    if (isset($products[$slug])) {
                        app(AddToWishlist::class)->handle($customer, (int) $products[$slug]);
                    }
                }

                foreach ($row['prefs'] ?? [] as [$channel, $category, $enabled]) {
                    app(SetNotificationPreference::class)->handle(
                        $customer->id,
                        NotificationChannel::from($channel),
                        NotificationCategory::from($category),
                        $enabled,
                        'storefront',
                    );
                }
            }
        });
    }

    /**
     * Storefront sign-in: request a code, then verify it. The code is read from the
     * recorded (never sent) SMS job — the only place it exists in plain text.
     */
    private function signUp(BusFake $bus, PhoneNumber $phone, string $locale): Customer
    {
        app(RequestLoginOtp::class)->handle($phone, null, $locale);

        $job = $bus->dispatched(SendLoginOtp::class, static fn (SendLoginOtp $j): bool => $j->e164Phone === $phone->e164())->last();
        if (! $job instanceof SendLoginOtp) {
            throw new RuntimeException('Demo seed: no sign-in code was issued for '.$phone->masked().'.');
        }

        return app(VerifyLoginOtp::class)->handle($phone, $job->code, $locale)->customer;
    }
}
