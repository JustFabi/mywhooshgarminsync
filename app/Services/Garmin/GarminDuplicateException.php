<?php

namespace App\Services\Garmin;

/**
 * The activity already exists on Garmin Connect (HTTP 409).
 */
class GarminDuplicateException extends GarminUploadException
{
}
