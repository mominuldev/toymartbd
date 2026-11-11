<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// Fast checks that run with every `php artisan test`. deptrac (below) is the full
// layer matrix; these catch the most damaging violations without it.
arch('the domain never depends on HTTP')
    ->expect('App\Domain')
    ->not->toUse('App\Http');

arch('support classes stay free of the domain and HTTP')
    ->expect('App\Support')
    ->not->toUse(['App\Domain', 'App\Http']);

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

it('makes deptrac fail on a deliberate Domain → Http import', function (): void {
    $probe = app_path('Domain/Ordering/DeptracProbe.php');
    file_put_contents($probe, <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Domain\Ordering;

        use App\Http\Controllers\Controller;

        final class DeptracProbe extends Controller {}
        PHP);

    try {
        $result = Process::path(base_path())
            ->run([PHP_BINARY, 'vendor/bin/deptrac', 'analyse', '--no-progress', '--no-cache']);
    } finally {
        unlink($probe);
    }

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output())->toContain('DeptracProbe');
})->group('arch');

// Report days are Dhaka days (rule 11). Finance code asks DhakaDate, never the clock directly.
arch('finance never reads the raw clock')
    ->expect('App\Domain\Finance')
    ->not->toUse(['now', 'today', 'date']);
