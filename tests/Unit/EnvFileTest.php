<?php

use App\Support\EnvFile;

it('parses KEY=VALUE lines with comments, export prefixes and quotes', function () {
    $path = sys_get_temp_dir().'/envfile-'.uniqid().'.env';
    file_put_contents($path, <<<'TXT'
        # full-line comment

        MYWHOOSH_EMAIL=rider@example.com
        export MYWHOOSH_PASSWORD="secret with spaces"
        GARMIN_EMAIL='team@example.com'
        EMPTY=
          INDENTED_KEY   =   trimmed value
        =noside
        nopleftsign
        9BAD=better-not

        TXT);

    try {
        $items = EnvFile::read($path);

        expect($items)->toBe([
            'MYWHOOSH_EMAIL' => 'rider@example.com',
            'MYWHOOSH_PASSWORD' => 'secret with spaces',
            'GARMIN_EMAIL' => 'team@example.com',
            'EMPTY' => '',
            'INDENTED_KEY' => 'trimmed value',
        ]);
    } finally {
        @unlink($path);
    }
});

it('applies values so env(), getenv() and the superglobals see them', function () {
    EnvFile::apply(['MYWHOOSHSYNC_CRON_TEST' => 'cron-secret']);

    try {
        expect(env('MYWHOOSHSYNC_CRON_TEST'))->toBe('cron-secret');
        expect(getenv('MYWHOOSHSYNC_CRON_TEST'))->toBe('cron-secret');
        expect($_ENV['MYWHOOSHSYNC_CRON_TEST'])->toBe('cron-secret');
        expect($_SERVER['MYWHOOSHSYNC_CRON_TEST'])->toBe('cron-secret');
    } finally {
        putenv('MYWHOOSHSYNC_CRON_TEST');
        unset($_ENV['MYWHOOSHSYNC_CRON_TEST'], $_SERVER['MYWHOOSHSYNC_CRON_TEST']);
    }
});

it('throws a runtime exception when the file does not exist', function () {
    EnvFile::read(sys_get_temp_dir().'/definitely-missing-'.uniqid().'.env');
})->throws(RuntimeException::class);
