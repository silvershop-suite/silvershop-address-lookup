<?php

declare(strict_types=1);

namespace SilverShop\AddressLookup\ValueObject;

/**
 * The outcome of validating an address against a provider: whether it is deliverable, a 0..1 confidence score, and an
 * optional canonical/corrected form. The third provider capability the market research flagged (autocomplete is not
 * validation). Immutable.
 */
final class AddressValidationResult
{
    public function __construct(
        public readonly bool $valid,
        public readonly float $score = 0.0,
        public readonly ?ResolvedAddress $canonical = null,
    ) {
    }

    /**
     * A "we don't know" result — neither confirmed valid nor rejected (the default for providers without a validate
     * capability). Treated as a soft pass by callers so the form stays usable.
     */
    public static function unknown(): self
    {
        return new self(true, 0.0, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'score' => $this->score,
            'canonical' => $this->canonical?->toArray(),
        ];
    }
}
