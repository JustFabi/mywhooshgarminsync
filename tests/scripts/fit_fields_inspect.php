<?php

/**
 * Inspect which field numbers each message type actually contains in the
 * bundled fixtures — used to answer "what data is physically in a MyWhoosh FIT".
 */

use App\Services\Fit\FitParser;
use App\Services\Fit\FitValue;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = $argv[1] ?? __DIR__.'/../fixtures/fit';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $dir,
    FilesystemIterator::SKIP_DOTS,
));

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'fit') {
        continue;
    }

    $bytes = file_get_contents($file->getPathname());
    $fileObj = (new FitParser)->parse($bytes);

    /** @var array<int, array<int, true>> $fields messageNum => fieldNums */
    $fields = [];
    /** @var array<string, true> $msgTypes */
    $msgTypes = [];
    $recordSamples = [];

    foreach ($fileObj->dataMessages() as $message) {
        $num = $message->mesgNum;
        $msgTypes[$num] = true;
        foreach ($message->definition->fields as $fd) {
            $fields[$num][$fd->num] = true;
        }
        if ($num === 20 && count($recordSamples) < 2) {
            $vals = [];
            foreach ($message->fieldBytes as $i => $bytes) {
                $fd = $message->definition->fields[$i] ?? null;
                if ($fd === null) {
                    continue;
                }
                $v = FitValue::readElement($bytes, $fd->baseType, $message->definition->architecture, 0);
                $vals[$fd->num] = $v;
            }
            ksort($vals);
            $recordSamples[] = $vals;
        }
    }

    ksort($msgTypes);
    echo '=== '.$file->getFilename()." ===\n";
    echo '  messages: '.implode(', ', array_map(fn ($n) => sprintf('%d(0x%02x)', $n, $n), array_keys($msgTypes)))."\n";
    foreach ($fields as $num => $map) {
        $nums = array_keys($map);
        sort($nums);
        $labels = implode(',', $nums);
        echo "  msg {$num} fields: {$labels}\n";
    }
    if ($recordSamples !== []) {
        $s = json_encode($recordSamples[0], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        echo '  sample record: '.substr($s, 0, 400)."\n";
    }
    echo "\n";
}

