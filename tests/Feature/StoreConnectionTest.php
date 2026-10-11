<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Store\Markers;
use ClaudioDekker\Firewatch\Store\Reader;
use ClaudioDekker\Firewatch\Store\Writer;
use Illuminate\Support\Facades\Cache;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * Count the handles this process holds open on a file for reading and writing.
 *
 * SQLite keeps a closed connection's handle open while another connection in the process holds a lock on the file, so the reader's read-only handles are left out.
 */
function writeHandles(string $path): int
{
    $path = realpath($path);

    if (is_dir('/proc/self/fd')) {
        $handles = array_filter(glob('/proc/self/fd/*') ?: [], fn (string $fd) => @readlink($fd) === $path
            && preg_match('/^flags:\s+(\d+)/m', (string) @file_get_contents('/proc/self/fdinfo/'.basename($fd)), $flags) === 1
            && (octdec($flags[1]) & 3) === 2);

        return count($handles);
    }

    exec('lsof -nP -Fan -p '.getmypid(), $lines);

    $handles = 0;

    foreach ($lines as $index => $line) {
        if ($line === 'au' && ($lines[$index + 1] ?? null) === 'n'.$path) {
            $handles++;
        }
    }

    return $handles;
}

it('holds one connection to the store for an ingest and the prune pass after it', function () {
    app()->when(Writer::class)->needs('$sqliteVersion')->give('3.51.3');
    registerFirewatch();

    Cache::get('kept');
    Nightwatch::digest();

    $claimed = app(Reader::class)->snapshot(fn (SQLite3 $connection) => Markers::read($connection)->pruneClaimedAt);

    expect($claimed)->not->toBeNull()
        ->and(writeHandles(app(Configuration::class)->database))->toBe(1);
})->group('posix', 'process');
