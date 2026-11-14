<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Admin → Integrations → Messaging, inbox channels: WhatsApp Cloud API and Messenger.
 *
 * @property array{whatsapp: array<string, mixed>, messenger: array<string, mixed>} $resource
 */
#[SchemaName('InboxChannelSettings')]
final class InboxChannelSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'whatsapp' => new InboxChannelCredentialsResource($this->resource['whatsapp']),
            'messenger' => new InboxChannelCredentialsResource($this->resource['messenger']),
        ];
    }
}
