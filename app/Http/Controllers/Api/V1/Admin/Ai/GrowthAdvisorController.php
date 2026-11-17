<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Domain\Ai\Advisor\Actions\AskAdvisor;
use App\Domain\Ai\Advisor\Actions\DecideBriefAction;
use App\Domain\Ai\Advisor\Actions\WriteWeeklyBrief;
use App\Domain\Ai\Advisor\Enums\ActionStatus;
use App\Domain\Ai\Advisor\Exceptions\ActionAlreadyDecided;
use App\Domain\Ai\Advisor\Exceptions\FiguresNotShared;
use App\Domain\Ai\Advisor\Models\AiBrief;
use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Domain\Ai\Advisor\Services\AdvisorOverview;
use App\Domain\Ai\Exceptions\AiException;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Ai\AskAdvisorRequest;
use App\Http\Resources\Admin\Ai\AdvisorActionResource;
use App\Http\Resources\Admin\Ai\AdvisorAnswerResource;
use App\Http\Resources\Admin\Ai\GrowthAdvisorResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Analytics › Growth advisor (Figma 537:82561): weekly brief, suggested actions, Ask AI.
 * reports.view throughout. Suggest only — nothing here changes an order, a price or stock.
 * The answer language follows the request locale (`?locale=` / Accept-Language).
 */
final class GrowthAdvisorController extends ApiController
{
    /** One brief rewrite per ten minutes, store-wide. */
    private const int BRIEF_DECAY = 600;

    /** Ask AI: twenty questions an hour per staff member. */
    private const int ASK_PER_HOUR = 20;

    /** `GET /analytics/advisor` — the latest brief, its open suggestions, done-this-month. */
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function show(AdvisorOverview $overview): GrowthAdvisorResource
    {
        $this->authorize('view', AiBrief::class);

        return new GrowthAdvisorResource($overview->build());
    }

    /** `POST /analytics/advisor/brief` — write (or rewrite) this week's brief now. */
    #[OpenApiResponse(409, 'AI not set up (`ai-not-configured`), over the monthly cap (`ai-cap-reached`), or report figures may not be shared (`ai-not-shared`)')]
    #[OpenApiResponse(429, 'Rewritten less than ten minutes ago (`too-many-requests`, Retry-After)')]
    #[OpenApiResponse(503, 'The provider gave no usable answer (`ai-unavailable`)')]
    public function refresh(Request $request, WriteWeeklyBrief $write, AdvisorOverview $overview): GrowthAdvisorResource
    {
        $this->authorize('view', AiBrief::class);
        $key = 'ai-advisor:brief';
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $wait = RateLimiter::availableIn($key);
            abort(429, "The brief was written moments ago. Try again in {$wait} seconds.", ['Retry-After' => (string) $wait]);
        }
        RateLimiter::hit($key, self::BRIEF_DECAY);
        try {
            $write->handle(self::uid($request), App::getLocale());
        } catch (FiguresNotShared $e) {
            RateLimiter::clear($key);
            throw new AiException(AiException::NOT_SHARED, $e->getMessage());
        } catch (AiException $e) {
            RateLimiter::clear($key);
            throw $e;
        }

        return new GrowthAdvisorResource($overview->build());
    }

    /** `POST /analytics/advisor/ask` — answer one question from this week's report figures. */
    #[OpenApiResponse(409, 'AI not set up (`ai-not-configured`), over the monthly cap (`ai-cap-reached`), or report figures may not be shared (`ai-not-shared`)')]
    #[OpenApiResponse(429, 'More than twenty questions in an hour (`too-many-requests`, Retry-After)')]
    #[OpenApiResponse(503, 'The provider gave no usable answer (`ai-unavailable`)')]
    public function ask(AskAdvisorRequest $request, AskAdvisor $ask): AdvisorAnswerResource
    {
        $key = 'ai-advisor:ask:'.(self::uid($request) ?? 0);
        if (RateLimiter::tooManyAttempts($key, self::ASK_PER_HOUR)) {
            $wait = RateLimiter::availableIn($key);
            abort(429, "That is a lot of questions for one hour. Try again in {$wait} seconds.", ['Retry-After' => (string) $wait]);
        }
        RateLimiter::hit($key, 3600);
        try {
            return new AdvisorAnswerResource($ask->handle($request->question(), App::getLocale(), self::uid($request)));
        } catch (FiguresNotShared $e) {
            throw new AiException(AiException::NOT_SHARED, $e->getMessage());
        }
    }

    /** `POST /analytics/advisor/actions/{action}/done` */
    #[OpenApiResponse(409, 'Already marked done or not useful (`conflict`)')]
    public function done(Request $request, AiBriefAction $action, DecideBriefAction $decide): AdvisorActionResource
    {
        return $this->decide($request, $action, $decide, ActionStatus::Done);
    }

    /** `POST /analytics/advisor/actions/{action}/dismiss` — "Not useful". */
    #[OpenApiResponse(409, 'Already marked done or not useful (`conflict`)')]
    public function dismiss(Request $request, AiBriefAction $action, DecideBriefAction $decide): AdvisorActionResource
    {
        return $this->decide($request, $action, $decide, ActionStatus::Dismissed);
    }

    private function decide(Request $request, AiBriefAction $action, DecideBriefAction $decide, ActionStatus $status): AdvisorActionResource
    {
        $this->authorize('decide', $action);
        try {
            return new AdvisorActionResource($decide->handle($action, $status, self::uid($request)));
        } catch (ActionAlreadyDecided $e) {
            abort(409, $e->getMessage());
        }
    }

    private static function uid(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
