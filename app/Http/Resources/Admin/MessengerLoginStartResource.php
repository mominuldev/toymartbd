<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Where to send the owner for "Continue with Facebook".
 *
 * @property array{url: string, redirect_uri: string} $resource
 */
#[SchemaName('MessengerLoginStart')]
final class MessengerLoginStartResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string Facebook's login dialog; open it in the same tab. Valid for 10 minutes. */
            'url' => $this->resource['url'],
            /** @var string Where Facebook sends the owner back — must be listed in the Meta app's Valid OAuth Redirect URIs. */
            'redirect_uri' => $this->resource['redirect_uri'],
        ];
    }
}
