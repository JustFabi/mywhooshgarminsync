<?php

namespace App\Services\Fit;

/**
 * A decoded data message. Values are kept as raw byte strings aligned 1:1
 * with the definition's field list; interpretation happens through FitValue
 * on demand so fixes can be surgical and cheap in memory.
 */
class FitMessageData
{
    /**
     * Raw value bytes, aligned by index with $definition->fields.
     *
     * @var string[]
     */
    public array $fieldBytes = [];

    /**
     * Raw value bytes for developer fields, aligned with $definition->devFields.
     *
     * @var string[]
     */
    public array $devBytes = [];

    /** Effective timestamp (from explicit field 253 or compressed header). */
    public ?int $timestamp = null;

    /** Set when this message's values changed and must be re-encoded. */
    public bool $dirty = false;

    public function __construct(
        public int $localMesgNum,
        public FitDefinition $definition,
        public bool $compressed = false,
        public int $timeOffset = 0,
    ) {
        $this->mesgNum = $definition->mesgNum;
    }

    /** Global message number (resolved from the definition at parse time). */
    public int $mesgNum;

    public function hasField(int $num): bool
    {
        return $this->definition->fieldIndex($num) >= 0;
    }

    /** Number of an existing field's definition; throws when absent. */
    public function fieldIndex(int $num): int
    {
        $index = $this->definition->fieldIndex($num);
        if ($index < 0) {
            throw new FitException("field {$num} does not exist in this message (mesg {$this->mesgNum})");
        }

        return $index;
    }

    /** Raw bytes of a field, or null when the field is absent. */
    public function fieldBytes(int $num): ?string
    {
        $index = $this->definition->fieldIndex($num);
        if ($index < 0) {
            return null;
        }

        return $this->fieldBytes[$index] ?? '';
    }

    /**
     * First element value of a field, or null when absent.
     *
     * @return int|null
     */
    public function intValue(int $num, int $index = 0): ?int
    {
        $bytes = $this->fieldBytes($num);
        if ($bytes === null) {
            return null;
        }

        $def = $this->definition->field($num);

        return FitValue::readElement($bytes, $def->baseType, $this->definition->architecture, $index);
    }

    /**
     * Set the first element of a field. Array fields keep their element count;
     * following elements are written with the base type's invalid pattern.
     */
    public function setIntValue(int $num, int $value): void
    {
        $index = $this->fieldIndex($num);
        $def = $this->definition->field($num);
        $bytes = $this->fieldBytes[$index] ?? '';
        $arch = $this->definition->architecture;

        FitValue::writeElement($bytes, $def->baseType, $arch, 0, $value);

        $count = FitValue::elementCount($bytes, $def->baseType);
        for ($i = 1; $i < $count; $i++) {
            FitValue::writeElement($bytes, $def->baseType, $arch, $i, self::invalidOf($def->baseType));
        }

        $this->fieldBytes[$index] = $bytes;
        $this->dirty = true;
    }

    /** Overwrite a field's raw bytes wholesale. */
    public function setFieldBytes(int $num, string $bytes): void
    {
        $index = $this->fieldIndex($num);
        $this->fieldBytes[$index] = $bytes;
        $this->dirty = true;
    }

    /** Numeric sentinel for a base type. */
    public static function invalidOf(int $baseType): int
    {
        $hex = FitConstants::BASE_TYPE_INVALID_BYTES[$baseType] ?? 'FF';

        return (int) hexdec($hex);
    }
}
