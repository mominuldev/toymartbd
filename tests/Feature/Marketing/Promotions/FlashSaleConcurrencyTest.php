<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Marketing\Promotions\PromoKit;

/*
 * The flash-sale race (P7): 8 shoppers check out at the same moment, each priced with
 * the sale price, against an allotment of 3 (and plenty of stock). Exactly 3 orders get
 * the flash price; every other checkout is refused cleanly (the claim under
 * `SELECT … FOR UPDATE` on flash_sale_items finds the allotment gone → PromotionRejected),
 * and `claimed` never exceeds the allotment. Same harness as CheckoutConcurrencyTest:
 * its own throwaway MySQL database, committed fixtures, forked children behind a barrier.
 */

const FLASH_RACE_CONNECTION = 'flash_race';

function flashRaceDatabase(): string
{
    return config('database.connections.mysql.database').'_flash_race';
}

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    $this->original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.flashRaceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.flashRaceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    config(['database.connections.'.FLASH_RACE_CONNECTION => ['database' => flashRaceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection(FLASH_RACE_CONNECTION);
    Artisan::call('migrate', ['--database' => FLASH_RACE_CONNECTION, '--force' => true]);
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    DB::purge(FLASH_RACE_CONNECTION);
    DB::setDefaultConnection($this->original);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.flashRaceDatabase().'`');
});

it('never sells more than the flash allotment to parallel checkouts', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');

    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $variant = CartTestKit::variant('1000.00', 50);
    PromoKit::flash([$variant->id => ['500.00', 3]]);

    $inputs = [];
    for ($i = 0; $i < 8; $i++) {
        $cart = CheckoutKit::cart([$variant->id => 1]);
        $inputs[] = CheckoutKit::data($cart, sprintf('0171%07d', $i)); // every one priced at the flash price
    }
    expect($inputs[7]->expectedTotal->toDecimal())->toBe('630.00');

    $dir = sys_get_temp_dir().'/flash-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';
    DB::disconnect(FLASH_RACE_CONNECTION);

    $pids = [];
    foreach ($inputs as $i => $input) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $deadline = microtime(true) + 10;
            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                app(PlaceOrder::class)->handle($input);
                $result = 'placed';
            } catch (PromotionRejected|PriceChanged) {
                $result = 'refused';
            } catch (InsufficientStock) {
                $result = 'insufficient';
            } catch (Throwable $e) {
                $result = 'error: '.$e::class.' '.$e->getMessage();
            }
            file_put_contents($dir.'/'.$i, $result);
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[] = $pid;
    }

    touch($go);
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($inputs) as $i) {
        $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
        @unlink($dir.'/'.$i);
    }
    @unlink($go);
    @rmdir($dir);

    $tally = array_count_values($results);
    ksort($tally);
    expect($tally)->toBe(['placed' => 3, 'refused' => 5]);

    $item = FlashSaleItem::query()->sole();
    expect($item->claimed)->toBe(3)
        ->and(Order::query()->count())->toBe(3)
        ->and(Order::query()->sum('promotion_discount'))->toEqual(1500)
        ->and(StockLevel::query()->where('variant_id', $variant->id)->sole()->reserved)->toBe(3);
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel checkouts need MySQL row locking (make test-mysql).');
