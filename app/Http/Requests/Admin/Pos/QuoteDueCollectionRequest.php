<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Pos;

/** `POST /pos/sessions/{session}/due-collections/quote` — read the tenders against the customer's open due. Writes nothing. */
final class QuoteDueCollectionRequest extends PosDueCollectionRequest {}
