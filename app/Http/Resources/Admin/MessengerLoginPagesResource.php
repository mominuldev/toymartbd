<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Pages shared in the Facebook window — ids and names only; their tokens stay on the server.
 *
 * @property array{session: string, pages: list<array{id: string, name: string, can_message: bool}>} $resource
 */
#[SchemaName('MessengerLoginPages')]
final class MessengerLoginPagesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string Pass to `…/messenger/connect` within 10 minutes. */
            'session' => $this->resource['session'],
            /** @var list<array{id: string, name: string, can_message: bool}> `can_message` false: this account cannot manage the Page's messages. */
            'pages' => $this->resource['pages'],
        ];
    }
}
