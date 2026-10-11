<?php

use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\Mcp\FirewatchServer;
use ClaudioDekker\Firewatch\Mcp\Tools\Detect;
use ClaudioDekker\Firewatch\Mcp\Tools\Overview;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Reader;
use Illuminate\Support\Facades\Date;

/**
 * Bind a reader that counts how often a call reads the key column of the meta table, which is what reading the markers does.
 */
function markersReader(): object
{
    $reader = new class(app(Configuration::class)) extends Reader
    {
        public int $reads = 0;

        protected function open(): SQLite3
        {
            $connection = parent::open();

            $connection->setAuthorizer(function (int $action, ?string $first, ?string $second): int {
                if ($action === SQLite3::READ && $first === 'meta' && $second === 'key') {
                    $this->reads++;
                }

                return SQLite3::OK;
            });

            return $connection;
        }
    };

    app()->instance(Reader::class, $reader);

    return $reader;
}

beforeEach(function () {
    $this->travelTo(Date::createFromTimestamp(1790776000.0 + 600));
});

it('reads the markers of the store once for a tool call, whatever the number of detectors that judge', function (string $tool) {
    ingest([
        syntheticRecord(RecordType::REQUEST)->with(['timestamp' => 1790776000.0]),
        syntheticRecord(RecordType::QUERY)->with(['timestamp' => 1790776000.0]),
        syntheticRecord(RecordType::JOB_ATTEMPT)->with(['timestamp' => 1790776000.0]),
    ]);

    $reader = markersReader();

    FirewatchServer::tool($tool, ['format' => 'json']);

    expect($reader->reads)->toBe(1);
})->with([
    'an overview' => [Overview::class],
    'a detect of every shape' => [Detect::class],
]);
