<?php

namespace App\Services\Fit;

/**
 * FIT protocol constants (FIT Protocol v2.x) ported from the FIT SDK
 * (ref: github.com/muktihari/fit proto/base type tables).
 */
final class FitConstants
{
    // ---------------------------------------------------------------------
    // Message header masks
    //
    // Normal definition message:      0x40 | localMesgNum (0-15)
    // Developer-data definition:      0x60 | localMesgNum (0-15)
    // Normal data message:            0x00 | localMesgNum (0-15)
    // Compressed timestamp message:   0x80 | localMesgNum(0-3)<<5 | timeOffset(0-31)
    // ---------------------------------------------------------------------
    public const MESG_DEFINITION_MASK = 0x40;
    public const MESG_NORMAL_HEADER_MASK = 0x00;
    public const MESG_COMPRESSED_HEADER_MASK = 0x80;
    public const LOCAL_MESG_NUM_MASK = 0x0F;
    public const COMPRESSED_LOCAL_MESG_NUM_MASK = 0x60;
    public const COMPRESSED_TIME_MASK = 0x1F;
    public const DEV_DATA_MASK = 0x20;
    public const COMPRESSED_BIT_SHIFT = 5;

    /** Timestamp field number shared by (nearly) all messages. */
    public const FIELD_NUM_TIMESTAMP = 253;

    // ---------------------------------------------------------------------
    // Global message numbers used by this application (FIT activity files).
    // ---------------------------------------------------------------------
    public const MESG_NUM_FILE_ID = 0;
    public const MESG_NUM_SESSION = 18;
    public const MESG_NUM_LAP = 19;
    public const MESG_NUM_RECORD = 20;
    public const MESG_NUM_EVENT = 21;
    public const MESG_NUM_DEVICE_INFO = 23;

    // ---------------------------------------------------------------------
    // Base types: ordinal (as stored in definition messages) => [label, size].
    // Ordinals include the endian-ability flag (0x80) for multi-byte types.
    // ---------------------------------------------------------------------
    public const ENUM = 0x00;
    public const SINT8 = 0x01;
    public const UINT8 = 0x02;
    public const STRING = 0x07;
    public const UINT8Z = 0x0A;
    public const BYTE = 0x0D;
    public const SINT16 = 0x83;
    public const UINT16 = 0x84;
    public const SINT32 = 0x85;
    public const UINT32 = 0x86;
    public const FLOAT32 = 0x88;
    public const FLOAT64 = 0x89;
    public const UINT16Z = 0x8B;
    public const UINT32Z = 0x8C;
    public const SINT64 = 0x8E;
    public const UINT64 = 0x8F;
    public const UINT64Z = 0x90;

    public const BASE_TYPE_SIZES = [
        self::ENUM => 1,
        self::SINT8 => 1,
        self::UINT8 => 1,
        self::STRING => 1,
        self::UINT8Z => 1,
        self::BYTE => 1,
        self::SINT16 => 2,
        self::UINT16 => 2,
        self::SINT32 => 4,
        self::UINT32 => 4,
        self::FLOAT32 => 4,
        self::FLOAT64 => 8,
        self::UINT16Z => 2,
        self::UINT32Z => 4,
        self::SINT64 => 8,
        self::UINT64 => 8,
        self::UINT64Z => 8,
    ];

    /** Invalid (sentinel) value expressed as little-endian bytes padded to the requested repetition. */
    public const BASE_TYPE_INVALID_BYTES = [
        self::ENUM => 'FF',
        self::SINT8 => '7F',
        self::UINT8 => 'FF',
        self::STRING => '00',
        self::UINT8Z => '00',
        self::BYTE => 'FF',
        self::SINT16 => 'FF7F',
        self::UINT16 => 'FFFF',
        self::SINT32 => 'FFFFFF7F',
        self::UINT32 => 'FFFFFFFF',
        self::FLOAT32 => 'FFFFFFFF',
        self::UINT16Z => '0000',
        self::UINT32Z => '00000000',
        self::SINT64 => 'FFFFFFFFFFFFFF7F',
        self::UINT64 => 'FFFFFFFFFFFFFFFF',
        self::UINT64Z => '0000000000000000',
        self::FLOAT64 => 'FFFFFFFFFFFFFFFF',
    ];

    /** Arch byte values in definitions. */
    public const ARCH_LITTLE_ENDIAN = 0;
    public const ARCH_BIG_ENDIAN = 1;

    /** Sentinel used for non-FIT messages (developer data). */
    public const MESG_NUM_DEV_DATA = 0xFFFE;

    public const ARCH_UINT32_EPOCH_1989_12_31 = 631065600;

    // ----- file_id (mesg 0) -----
    public const FIELD_NUM_FILE_TYPE = 0;
    public const FIELD_NUM_MANUFACTURER = 1;
    public const FIELD_NUM_PRODUCT = 2;
    public const FIELD_NUM_SERIAL_NUMBER = 3;
    public const FIELD_NUM_TIME_CREATED = 4;
    public const FIELD_NUM_NUMBER = 5;
    public const FIELD_NUM_PRODUCT_NAME = 8;

    // ----- record (mesg 20) -----
    public const FIELD_NUM_HEART_RATE = 3;
    public const FIELD_NUM_CADENCE = 4;
    public const FIELD_NUM_SPEED = 6;
    public const FIELD_NUM_POWER = 7;
    public const FIELD_NUM_TEMPERATURE = 13;

    // ----- session (mesg 18) -----
    public const FIELD_NUM_SESSION_START_TIME = 2;
    public const FIELD_NUM_SESSION_TOTAL_TIMER_TIME = 8;
    public const FIELD_NUM_SESSION_AVG_SPEED = 14;
    public const FIELD_NUM_SESSION_AVG_HEART_RATE = 16;
    public const FIELD_NUM_SESSION_MAX_HEART_RATE = 17;
    public const FIELD_NUM_SESSION_AVG_CADENCE = 18;
    public const FIELD_NUM_SESSION_MAX_CADENCE = 19;
    public const FIELD_NUM_SESSION_AVG_POWER = 20;
    public const FIELD_NUM_SESSION_MAX_POWER = 21;
    public const FIELD_NUM_SESSION_SPORT = 5;
    public const FIELD_NUM_SESSION_TOTAL_DISTANCE = 9;
    public const FIELD_NUM_SESSION_TOTAL_CALORIES = 11;
    public const FIELD_NUM_SESSION_TOTAL_CYCLES = 10;
    public const FIELD_NUM_SESSION_TOTAL_ELAPSED_TIME = 7;

    // ----- device_info (mesg 23) -----
    public const FIELD_NUM_DEVICE_INDEX = 0;
    public const FIELD_NUM_DI_DEVICE_TYPE = 1;
    public const FIELD_NUM_DI_MANUFACTURER = 2;
    public const FIELD_NUM_DI_SERIAL_NUMBER = 3;
    public const FIELD_NUM_DI_PRODUCT = 4;
    public const FIELD_NUM_DI_SOFTWARE_VERSION = 5;
    public const FIELD_NUM_DI_HARDWARE_VERSION = 6;
    public const FIELD_NUM_DI_PRODUCT_NAME = 27;
    public const FIELD_NUM_DEVICE_TIMESTAMP = 253;

    // ----- manufacturer -----
    public const MANUFACTURER_GARMIN = 1;

    public const GARMIN_FAKE_SERIAL_NUMBER = 3420897194;
}
