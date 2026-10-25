<?php

namespace App\Services\Garmin;

class GarminUploadException extends GarminException
{
    public static function fromStatus(int $status, string $body): self
    {
        return new self(sprintf('upload failed (HTTP %d): %s', $status, mb_substr($body, 0, 300)));
    }
}
