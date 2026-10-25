<?php

namespace App\Services\Fit;

/**
 * Definition message of a local message number. Each definition frame in the
 * file is its own object; data messages hold a reference to the instance they
 * were declared with.
 */
class FitDefinition
{
    /** @var array<int, FitFieldDef> keyed in declaration order */
    public array $fields = [];

    /** @var array<int, FitDevFieldDef> keyed in declaration order */
    public array $devFields = [];

    /** Set when the structure changed (field added) and needs re-encode. */
    public bool $dirty = false;

    /** Verbatim bytes of the original definition frame. */
    public ?string $rawToken = null;

    public function __construct(
        public int $localMesgNum,
        public int $mesgNum,
        public int $architecture = FitConstants::ARCH_LITTLE_ENDIAN,
    ) {}

    /** Content key used by the encoder to detect required re-emissions. */
    public function contentKey(): string
    {
        $parts = [$this->architecture, $this->mesgNum];
        foreach ($this->fields as $f) {
            $parts[] = $f->num . ':' . $f->size . ':' . $f->baseType;
        }
        foreach ($this->devFields as $f) {
            $parts[] = 'dev-' . $f->num . ':' . $f->size . ':' . $f->devIndex;
        }

        return hash('crc32b', implode('|', $parts));
    }

    /** Locates a field definition by global field number. */
    public function field(int $num): ?FitFieldDef
    {
        foreach ($this->fields as $f) {
            if ($f->num === $num) {
                return $f;
            }
        }

        return null;
    }

    /** Index of a field definition within the declaration order, or -1. */
    public function fieldIndex(int $num): int
    {
        foreach ($this->fields as $i => $f) {
            if ($f->num === $num) {
                return $i;
            }
        }

        return -1;
    }

    public function hasDevFields(): bool
    {
        return count($this->devFields) > 0;
    }
}
