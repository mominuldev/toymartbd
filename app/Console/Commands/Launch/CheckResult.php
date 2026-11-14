<?php

declare(strict_types=1);

namespace App\Console\Commands\Launch;

/**
 * One line of the launch check. `detail` says what was found and `fix` what to do — never a
 * secret value, a full phone number or an address (rule 20); secrets are reported by name only.
 * `ref` is the item id in docs/ops/LAUNCH-CHECKLIST.md.
 */
final readonly class CheckResult
{
    public function __construct(
        public string $id,
        public string $title,
        public CheckStatus $status,
        public string $detail,
        public string $ref,
        public ?string $fix = null,
    ) {}

    public static function pass(string $id, string $title, string $ref, string $detail): self
    {
        return new self($id, $title, CheckStatus::Pass, $detail, $ref);
    }

    public static function warn(string $id, string $title, string $ref, string $detail, string $fix): self
    {
        return new self($id, $title, CheckStatus::Warn, $detail, $ref, $fix);
    }

    public static function fail(string $id, string $title, string $ref, string $detail, string $fix): self
    {
        return new self($id, $title, CheckStatus::Fail, $detail, $ref, $fix);
    }

    public static function manual(string $id, string $title, string $ref, string $how): self
    {
        return new self($id, $title, CheckStatus::Manual, $how, $ref);
    }

    /** @return array{id: string, title: string, status: string, detail: string, fix: string|null, checklist: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'fix' => $this->fix,
            'checklist' => $this->ref,
        ];
    }
}
