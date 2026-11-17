<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Ai;

use App\Domain\Ai\Restock\RestockForecast;
use App\Domain\Ai\Restock\RestockNote;
use App\Domain\Inventory\Models\StockLevel;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Ai\RestockNoteRequest;
use App\Http\Resources\Admin\Ai\RestockForecastResource;
use App\Http\Resources\Admin\Ai\RestockNoteResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Inventory › Restock forecast (Figma 540:83487). The forecast is arithmetic on the server; AI
 * only writes the short note. Suggest only: nothing is ordered until a person sends a PO, which
 * the screen raises as a draft through the existing `POST /purchases`.
 */
final class RestockForecastController extends ApiController
{
    /** `GET /inventory/restock` — needs inventory.view. Any query parameter but `horizon` is a 400. */
    #[QueryParameter('horizon', 'Days ahead the forecast covers.', type: "'7'|'14'|'30'", default: '14')]
    #[OpenApiResponse(400, 'An unlisted parameter or a horizon other than 7, 14 or 30 (`bad-request`)')]
    public function index(Request $request, RestockForecast $forecast, RestockNote $note): RestockForecastResource
    {
        $this->authorize('viewAny', StockLevel::class);
        $result = $forecast->compute(self::horizon($request));
        $language = app()->getLocale() === 'bn' ? 'bn' : 'en';

        return new RestockForecastResource(['forecast' => $result, 'note' => $note->cached($result, $language), 'note_blocked' => $note->blocked($result)]);
    }

    /**
     * `POST /inventory/restock/note` — the AI note for the current forecast (cached 6 hours per
     * Dhaka day, horizon and language). Needs inventory.view. 409 `ai-not-configured` /
     * `ai-cap-reached`, 503 `ai-unavailable`.
     */
    public function note(RestockNoteRequest $request, RestockForecast $forecast, RestockNote $note): RestockNoteResource
    {
        $result = $forecast->compute($request->horizon());
        $language = app()->getLocale() === 'bn' ? 'bn' : 'en';
        $id = $request->user()?->getAuthIdentifier();

        return new RestockNoteResource($note->write($result, $language, is_int($id) ? $id : null, $request->fresh()));
    }

    private static function horizon(Request $request): int
    {
        $unknown = array_diff(array_map(strval(...), array_keys($request->query->all())), ['horizon']);
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown parameter(s): '.implode(', ', $unknown).'. Allowed: horizon.');
        }
        $raw = $request->query('horizon');
        if ($raw === null || $raw === '') {
            return RestockForecast::DEFAULT_HORIZON;
        }
        if (! is_string($raw) || ! in_array($raw, array_map(strval(...), RestockForecast::HORIZONS), true)) {
            throw new BadRequestHttpException('horizon must be one of: '.implode(', ', RestockForecast::HORIZONS).'.');
        }

        return (int) $raw;
    }
}
