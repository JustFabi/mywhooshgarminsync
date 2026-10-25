<?php

namespace App\Services\MyWhoosh;

class MyWhooshAuthException extends MyWhooshException
{
    public ?int $status = null;

    public static function unauthorized(?int $status = null, string $body = ''): self
    {
        $e = new self($status
            ? "MyWhoosh rejected the session (HTTP {$status}). Reconnect on Connections."
            : 'MyWhoosh rejected the session. Reconnect on Connections.');
        $e->status = $status ?? 401;

        return $e;
    }
}
