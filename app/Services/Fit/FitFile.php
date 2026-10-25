<?php

namespace App\Services\Fit;

/**
 * In-memory representation of a parsed FIT file (possibly a chained FIT file
 * containing multiple back-to-back file segments).
 */
class FitFile
{
    /** @var FitSegment[] */
    public array $segments = [];

    /** @var string[] warnings collected while parsing any segment */
    public array $warnings = [];

    /** @return FitMessageData[] all data messages across all segments */
    public function dataMessages(): array
    {
        $out = [];
        foreach ($this->segments as $segment) {
            foreach ($segment->dataMessages() as $msg) {
                $out[] = $msg;
            }
        }

        return $out;
    }

    /** @return FitMessageData[] data messages with the given global number */
    public function dataMessagesByNum(int $mesgNum): array
    {
        $out = [];
        foreach ($this->dataMessages() as $msg) {
            if ($msg->mesgNum === $mesgNum) {
                $out[] = $msg;
            }
        }

        return $out;
    }

    public function firstMessageByNum(int $mesgNum): ?FitMessageData
    {
        foreach ($this->dataMessagesByNum($mesgNum) as $msg) {
            return $msg;
        }

        return null;
    }

    public function messageCount(): int
    {
        $count = 0;
        foreach ($this->segments as $segment) {
            $count += count($segment->entries);
        }

        return $count;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }
}
