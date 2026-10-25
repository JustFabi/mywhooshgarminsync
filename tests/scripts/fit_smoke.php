<?php

/** Standalone smoke test: round-trip real FIT files through the PHP codec. */

require __DIR__ . '/../../vendor/autoload.php';

use App\Services\Fit\FitEncoder;
use App\Services\Fit\FitFixer;
use App\Services\Fit\FitParser;

$fixtures = [
    'C:/Users/fabia/AppData/Local/Temp/opencode/fitdata/chained_Activity_activity_poolswim.fit',
    'C:/Users/fabia/AppData/Local/Temp/opencode/fitdata/big_activity.fit',
    'C:/Users/fabia/AppData/Local/Temp/opencode/fitdata/TestEncode.fit',
    'C:/Users/fabia/AppData/Local/Temp/opencode/fitdata/valid_only_contain_fileid.fit',
];

foreach ($fixtures as $fixture) {
    $basename = basename($fixture);
    echo "== $basename\n";
    $bytes = file_get_contents($fixture);

    try {
        $parser = new FitParser();
        $fit = $parser->parse($bytes);

        $stats = ['defs' => 0, 'data' => 0, 'records' => 0, 'sessions' => 0, 'deviceInfo' => 0];
        foreach ($fit->segments as $segment) {
            foreach ($segment->entries as $entry) {
                if ($entry instanceof \App\Services\Fit\FitDefinition) {
                    $stats['defs']++;
                } else {
                    $stats['data']++;
                    if ($entry->mesgNum === 20) {
                        $stats['records']++;
                    }
                    if ($entry->mesgNum === 18) {
                        $stats['sessions']++;
                    }
                    if ($entry->mesgNum === 23) {
                        $stats['deviceInfo']++;
                    }
                }
            }
        }
        echo '  segments=' . count($fit->segments) . ' | ' . implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($stats), $stats)) . "\n";
        echo '  parse warnings: ' . count($fit->warnings) . "\n";

        // Round trip
        $encoded = (new FitEncoder())->encode($fit);
        echo '  round-trip: ' . strlen($bytes) . ' -> ' . strlen($encoded) . ' bytes, identical=' . var_export($encoded === $bytes, true) . "\n";

        // Re-parse encoded version and compare entry-by-entry
        $fit2 = $parser->parse($encoded);
        if (count($fit2->warnings) === 0 && count($fit->segments) === count($fit2->segments)) {
            $ok = true;
            foreach ($fit->segments as $i => $segment) {
                $seg2 = $fit2->segments[$i];
                if (count($segment->entries) !== count($seg2->entries)) {
                    $ok = false;
                    break;
                }
                foreach ($segment->entries as $j => $entry) {
                    $e2 = $seg2->entries[$j];
                    if (get_class($entry) !== get_class($e2)) {
                        $ok = false;
                        break;
                    }
                    if ($entry instanceof \App\Services\Fit\FitDefinition) {
                        if ($entry->contentKey() !== $e2->contentKey()) {
                            $ok = false;
                            break;
                        }
                    } else {
                        if ($entry->localMesgNum !== $e2->localMesgNum || $entry->definition->mesgNum !== $e2->definition->mesgNum) {
                            $ok = false;
                            break;
                        }
                        if (implode('', $entry->fieldBytes) !== implode('', $e2->fieldBytes)) {
                            $ok = false;
                            break;
                        }
                    }
                }
            }
            echo "  structural match: " . var_export($ok, true) . "\n";
        } else {
            echo '  WARN: segments ' . count($fit->segments) . ' vs ' . count($fit2->segments) . ', warnings2=' . count($fit2->warnings) . "\n";
        }

        // Fix and verify nothing explodes
        [$fixed, $log, $warnings] = FitFixer::fix($bytes, ['name' => 'Fenix 6S Pro', 'product_id' => 3288]);
        $fit3 = $parser->parse($fixed);
        echo '  fixer: ' . strlen($fixed) . ' bytes, reparse OK, warnings=' . count($fit3->warnings) . ', segments=' . count($fit3->segments) . ', log=' . count($log) . "\n";

        $fixedId = $fit3->firstMessageByNum(0);
        if ($fixedId) {
            echo '  fixed fileId: manufacturer=' . $fixedId->intValue(1) . ' product=' . $fixedId->intValue(2) . ' serial=' . $fixedId->intValue(3) . "\n";
        }
        $di = null;
        foreach ($fit3->dataMessagesByNum(23) as $first) {
            $di = $first;
            break;
        }
        if ($di) {
            echo '  fixed deviceInfo: manufacturer=' . $di->intValue(2) . ' product=' . $di->intValue(4) . ' serial=' . $di->intValue(3) . "\n";
        }
        foreach ($fit3->dataMessagesByNum(18) as $sess) {
            echo '  session avg power=' . var_export($sess->intValue(20), true) . ' hr=' . var_export($sess->intValue(16), true) . ' cad=' . var_export($sess->intValue(18), true) . "\n";
            break;
        }
    } catch (Throwable $e) {
        echo '  ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    }
    echo "\n";
}
