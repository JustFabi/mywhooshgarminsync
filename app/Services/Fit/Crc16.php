<?php

namespace App\Services\Fit;

/**
 * CRC-16 used by the FIT protocol (polynomial 0x1021, init 0), implemented
 * with the nibble-based algorithm from the official FIT SDK reference.
 */
final class Crc16
{
    private const TABLE = [
        0x0000, 0xCC01, 0xD801, 0x1400,
        0xF001, 0x3C00, 0x2800, 0xE401,
        0xA001, 0x6C00, 0x7800, 0xB401,
        0x5000, 0x9C01, 0x8801, 0x4400,
    ];

    private int $crc = 0;

    public static function compute(string $bytes, int $init = 0): int
    {
        $crc = new self();
        $crc->crc = $init & 0xFFFF;
        $crc->write($bytes);

        return $crc->sum();
    }

    public function write(string $bytes): void
    {
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);

            // Lower four bits.
            $tmp = self::TABLE[$this->crc & 0xF];
            $this->crc = (($this->crc >> 4) & 0x0FFF);
            $this->crc ^= $tmp ^ self::TABLE[$b & 0xF];

            // Upper four bits.
            $tmp = self::TABLE[$this->crc & 0xF];
            $this->crc = (($this->crc >> 4) & 0x0FFF);
            $this->crc ^= $tmp ^ self::TABLE[($b >> 4) & 0xF];
        }
    }

    public function sum(): int
    {
        return $this->crc & 0xFFFF;
    }
}
