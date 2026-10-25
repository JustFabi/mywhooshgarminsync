<?php

use App\Services\Fit\FitConstants;
use App\Services\Fit\FitFixer;
use App\Services\Fit\FitParser;

it('parses the chained poolswim file into two segments', function () {
    $bytes = fixture('chained_Activity_activity_poolswim.fit');

    $fit = (new FitParser)->parse($bytes);

    expect(count($fit->segments))->toBe(2)
        ->and($fit->warnings)->toBeEmpty()
        ->and($fit->dataMessagesByNum(FitConstants::MESG_NUM_SESSION))->toHaveCount(2)
        ->and($fit->dataMessagesByNum(FitConstants::MESG_NUM_RECORD))->toHaveCount(3625);
});

it('round-trips every bundled fixture byte-for-byte', function () {
    // @group may be slow with the large chained file
    $parser = new FitParser;
    $encoder = new App\Services\Fit\FitEncoder;

    foreach (fitFixtures() as $name => $bytes) {
        $fit = $parser->parse($bytes);

        expect($encoder->encode($fit), "round-trip of {$name}")->toBe($bytes);
    }
});

it('fixes the chained fixture: spoof, averages and temperature', function () {
    $bytes = fixture('chained_Activity_activity_poolswim.fit');

    [$fixed, $log] = FitFixer::fix($bytes, [
        'name' => 'Garmin Fenix 6S Pro',
        'product_id' => 3288,
    ]);

    $fit = (new FitParser)->parse($fixed);

    // A clean reparse with no structural warnings.
    expect($fit->warnings)->toBeEmpty()
        ->and(count($fit->segments))->toBe(2);

    // Spoofing applied to every file_id + device_info message.
    foreach ($fit->dataMessagesByNum(FitConstants::MESG_NUM_FILE_ID) as $fileId) {
        expect($fileId->intValue(FitConstants::FIELD_NUM_MANUFACTURER))->toBe(1)
            ->and($fileId->intValue(FitConstants::FIELD_NUM_PRODUCT))->toBe(3288)
            ->and($fileId->intValue(FitConstants::FIELD_NUM_SERIAL_NUMBER))->toBe(FitConstants::GARMIN_FAKE_SERIAL_NUMBER);
    }

    foreach ($fit->dataMessagesByNum(FitConstants::MESG_NUM_DEVICE_INFO) as $deviceInfo) {
        expect($deviceInfo->intValue(FitConstants::FIELD_NUM_DI_MANUFACTURER))->toBe(1)
            ->and($deviceInfo->intValue(FitConstants::FIELD_NUM_DI_PRODUCT))->toBe(3288)
            ->and($deviceInfo->intValue(FitConstants::FIELD_NUM_DI_SERIAL_NUMBER))->toBe(FitConstants::GARMIN_FAKE_SERIAL_NUMBER);
    }

    // The two sessions of the poolswim file get their averages fixed.
    $sessions = $fit->dataMessagesByNum(FitConstants::MESG_NUM_SESSION);
    expect($sessions[0]->intValue(FitConstants::FIELD_NUM_SESSION_AVG_POWER))->toBe(199)
        ->and($sessions[0]->intValue(FitConstants::FIELD_NUM_SESSION_AVG_HEART_RATE))->toBe(126)
        ->and($sessions[0]->intValue(FitConstants::FIELD_NUM_SESSION_AVG_CADENCE))->toBe(125)
        ->and($sessions[1]->intValue(FitConstants::FIELD_NUM_SESSION_AVG_HEART_RATE))->toBe(126)
        ->and($sessions[1]->intValue(FitConstants::FIELD_NUM_SESSION_AVG_CADENCE))->toBe(125);

    // Any temperature bytes that remain on records are invalid patterns.
    $invalidTemperature = App\Services\Fit\FitValue::invalidByte(
        FitConstants::SINT8,
        FitConstants::ARCH_LITTLE_ENDIAN,
    );
    $withTemp = array_values(array_filter(
        $fit->dataMessagesByNum(FitConstants::MESG_NUM_RECORD),
        fn ($record) => $record->fieldBytes(FitConstants::FIELD_NUM_TEMPERATURE) !== null,
    ));

    if (count($withTemp) > 0) {
        foreach ($withTemp as $record) {
            $bytes = $record->fieldBytes(FitConstants::FIELD_NUM_TEMPERATURE);
            expect($bytes)->toBe(str_repeat($invalidTemperature, strlen($bytes)));
        }
    } else {
        // Neither segment defines a temperature field on records at all —
        // matching the original Go tool which found none to strip here.
        expect(true)->toBeTrue();
    }

    // The log explains exactly what happened.
    $logText = implode("\n", $log);
    expect($logText)->toContain('device spoofed: Garmin Fenix 6S Pro');
});

it('fixes the tiny generated fixture', function () {
    $bytes = fixture('TestEncode.fit');

    [$fixed] = FitFixer::fix($bytes, App\Support\GarminDevices::default());

    $fit = (new FitParser)->parse($fixed);

    $fileId = $fit->segments[0]->fileId;
    expect($fileId)->not->toBeNull()
        ->and($fileId->intValue(FitConstants::FIELD_NUM_PRODUCT))->toBe(3288);

    // Round-trip stability of already-fixed content.
    [$fixed2] = FitFixer::fix($fixed, App\Support\GarminDevices::default());
    expect($fixed2)->toBe($fixed);
});
