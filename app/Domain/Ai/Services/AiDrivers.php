<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Contracts\AiDriver;
use App\Domain\Ai\Drivers\AnthropicDriver;
use App\Domain\Ai\Drivers\FakeDriver;
use App\Domain\Ai\Drivers\GeminiDriver;
use App\Domain\Ai\Drivers\OpenAiDriver;
use App\Domain\Ai\Enums\AiProvider;
use LogicException;

/** Provider => driver. Tests call {@see self::fake()} so no request ever leaves the machine. */
final class AiDrivers
{
    private ?FakeDriver $fake = null;

    /** Driver tests that fake HTTP themselves (OpenAI, Gemini) construct drivers directly instead. */
    public static bool $allowRealInTests = false;

    public function for(AiProvider $p): AiDriver
    {
        if ($this->fake !== null) {
            return $this->fake;
        }
        // A test that forgot AiDrivers::fake() must fail, never reach a provider.
        if (app()->runningUnitTests() && ! self::$allowRealInTests) {
            throw new LogicException('Call AiDrivers::fake() in tests: real AI providers are never called from the suite.');
        }

        return match ($p) {
            AiProvider::Anthropic => app(AnthropicDriver::class),
            AiProvider::OpenAi => app(OpenAiDriver::class),
            AiProvider::Gemini => app(GeminiDriver::class),
        };
    }

    public static function fake(): FakeDriver
    {
        $drivers = new self;
        $drivers->fake = new FakeDriver;
        app()->instance(self::class, $drivers);

        return $drivers->fake;
    }
}
