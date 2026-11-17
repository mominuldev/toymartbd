<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Ai;

use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiProvider;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** `PUT /settings/ai` — which provider and model each feature uses, the monthly cap, what may be shared; needs settings.update. */
final class UpdateAiSettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $features = array_column(AiFeature::cases(), 'value');
        $rules = [
            /** Every feature, keyed by feature: `advisor`, `product_copy`, `inbox_reply`, `restock_note`. */
            'features' => ['required', 'array:'.implode(',', $features), 'required_array_keys:'.implode(',', $features)],
            /** Monthly spending cap in US dollars, e.g. `50.00`. `0` turns AI off. */
            'cap_usd' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/'],
            /** What features may send to the provider. Phones, addresses and payment details are never sent. */
            'share' => ['required', 'array:figures,products,first_names'],
            'share.figures' => ['required', 'boolean:strict'],
            'share.products' => ['required', 'boolean:strict'],
            'share.first_names' => ['required', 'boolean:strict'],
        ];
        foreach ($features as $f) {
            $rules["features.$f"] = ['required', 'array:provider,model'];
            /** `anthropic`, `openai` or `gemini`. */
            $rules["features.$f.provider"] = ['required', 'string', Rule::enum(AiProvider::class)];
            /** One of the provider's models (see GET /settings/ai). */
            $rules["features.$f.model"] = ['required', 'string', 'max:60'];
        }

        return $rules;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            foreach ((array) $this->input('features', []) as $f => $row) {
                $provider = AiProvider::tryFrom((string) (is_array($row) ? ($row['provider'] ?? '') : ''));
                if ($provider !== null && ! $provider->hasModel((string) ($row['model'] ?? ''))) {
                    $v->errors()->add("features.$f.model", 'This model is not offered for '.$provider->label().'.');
                }
            }
        }];
    }

    /** @return array<string, array{provider: string, model: string}> */
    public function features(): array
    {
        /** @var array<string, array{provider: string, model: string}> $f */
        $f = $this->validated('features');

        return $f;
    }

    /** @return array{figures: bool, products: bool, first_names: bool} */
    public function share(): array
    {
        /** @var array{figures: bool, products: bool, first_names: bool} $s */
        $s = $this->validated('share');

        return $s;
    }
}
