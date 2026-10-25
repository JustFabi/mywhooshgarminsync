<?php

namespace App\Services\Fit;

/**
 * Fixes MyWhoosh FIT activity files so Garmin Connect processes them like a
 * native Garmin recording, ported from the original mywoosh2garmin (Go).
 *
 *  1. Session averages (avg power / HR / cadence) are calculated from record
 *     samples when missing, invalid or zero.
 *  2. Fake temperature readings are stripped (set invalid) from all records.
 *  3. The device identity is spoofed to a selected Garmin watch so Garmin
 *     accepts the training stats that come with it (Training Effect, VO2max,
 *     Training Load, Training Status).
 *
 * Every modification is logged so the UI can show exactly what happened.
 */
class FitFixer
{
    /**
     * @param  string        $bytes raw MyWhoosh FIT file contents
     * @param  array         $watch ['name' => string, 'product_id' => int]
     * @param  \Closure|null $log   optional listener (string $line)
     * @return array{0: string, 1: array<int, string>, 2: array<int, string>} [fixedBytes, logLines, warnings]
     */
    public static function fix(string $bytes, array $watch, ?\Closure $log = null): array
    {
        $logLines = [];

        $logger = function (string $line) use (&$logLines, $log) {
            $logLines[] = $line;
            if ($log) {
                $log($line);
            }
        };

        $fit = (new FitParser())->parse($bytes);

        foreach ($fit->warnings as $warning) {
            $logger('! ' . $warning);
        }

        // ------------------------------------------------------------
        // Collect metrics from record messages.
        // ------------------------------------------------------------
        $powers = [];
        $heartRates = [];
        $cadences = [];
        $records = $fit->dataMessagesByNum(FitConstants::MESG_NUM_RECORD);

        foreach ($records as $record) {
            $power = $record->intValue(FitConstants::FIELD_NUM_POWER);
            if ($power !== null && $power !== 0xFFFF) {
                $powers[] = $power;
            }

            $hr = $record->intValue(FitConstants::FIELD_NUM_HEART_RATE);
            if ($hr !== null && $hr !== 0xFF) {
                $heartRates[] = $hr;
            }

            $cadence = $record->intValue(FitConstants::FIELD_NUM_CADENCE);
            if ($cadence !== null && $cadence !== 0xFF) {
                $cadences[] = $cadence;
            }
        }

        $logger(sprintf(
            'Records: %d | Power: %d | HR: %d | Cadence: %d samples',
            count($records),
            count($powers),
            count($heartRates),
            count($cadences),
        ));

        // ------------------------------------------------------------
        // Strip bogus temperature from records.
        // ------------------------------------------------------------
        $stripped = 0;
        foreach ($records as $record) {
            $bytes = $record->fieldBytes(FitConstants::FIELD_NUM_TEMPERATURE);
            if ($bytes === null) {
                continue;
            }

            $invalid = FitValue::invalidByte(FitConstants::SINT8, $record->definition->architecture);
            $replacement = str_repeat($invalid, strlen($bytes));
            if ($replacement !== $bytes) {
                $record->setFieldBytes(FitConstants::FIELD_NUM_TEMPERATURE, $replacement);
                $stripped++;
            }
        }
        if ($stripped > 0) {
            $logger("→ temperature stripped from {$stripped} records");
        }

        // ------------------------------------------------------------
        // Fix missing / zero session averages from collected samples.
        // ------------------------------------------------------------
        foreach ($fit->dataMessagesByNum(FitConstants::MESG_NUM_SESSION) as $session) {
            if (count($powers) > 0 && self::shouldFix($session, FitConstants::FIELD_NUM_SESSION_AVG_POWER)) {
                $avg = self::avgOf($powers);
                if (self::setValue($fit, $session, FitConstants::FIELD_NUM_SESSION_AVG_POWER, $avg, FitConstants::UINT16)) {
                    $logger("→ avg power:      {$avg} W");
                }
            }

            if (count($heartRates) > 0 && self::shouldFix($session, FitConstants::FIELD_NUM_SESSION_AVG_HEART_RATE)) {
                $avg = self::avgOf($heartRates);
                if (self::setValue($fit, $session, FitConstants::FIELD_NUM_SESSION_AVG_HEART_RATE, $avg, FitConstants::UINT8)) {
                    $logger("→ avg heart rate: {$avg} bpm");
                }
            }

            if (count($cadences) > 0 && self::shouldFix($session, FitConstants::FIELD_NUM_SESSION_AVG_CADENCE)) {
                $avg = self::avgOf($cadences);
                if (self::setValue($fit, $session, FitConstants::FIELD_NUM_SESSION_AVG_CADENCE, $avg, FitConstants::UINT8)) {
                    $logger("→ avg cadence:    {$avg} rpm");
                }
            }
        }

        // ------------------------------------------------------------
        // Spoof device identity to the selected Garmin watch.
        // ------------------------------------------------------------
        $product = (int) ($watch['product_id'] ?? FitConstants::GARMIN_FAKE_SERIAL_NUMBER);
        $watchName = $watch['name'] ?? 'Garmin';
        $serial = FitConstants::GARMIN_FAKE_SERIAL_NUMBER;

        foreach ($fit->dataMessagesByNum(FitConstants::MESG_NUM_FILE_ID) as $fileId) {
            self::setValue($fit, $fileId, FitConstants::FIELD_NUM_MANUFACTURER, FitConstants::MANUFACTURER_GARMIN, FitConstants::UINT16);
            self::setValue($fit, $fileId, FitConstants::FIELD_NUM_PRODUCT, $product, FitConstants::UINT16);
            self::setValue($fit, $fileId, FitConstants::FIELD_NUM_SERIAL_NUMBER, $serial, FitConstants::UINT32Z);
        }

        foreach ($fit->dataMessagesByNum(FitConstants::MESG_NUM_DEVICE_INFO) as $deviceInfo) {
            self::setValue($fit, $deviceInfo, FitConstants::FIELD_NUM_DI_MANUFACTURER, FitConstants::MANUFACTURER_GARMIN, FitConstants::UINT16);
            self::setValue($fit, $deviceInfo, FitConstants::FIELD_NUM_DI_PRODUCT, $product, FitConstants::UINT16);
            self::setValue($fit, $deviceInfo, FitConstants::FIELD_NUM_DI_SERIAL_NUMBER, $serial, FitConstants::UINT32Z);
        }

        $logger(sprintf('→ device spoofed: %s (product %d)', $watchName, $product));

        // ------------------------------------------------------------
        // Serialize.
        // ------------------------------------------------------------
        $out = (new FitEncoder())->encode($fit);

        return [$out, $logLines, $fit->warnings];
    }

    /**
     * Original Go `shouldFixU16/U8`: fix when invalid sentinel, zero, or the
     * field is missing entirely.
     */
    private static function shouldFix(FitMessageData $msg, int $fieldNum): bool
    {
        $index = $msg->definition->fieldIndex($fieldNum);
        if ($index < 0) {
            return true;
        }

        $fieldDef = $msg->definition->fields[$index];
        $value = FitValue::readElement(
            $msg->fieldBytes[$index],
            $fieldDef->baseType,
            $msg->definition->architecture,
        );

        // z-base types treat zero as invalid too; plain types fix on 0 as well.
        return $value === null
            || $value === 0
            || $value === FitMessageData::invalidOf($fieldDef->baseType);
    }

    private static function avgOf(array $values): int
    {
        return intdiv(array_sum($values), count($values));
    }

    /**
     * Set a field value, adding the field to the message's definition when
     * missing. Definitions stay ascending per the FIT spec and every message
     * sharing the definition is rebuilt with matching structure.
     */
    private static function setValue(FitFile $fit, FitMessageData $msg, int $num, int $value, int $baseType): bool
    {
        $def = $msg->definition;

        if ($def->fieldIndex($num) < 0) {
            // Capture the original field order BEFORE mutating, so raw value
            // bytes can be re-aligned 1:1 by their original indices.
            $originalNums = array_map(fn (FitFieldDef $f) => $f->num, $def->fields);
            self::addFieldToDefinition($def, $num, $baseType);
            self::rebuildMessagesForDefinition($fit, $def, $originalNums);
        }

        $msg->setIntValue($num, $value);

        return true;
    }

    private static function addFieldToDefinition(FitDefinition $def, int $num, int $baseType): FitFieldDef
    {
        foreach ($def->fields as $existing) {
            if ($existing->num === $num) {
                return $existing;
            }
        }

        $fieldDef = new FitFieldDef($num, FitConstants::BASE_TYPE_SIZES[$baseType], $baseType);
        $def->fields[] = $fieldDef;
        usort($def->fields, fn (FitFieldDef $a, FitFieldDef $b) => $a->num <=> $b->num);
        $def->dirty = true;

        return $fieldDef;
    }

    /**
     * Rebuild the byte lists of all data messages bound to a (possibly
     * modified) definition so values line up with the new definition order;
     * new fields are filled with the invalid pattern.
     *
     * @param  int[]  $originalNums field numbers of the definition as they
     *                                were when the value bytes were captured
     */
    private static function rebuildMessagesForDefinition(FitFile $fit, FitDefinition $def, array $originalNums): void
    {
        $arch = $def->architecture;

        foreach ($fit->segments as $segment) {
            foreach ($segment->entries as $entry) {
                if (!$entry instanceof FitMessageData || $entry->definition !== $def) {
                    continue;
                }

                // Index existing raw bytes by field number using the ORIGINAL
                // declaration order the bytes were read in.
                $byNum = [];
                foreach ($entry->fieldBytes as $i => $bytes) {
                    if (!isset($originalNums[$i])) {
                        break;
                    }
                    $byNum[$originalNums[$i]] = $bytes;
                }

                $rebuilt = [];
                foreach ($def->fields as $fieldDef) {
                    $num = $fieldDef->num;
                    if (isset($byNum[$num])) {
                        $rebuilt[] = $byNum[$num];

                        continue;
                    }

                    $baseSize = max(FitConstants::BASE_TYPE_SIZES[$fieldDef->baseType] ?? 1, 1);
                    $invalidElement = FitValue::invalidByte($fieldDef->baseType, $arch);
                    $rebuilt[] = substr(
                        str_repeat($invalidElement, (int) ceil($fieldDef->size / $baseSize)),
                        0,
                        $fieldDef->size,
                    );
                }

                $entry->fieldBytes = $rebuilt;
                $entry->dirty = true;
            }
        }
    }
}
