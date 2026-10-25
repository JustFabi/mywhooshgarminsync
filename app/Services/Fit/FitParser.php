<?php

namespace App\Services\Fit;

/**
 * FIT binary decoder producing a FitFile document model (one segment per
 * chained FIT file). Handles protocol v1/v2, little/big endian architecture,
 * compressed timestamp messages and developer fields.
 */
class FitParser
{
    public function parse(string $bytes): FitFile
    {
        $file = new FitFile();

        $len = strlen($bytes);
        if ($len < 14) {
            throw new FitException('file too small to be a FIT file (' . $len . ' bytes)');
        }

        if (substr($bytes, 8, 4) !== '.FIT') {
            throw new FitException('invalid FIT data type (expected .FIT magic)');
        }

        $offset = 0;
        while ($offset < $len) {
            // Skip zero padding between chained segments.
            if (substr($bytes, $offset + 8, 4) !== '.FIT') {
                if (strspn(substr($bytes, $offset), "\x00") === $len - $offset) {
                    $file->addWarning('ignored ' . ($len - $offset) . ' trailing zero bytes');
                    break;
                }
                throw new FitException('chained data at offset ' . $offset . ' is not a FIT header');
            }

            $segment = $this->parseSegment($bytes, $offset, $file);
            $file->segments[] = $segment;

            // Next chained segment starts after this one's CRC.
            $next = $offset + $segment->consumed;
            if ($next >= $len) {
                break;
            }

            $offset = $next;
        }

        return $file;
    }

    private function parseSegment(string $bytes, int $offset, FitFile $file): FitSegment
    {
        $len = strlen($bytes);

        $headerSize = ord($bytes[$offset]);
        if ($headerSize !== 12 && $headerSize !== 14) {
            throw new FitException("unsupported FIT header size {$headerSize} at offset {$offset} (expected 12 or 14)");
        }

        if (substr($bytes, $offset + 8, 4) !== '.FIT') {
            throw new FitException('invalid FIT data type (expected .FIT magic) at offset ' . $offset);
        }

        $segment = new FitSegment(
            headerSize: $headerSize,
            protocolVersion: ord($bytes[$offset + 1]),
            profileVersion: unpack('v', substr($bytes, $offset + 2, 2))[1],
        );
        $declaredDataSize = unpack('V', substr($bytes, $offset + 4, 4))[1];

        $messagesStart = $offset + $headerSize;
        $crcStart = $messagesStart + $declaredDataSize;   // expected start of CRC bytes
        $fileCrcEnd = $len - 2;

        // ---- data size sanity ----
        $dataEnd = $crcStart;
        if ($crcStart > $fileCrcEnd) {
            // Header declares more data than the file holds: clamp to what
            // exists and repair the size when encoding.
            $dataEnd = $fileCrcEnd;
            $segment->addWarning('header declares more data than the segment holds; sizes repaired on encode');
        } elseif ($crcStart < $fileCrcEnd) {
            // The remaining file region is larger than the declared data
            // size. Before assuming a broken header, check whether the
            // declared size stands on its own: either its CRC validates, or
            // another chained segment starts right after this one's CRC.
            $wellFormed = false;
            if ($crcStart + 2 <= $len) {
                $init = $headerSize === 14 ? 0 : Crc16::compute(substr($bytes, $offset, $headerSize));
                $computed = Crc16::compute(substr($bytes, $messagesStart, $crcStart - $messagesStart), $init);
                $stored = unpack('v', substr($bytes, $crcStart, 2))[1];
                if ($stored === 0 || $stored === $computed) {
                    $wellFormed = true;
                }
            }
            if (!$wellFormed && substr($bytes, $crcStart + 2 + 8, 4) === '.FIT') {
                $wellFormed = true;
            }

            if ($wellFormed) {
                // Declared size is credible: keep the exact segment boundary.
            } elseif ($fileCrcEnd - $crcStart <= 4) {
                // Small gap before CRC: absorb as trailer.
                $segment->trailer = substr($bytes, $crcStart, $fileCrcEnd - $crcStart);
                $segment->addWarning('absorbed ' . strlen($segment->trailer) . ' byte trailer before CRC');
                $dataEnd = $crcStart;
            } else {
                // Large mismatch: don't trust the declared size; parse the
                // full remaining region and re-derive on encode.
                $dataEnd = $fileCrcEnd;
                $segment->addWarning('data size mismatch: header declares ' . $declaredDataSize . ' bytes but segment region has ' . ($fileCrcEnd - $messagesStart));
            }
        }

        // ---- header CRC (14-byte headers) ----
        if ($headerSize === 14) {
            $computedHeaderCrc = Crc16::compute(substr($bytes, $offset, 12));
            $storedHeaderCrc = unpack('v', substr($bytes, $offset + 12, 2))[1];
            if ($storedHeaderCrc !== 0 && $storedHeaderCrc !== $computedHeaderCrc) {
                $segment->addWarning('file header CRC mismatch');
            }
        }

        // ---- message section CRC (lenient) ----
        if ($dataEnd > $messagesStart) {
            $dataSection = substr($bytes, $messagesStart, $dataEnd - $messagesStart);
            $init = $headerSize === 14 ? 0 : Crc16::compute(substr($bytes, $offset, $headerSize));
            $computedCrc = Crc16::compute($dataSection, $init);
            if ($dataEnd + 2 <= $len) {
                $storedCrc = unpack('v', substr($bytes, $dataEnd, 2))[1];
                if ($storedCrc !== $computedCrc) {
                    $segment->addWarning('data CRC mismatch');
                }
            }
        }

        $this->parseMessages($segment, $bytes, $messagesStart, $dataEnd);

        // total bytes consumed by this segment: header + messages + CRC
        $segment->consumed = ($dataEnd - $offset) + 2;

        return $segment;
    }

    private function parseMessages(FitSegment $segment, string $bytes, int $start, int $end): void
    {
        $localDefs = array_fill(0, 16, null);

        $lastTimestamp = 0;      // uint32, FIT epoch (1989-12-31)
        $lastTimeOffset = 0;     // last compressed time offset

        $pos = $start;
        while ($pos < $end) {
            $frameStart = $pos;
            $header = ord($bytes[$pos]);
            $masked = $header & 0xC0;

            // ---- definition message ----
            if ($masked === FitConstants::MESG_DEFINITION_MASK) {
                if ($end - $pos < 6) {
                    throw new FitException('truncated message definition at offset ' . $pos);
                }

                $localMesgNum = $header & FitConstants::LOCAL_MESG_NUM_MASK;
                $pos += 1;
                $reserved = ord($bytes[$pos]); // reserved
                $pos += 1;
                $architecture = ord($bytes[$pos]);
                $pos += 1;
                if ($architecture !== FitConstants::ARCH_LITTLE_ENDIAN && $architecture !== FitConstants::ARCH_BIG_ENDIAN) {
                    throw new FitException("unknown architecture byte {$architecture} in definition at offset {$frameStart}");
                }

                $mesgNum = unpack($architecture === FitConstants::ARCH_LITTLE_ENDIAN ? 'v' : 'n', substr($bytes, $pos, 2))[1];
                $pos += 2;

                $numFields = ord($bytes[$pos]);
                $pos += 1;

                $def = new FitDefinition($localMesgNum, $mesgNum, $architecture);
                for ($i = 0; $i < $numFields; $i++) {
                    if ($end - $pos < 3) {
                        throw new FitException('truncated field definition at offset ' . $pos);
                    }
                    $fieldNum = ord($bytes[$pos]);
                    $fieldSize = ord($bytes[$pos + 1]);
                    $baseType = ord($bytes[$pos + 2]);
                    $pos += 3;

                    if (!isset(FitConstants::BASE_TYPE_SIZES[$baseType])) {
                        throw new FitException("unknown base type 0x" . dechex($baseType) . " in definition at offset {$frameStart}");
                    }

                    $def->fields[] = new FitFieldDef($fieldNum, $fieldSize, $baseType);
                }

                if (($header & FitConstants::DEV_DATA_MASK) !== 0) {
                    if ($end - $pos < 1) {
                        throw new FitException('truncated developer field count at offset ' . $pos);
                    }
                    $numDevFields = ord($bytes[$pos]);
                    $pos += 1;
                    for ($i = 0; $i < $numDevFields; $i++) {
                        if ($end - $pos < 3) {
                            throw new FitException('truncated developer field definition at offset ' . $pos);
                        }
                        $devNum = ord($bytes[$pos]);
                        $devSize = ord($bytes[$pos + 1]);
                        $devIndex = ord($bytes[$pos + 2]);
                        $pos += 3;
                        $def->devFields[] = new FitDevFieldDef($devNum, $devSize, $devIndex);
                    }
                }

                $def->rawToken = substr($bytes, $frameStart, $pos - $frameStart);
                $localDefs[$localMesgNum] = $def;
                $segment->entries[] = $def;

                continue;
            }

            // ---- data message ----
            $compressed = $masked === FitConstants::MESG_COMPRESSED_HEADER_MASK;
            if ($compressed) {
                $localMesgNum = ($header & FitConstants::COMPRESSED_LOCAL_MESG_NUM_MASK) >> FitConstants::COMPRESSED_BIT_SHIFT;
                $timeOffset = $header & FitConstants::COMPRESSED_TIME_MASK;
            } else {
                $localMesgNum = $header & FitConstants::LOCAL_MESG_NUM_MASK;
                $timeOffset = null;
            }

            $def = $localDefs[$localMesgNum];
            if ($def === null) {
                throw new FitException("data message at offset {$pos} references undefined local message number {$localMesgNum}");
            }

            $needed = 0;
            foreach ($def->fields as $f) {
                $needed += $f->size;
            }
            foreach ($def->devFields as $f) {
                $needed += $f->size;
            }
            if ($end - $pos - 1 < $needed) {
                throw new FitException('truncated data message at offset ' . $pos);
            }

            $pos += 1;

            $msg = new FitMessageData($localMesgNum, $def, $compressed, $timeOffset ?? 0);
            $msg->mesgNum = $def->mesgNum;

            foreach ($def->fields as $f) {
                $msg->fieldBytes[] = substr($bytes, $pos, $f->size);
                $pos += $f->size;
            }
            foreach ($def->devFields as $f) {
                $msg->devBytes[] = substr($bytes, $pos, $f->size);
                $pos += $f->size;
            }

            /**
             * Timestamp tracking, replicating the reference decoder:
             *  - compressed header advances the active timestamp by the
             *    masked delta of the 5-bit time offset;
             *  - any explicit field 253 (uint32) resets it.
             */
            if ($compressed) {
                $lastTimestamp = ($lastTimestamp + (($timeOffset - $lastTimeOffset) & FitConstants::COMPRESSED_TIME_MASK)) & 0xFFFFFFFF;
                $lastTimeOffset = $timeOffset;
                $msg->timestamp = $lastTimestamp;
            } else {
                $tsIndex = $def->fieldIndex(FitConstants::FIELD_NUM_TIMESTAMP);
                if ($tsIndex >= 0 && $def->fields[$tsIndex]->size === 4) {
                    $ts = FitValue::readElement($msg->fieldBytes[$tsIndex], $def->fields[$tsIndex]->baseType, $def->architecture);
                    if ($ts !== null) {
                        $lastTimestamp = $ts;
                        $lastTimeOffset = $ts & FitConstants::COMPRESSED_TIME_MASK;
                    }
                }
                $msg->timestamp = $lastTimestamp;
            }

            $segment->entries[] = $msg;

            if ($msg->mesgNum === FitConstants::MESG_NUM_FILE_ID && $segment->fileId === null) {
                $segment->fileId = $msg;
            }
        }
    }
}
