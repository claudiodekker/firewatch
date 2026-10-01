<?php

use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\AnswerFormat;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\Emptiness;
use ClaudioDekker\Firewatch\Mcp\EmptyKind;
use ClaudioDekker\Firewatch\Mcp\Markdown;
use ClaudioDekker\Firewatch\Mcp\Window;

function answerWith(mixed ...$overrides): Answer
{
    return new Answer(...[
        'tool' => 'overview',
        'now' => 1790776800.25,
        'timezone' => 'UTC',
        'window' => Window::between(null, null, 'UTC'),
        'summary' => 'Two requests.',
        'empty' => null,
        'result' => ['requests' => 2],
        'coverage' => new Coverage(CoverageState::OK, oldest: 1790776000.5, newest: 1790776700.0, records: 1234),
        ...$overrides,
    ]);
}

test('an answer has every envelope key, in order, with an empty result as an object', function () {
    $answer = answerWith(result: [])->toArray();

    expect(array_keys($answer))->toBe(['tool', 'now', 'window', 'summary', 'empty', 'result', 'coverage', 'blind_spots', 'notes', 'truncated', 'next'])
        ->and(json_encode($answer['result']))->toBe('{}')
        ->and($answer)->toMatchArray(['empty' => null, 'blind_spots' => [], 'notes' => [], 'truncated' => [], 'next' => []]);
});

it('prints the whole envelope in its fixed layout', function () {
    $answer = answerWith(
        summary: 'One slow route.',
        empty: Emptiness::windowEmpty(40),
        result: [
            'requests' => 2,
            'slowest' => [['route' => 'GET /', 'p95_ms' => 12.5], ['route' => 'POST /a|b', 'p95_ms' => null]],
        ],
        blindSpots: [['id' => 'console-requests', 'kind' => 'structural', 'message' => 'Requests only over HTTP.']],
        notes: ['Move since to the last anchor.'],
        truncated: [
            ['section' => 'slowest', 'shown' => 2, 'matched' => 7, 'reason' => 'limit', 'how' => 'Pass the cursor.'],
            ['section' => 'queries', 'shown' => 20, 'matched' => null, 'reason' => 'size', 'how' => 'Narrow the call.'],
        ],
        next: [
            ['tool' => 'occurrences', 'arguments' => ['type' => 'request', 'limit' => 5], 'why' => 'the slowest request'],
            ['tool' => 'describe', 'arguments' => [], 'why' => 'the store'],
        ],
    );

    expect($answer->toMarkdown())->toBe(implode("\n", [
        '## overview',
        'One slow route.',
        'Window: none (unbounded)',
        'Store clock: 2026-09-30 14:00:00.250000 (epoch 1790776800.25) - pass that number as since, until or split_at to measure what happens next against what came before',
        'No records fall in this window, and the store holds 40 records: widen the window or move it.',
        '- **requests**: 2',
        '',
        '### slowest',
        '',
        '| route | p95_ms |',
        '| --- | --- |',
        '| GET / | 12.5 |',
        '| POST /a\|b | n/a |',
        '',
        'Store: ok, 2026-09-30 13:46:40.500000 to 2026-09-30 13:58:20.000000, 1,234 records',
        'Truncated: slowest shows 2 of 7 (limit). Pass the cursor.',
        'Truncated: queries shows 20 of more (size). Narrow the call.',
        '> Move since to the last anchor.',
        'Blind spot (console-requests): Requests only over HTTP.',
        'Next:',
        '- occurrences(type: "request", limit: 5) - the slowest request',
        '- describe() - the store',
    ]));
});

it('prints only the lines that apply', function () {
    $markdown = answerWith(coverage: new Coverage(CoverageState::ABSENT), result: [])->toMarkdown();

    expect($markdown)->toBe(implode("\n", [
        '## overview',
        'Two requests.',
        'Window: none (unbounded)',
        'Store clock: 2026-09-30 14:00:00.250000 (epoch 1790776800.25) - pass that number as since, until or split_at to measure what happens next against what came before',
        'Store: absent',
    ]));
});

it('prints the times of an answer in the application timezone', function () {
    $markdown = answerWith(timezone: 'Europe/Amsterdam', window: Window::between(1790776800.0, null, 'Europe/Amsterdam'))->toMarkdown();

    expect($markdown)->toContain('Window: since 2026-09-30 16:00:00.000000 until none (unbounded) (Europe/Amsterdam, half-open)')
        ->toContain('Store clock: 2026-09-30 16:00:00.250000 (epoch 1790776800.25)');
});

test('a window states its bounds, or that it has none', function (Window $window, array $json, string $line) {
    expect($window->toArray())->toBe($json)
        ->and($window->line())->toBe($line);
})->with([
    'unbounded' => [
        fn () => Window::between(null, null, 'UTC'),
        ['windowed' => true, 'basis' => 'started_at', 'since' => null, 'until' => null, 'timezone' => 'UTC', 'description' => 'Half-open on started_at: a record at since is in, a record at until is out; an absent bound is unbounded.'],
        'Window: none (unbounded)',
    ],
    'both bounds' => [
        fn () => Window::between(1790776800.0, 1790780400.5, 'UTC'),
        ['windowed' => true, 'basis' => 'started_at', 'since' => 1790776800.0, 'until' => 1790780400.5, 'timezone' => 'UTC', 'description' => 'Half-open on started_at: a record at since is in, a record at until is out; an absent bound is unbounded.'],
        'Window: since 2026-09-30 14:00:00.000000 until 2026-09-30 15:00:00.500000 (UTC, half-open)',
    ],
    'only until' => [
        fn () => Window::between(null, 1790780400.0, 'UTC'),
        ['windowed' => true, 'basis' => 'started_at', 'since' => null, 'until' => 1790780400.0, 'timezone' => 'UTC', 'description' => 'Half-open on started_at: a record at since is in, a record at until is out; an absent bound is unbounded.'],
        'Window: since none (unbounded) until 2026-09-30 15:00:00.000000 (UTC, half-open)',
    ],
    'not windowed' => [
        fn () => Window::none('it reads one execution', 'UTC'),
        ['windowed' => false, 'reason' => 'it reads one execution'],
        'Not windowed: it reads one execution',
    ],
]);

test('each cell prints by one set of rules', function (mixed $value, string $cell) {
    expect(Markdown::cell($value))->toBe($cell);
})->with([
    'null' => [null, 'n/a'],
    'true' => [true, 'yes'],
    'false' => [false, 'no'],
    'an integer' => [12, '12'],
    'a float' => [12.5, '12.5'],
    'a whole float' => [3.0, '3.0'],
    'text' => ['plain', 'plain'],
    'a pipe' => ['a|b', 'a\|b'],
    'a line break' => ["one\r\ntwo\nthree", 'one two three'],
    'a list' => [[1, 'two', null], '[1,"two",null]'],
    'an object' => [['a' => true, 'b' => '/'], '{"a":true,"b":"/"}'],
]);

test('rows print as a table only when they are one shape', function (array $value, string $line) {
    $markdown = answerWith(result: ['rows' => $value])->toMarkdown();

    expect($markdown)->toContain($line);
})->with([
    'rows of one shape' => [[['a' => 1], ['a' => 2]], '| a |'],
    'rows of different shapes' => [[['a' => 1], ['b' => 2]], '- **rows**: [{"a":1},{"b":2}]'],
    'rows of different order' => [[['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]], '- **rows**: [{"a":1,"b":2},{"b":2,"a":1}]'],
    'no rows' => [[], '- **rows**: []'],
    'a list of values' => [[1, 2], '- **rows**: [1,2]'],
    'a list of empty rows' => [[[], []], '- **rows**: [[],[]]'],
    'a list of lists' => [[[1], [2]], '- **rows**: [[1],[2]]'],
    'a list of a row and a value' => [[['a' => 1], 2], '- **rows**: [{"a":1},2]'],
]);

test('each empty kind carries its population and its fixed words', function (Emptiness $empty, string $kind, ?int $population, string $message, string $summary) {
    expect(answerWith(empty: $empty)->toArray()['empty'])->toBe(['kind' => $kind, 'population' => $population, 'message' => $message])
        ->and($empty->summary())->toBe($summary);
})->with([
    'no store' => [
        fn () => Emptiness::noStore('/s/firewatch.sqlite'),
        'no_store',
        null,
        'No application process has written a store at /s/firewatch.sqlite yet: exercise the application, then ask again.',
        'Nothing to report: no store has been written yet.',
    ],
    'an unusable store' => [
        fn () => new Emptiness(EmptyKind::STORE_UNUSABLE, null, 'The store can not be read.'),
        'store_unusable',
        null,
        'The store can not be read.',
        'Nothing to report: the store can not be used.',
    ],
    'an empty store' => [
        fn () => Emptiness::storeEmpty('/s/firewatch.sqlite'),
        'store_empty',
        0,
        'The store at /s/firewatch.sqlite holds no records: nothing was captured yet, or it was cleared. Exercise the application, then ask again.',
        'Nothing to report: the store holds no records.',
    ],
    'an empty window' => [
        fn () => Emptiness::windowEmpty(40),
        'window_empty',
        40,
        'No records fall in this window, and the store holds 40 records: widen the window or move it.',
        'Nothing to report: no records fall in the window.',
    ],
    'no match' => [
        fn () => Emptiness::noMatch(12, ['type=request', 'status=500']),
        'no_match',
        12,
        'No record matched the filters (type=request, status=500), among 12 records before filtering.',
        'Nothing to report: no records matched the filters.',
    ],
]);

test('a summary is cut at a word boundary within 300 characters', function (string $summary, string $expected) {
    $fitted = answerWith(summary: $summary)->summary;

    expect($fitted)->toBe($expected)
        ->and(mb_strlen($fitted))->toBeLessThanOrEqual(300);
})->with([
    'one that fits' => ['Short and  tidy.', 'Short and tidy.'],
    'one of exactly 300 characters' => [str_repeat('a', 300), str_repeat('a', 300)],
    'one cut between words' => [str_repeat('word ', 80), rtrim(str_repeat('word ', 60))],
    'one with a long word' => [str_repeat('a', 400), str_repeat('a', 300)],
    'one that ends on punctuation after the cut' => [str_repeat('a', 290).' bb, '.str_repeat('c', 20), str_repeat('a', 290).' bb'],
    'one of multibyte characters' => [str_repeat('é', 301), str_repeat('é', 300)],
]);

test('an answer carries at most five notes and five next calls', function (int $count, bool $allowed) {
    $notes = array_fill(0, $count, 'A note.');
    $next = array_fill(0, $count, ['tool' => 'describe', 'arguments' => [], 'why' => 'the store']);

    if ($allowed) {
        expect(answerWith(notes: $notes, next: $next)->toArray())->toMatchArray(['notes' => $notes, 'next' => $next]);
    } else {
        expect(fn () => answerWith(notes: $notes))->toThrow(InvalidArgumentException::class)
            ->and(fn () => answerWith(next: $next))->toThrow(InvalidArgumentException::class);
    }
})->with([
    'five' => [5, true],
    'six' => [6, false],
]);

test('the store line states the coverage', function (Coverage $coverage, string $line, array $json) {
    expect($coverage->line('UTC'))->toBe($line)
        ->and($coverage->toArray())->toBe($json);
})->with([
    'an absent store' => [
        new Coverage(CoverageState::ABSENT),
        'Store: absent',
        ['state' => 'absent', 'reason' => null, 'oldest_at' => null, 'newest_at' => null, 'records' => null],
    ],
    'an unusable store' => [
        new Coverage(CoverageState::UNUSABLE, 'foreign_file'),
        'Store: unusable (foreign_file)',
        ['state' => 'unusable', 'reason' => 'foreign_file', 'oldest_at' => null, 'newest_at' => null, 'records' => null],
    ],
    'an empty store' => [
        new Coverage(CoverageState::EMPTY, records: 0),
        'Store: empty, 0 records',
        ['state' => 'empty', 'reason' => null, 'oldest_at' => null, 'newest_at' => null, 'records' => 0],
    ],
    'a store with records' => [
        new Coverage(CoverageState::OK, oldest: 1790776000.5, newest: 1790776700.0, records: 1234567),
        'Store: ok, 2026-09-30 13:46:40.500000 to 2026-09-30 13:58:20.000000, 1,234,567 records',
        ['state' => 'ok', 'reason' => null, 'oldest_at' => 1790776000.5, 'newest_at' => 1790776700.0, 'records' => 1234567],
    ],
]);

it('answers in markdown as one text block and in JSON as structured content beside the same JSON', function () {
    $answer = answerWith();

    $markdown = $answer->response(AnswerFormat::MARKDOWN);
    $json = $answer->response(AnswerFormat::JSON);

    expect($markdown->content()->toArray()['text'])->toBe($answer->toMarkdown())
        ->and($json->responses()->count())->toBe(1)
        ->and($json->getStructuredContent())->toBe($answer->toArray())
        ->and($json->responses()->first()->content()->toArray()['text'])->toBe(json_encode($answer->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});
