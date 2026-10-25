<?php

namespace App\Services\Fit;

/**
 * One FIT segment: a header + ordered stream of definition/data frames + CRC.
 * Chained FIT files contain several of these back-to-back.
 */
class FitSegment
{
    public function __construct(
        public int $headerSize = 14,
        public int $protocolVersion = 0x10,
        public int $profileVersion = 0x16A7,
    ) {
        $this->dataSize = 0;
    }

    /** @var int raw declared data size of the segment header bytes */
    public int $dataSize;

    /** @var array<int, FitDefinition|FitMessageData> ordered stream frames */
    public array $entries = [];

    /** @var string[] segment-specific warnings */
    public array $warnings = [];

    /** Zero/odd padding absorbed between the last message and the CRC. */
    public string $trailer = '';

    /** @var int total bytes consumed from the original stream (header+data+CRC) */
    public int $consumed = 0;

    public ?FitMessageData $fileId = null;

    /** @return FitMessageData[] */
    public function dataMessages(): array
    {
        $out = [];
        foreach ($this->entries as $entry) {
            if ($entry instanceof FitMessageData) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
