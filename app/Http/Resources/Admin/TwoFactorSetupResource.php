<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * A two-factor setup in progress, returned once by `POST /auth/two-factor`: the setup key
 * for typing into the app, the otpauth URI, and the same URI as an SVG QR code.
 *
 * @property array{secret: string, uri: string, svg: string} $resource
 */
final class TwoFactorSetupResource extends ApiResource
{
    /** @return array{secret: string, otpauth_uri: string, qr_svg: string} */
    public function toArray(Request $request): array
    {
        return [
            'secret' => $this->resource['secret'],
            'otpauth_uri' => $this->resource['uri'],
            'qr_svg' => $this->resource['svg'],
        ];
    }
}
