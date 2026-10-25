<?php

namespace App\Services\Garmin;

use GuzzleHttp\Cookie\CookieJar;

/**
 * Thin alias so the SSO service can rely on a cookie jar without leaking the
 * Guzzle import name everywhere.
 */
class_alias(CookieJar::class, 'App\Services\Garmin\CookieJar');
