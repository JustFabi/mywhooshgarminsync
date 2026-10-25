<?php

namespace App\Services\Fit;

/**
 * FIT serializer. Untouched definition frames are emitted verbatim; data
 * messages are rebuilt from the parsed model (byte-identical by construction)
 * and new definition messages are inserted whenever the live content of a
 * local definition differs from the last emitted one (FIT spec).
 */
class FitEncoder
{
    public function encode(FitFile $file): string
    {
        $out = '';
        foreach ($file->segments as $segment) {
            $out .= $this->encodeSegment($segment);
        }

        return $out;
    }

    public function encodeSegment(FitSegment $segment): string
    {
        $lastEmittedKeys = array_fill(0, 16, null);
        $out = '';

        foreach ($segment->entries as $entry) {
            if ($entry instanceof FitDefinition) {
                $key = $entry->contentKey();
                if ($entry->dirty || $lastEmittedKeys[$entry->localMesgNum] !== $key) {
                    $out .= self::encodeDefinition($entry);
                } else {
                    $out .= $entry->rawToken;
                }
                $lastEmittedKeys[$entry->localMesgNum] = $key;

                continue;
            }

            // ----- data message -----
            $msg = $entry;
            $def = $msg->definition;
            $key = $def->contentKey();
            $local = $msg->localMesgNum;

            if ($lastEmittedKeys[$local] !== $key) {
                // Live definition content differs from what the stream would
                // otherwise apply: re-send the definition first.
                if (!$def->dirty && $def->rawToken !== null) {
                    $out .= $def->rawToken;
                } else {
                    $out .= self::encodeDefinition($def);
                }
                $lastEmittedKeys[$local] = $key;
            }

            $out .= self::encodeDataMessage($msg);
        }

        $messages = $out . $segment->trailer;
        $dataSize = strlen($messages);

        // ---------------- header ----------------
        $baseHeader = pack('C', $segment->headerSize)
            . pack('C', $segment->protocolVersion)
            . pack('v', $segment->profileVersion)
            . pack('V', $dataSize)
            . '.FIT';

        if ($segment->headerSize === 14) {
            $header = $baseHeader . pack('v', Crc16::compute($baseHeader));
            $crc = Crc16::compute($messages, 0);
        } else {
            $header = $baseHeader . pack('C', 0);
            // 12-byte headers fold the header into the data CRC (FIT spec).
            $crc = Crc16::compute($messages, Crc16::compute($baseHeader));
        }

        if (strlen($header) !== $segment->headerSize) {
            throw new FitException('internal error: header size mismatch');
        }

        return $header . $messages . pack('v', $crc);
    }

    public static function encodeDefinition(FitDefinition $def): string
    {
        $header = FitConstants::MESG_DEFINITION_MASK | $def->localMesgNum;
        if ($def->hasDevFields()) {
            $header |= FitConstants::DEV_DATA_MASK;
        }

        $be = $def->architecture === FitConstants::ARCH_BIG_ENDIAN;

        $out = pack('C', $header)
            . pack('C', 0)                     // reserved
            . pack('C', $def->architecture)
            . pack($be ? 'n' : 'v', $def->mesgNum)
            . pack('C', count($def->fields));

        foreach ($def->fields as $f) {
            $out .= pack('C', $f->num) . pack('C', $f->size) . pack('C', $f->baseType);
        }

        if ($def->hasDevFields()) {
            $out .= pack('C', count($def->devFields));
            foreach ($def->devFields as $f) {
                $out .= pack('C', $f->num) . pack('C', $f->size) . pack('C', $f->devIndex);
            }
        }

        return $out;
    }

    public static function encodeDataMessage(FitMessageData $msg): string
    {
        $header = $msg->compressed
            ? FitConstants::MESG_COMPRESSED_HEADER_MASK
                | ($msg->localMesgNum << FitConstants::COMPRESSED_BIT_SHIFT)
                | ($msg->timeOffset & FitConstants::COMPRESSED_TIME_MASK)
            : $msg->localMesgNum;

        $out = pack('C', $header);
        foreach ($msg->fieldBytes as $bytes) {
            $out .= $bytes;
        }
        foreach ($msg->devBytes as $bytes) {
            $out .= $bytes;
        }

        return $out;
    }
}
