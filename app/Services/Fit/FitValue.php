<?php

namespace App\Services\Fit;

/**
 * Binary helpers for FIT base types. All numeric encodings follow the FIT
 * protocol: little-endian (arch 0) or big-endian (arch 1) as declared by the
 * containing definition message. Values are represented as PHP ints; signed
 * base types are interpreted with two's complement.
 */
final class FitValue
{
    /**
     * Read the $i-th element of a field value.
     *
     * @return int|null numeric value (signed interpretation for sint*) or null
     *                  when there are not enough bytes.
     */
    public static function readElement(string $bytes, int $baseType, int $arch, int $index = 0): ?int
    {
        $size = FitConstants::BASE_TYPE_SIZES[$baseType] ?? null;
        if ($size === null) {
            return null;
        }

        $offset = $index * $size;
        if ($offset + $size > strlen($bytes)) {
            return null;
        }

        $slice = substr($bytes, $offset, $size);
        // FIT stores multi-byte types in the byte order defined by the
        // definition's architecture byte; normalize to little-endian for PHP.
        if ($arch === FitConstants::ARCH_BIG_ENDIAN) {
            $slice = strrev($slice);
        }

        $int = 0;
        $len = strlen($slice);
        for ($i = 0; $i < $len; $i++) {
            $int += ord($slice[$i]) << (8 * $i);
        }

        switch ($baseType) {
            case FitConstants::SINT8:
            case FitConstants::SINT16:
            case FitConstants::SINT32:
            case FitConstants::SINT64:
                return self::toSigned($int, $size);
            default:
                return $int;
        }
    }

    /** Number of elements packed into a field's value bytes. */
    public static function elementCount(string $bytes, int $baseType): int
    {
        $size = FitConstants::BASE_TYPE_SIZES[$baseType] ?? 1;
        if ($size === 0) {
            return 0;
        }

        return intdiv(strlen($bytes), $size);
    }

    /**
     * Write the $i-th element of a field's value bytes in place.
     * If the field bytes are shorter than needed they are extended.
     */
    public static function writeElement(string &$bytes, int $baseType, int $arch, int $index, int $value): void
    {
        $size = FitConstants::BASE_TYPE_SIZES[$baseType] ?? 1;

        $contentLen = intdiv(strlen($bytes), $size) * $size;
        $needed = ($index + 1) * $size;
        if ($needed > $contentLen) {
            // Extend with the base type's invalid pattern up to the required length.
            $bytes = str_pad($bytes, $needed, self::invalidByte($baseType, $arch), STR_PAD_RIGHT);
        }

        if ($size === 1) {
            $bytes[$index] = self::encode($value, $baseType, $arch);
        } else {
            $bytes = substr_replace(
                $bytes,
                self::encode($value, $baseType, $arch),
                $index * $size,
                $size
            );
        }
    }

    /** Build a fresh field value holding a single element with padding for arrays. */
    public static function makeSingle(int $baseType, int $arch, int $value, int $repeat = 1): string
    {
        $one = self::encode($value, $baseType, $arch);
        return str_repeat($one, $repeat);
    }

    /** Invalid pattern for one element of a base type, respecting architecture. */
    public static function invalidByte(int $baseType, int $arch): string
    {
        $hex = FitConstants::BASE_TYPE_INVALID_BYTES[$baseType] ?? 'FFFFFFFFFFFFFFFF';
        $raw = hex2bin($hex);

        if ($arch === FitConstants::ARCH_BIG_ENDIAN) {
            $raw = strrev($raw);
        }

        return $raw;
    }

    /** Encode an integer field per base type. Only integer base types supported. */
    public static function encode(int $value, int $baseType, int $arch): string
    {
        switch ($baseType) {
            case FitConstants::ENUM:
            case FitConstants::SINT8:
            case FitConstants::UINT8:
            case FitConstants::STRING:
            case FitConstants::UINT8Z:
            case FitConstants::BYTE:
                return chr($value & 0xFF);

            case FitConstants::SINT16:
            case FitConstants::UINT16:
            case FitConstants::UINT16Z:
                $packed = pack($arch === FitConstants::ARCH_BIG_ENDIAN ? 'n' : 'v', $value & 0xFFFF);
                return $packed;

            case FitConstants::SINT32:
            case FitConstants::UINT32:
            case FitConstants::UINT32Z:
            case FitConstants::FLOAT32:
                return pack($arch === FitConstants::ARCH_BIG_ENDIAN ? 'N' : 'V', $value & 0xFFFFFFFF);

            case FitConstants::SINT64:
            case FitConstants::UINT64:
            case FitConstants::UINT64Z:
            case FitConstants::FLOAT64:
                return pack($arch === FitConstants::ARCH_BIG_ENDIAN ? 'J' : 'P', $value & 0xFFFFFFFFFFFFFFFF);

            default:
                throw new \RuntimeException("Unsupported base type for encoding: 0x" . dechex($baseType));
        }
    }

    private static function toSigned(int $value, int $size): int
    {
        $bits = $size * 8;
        if ($value >= (1 << ($bits - 1))) {
            $value -= (1 << $bits);
        }

        return $value;
    }
}
