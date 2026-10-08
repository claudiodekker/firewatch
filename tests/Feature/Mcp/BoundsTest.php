<?php

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use ClaudioDekker\Firewatch\Mcp\Answer;
use ClaudioDekker\Firewatch\Mcp\Coverage;
use ClaudioDekker\Firewatch\Mcp\CoverageState;
use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Rows;
use ClaudioDekker\Firewatch\Mcp\Window;

function capHow(): string
{
    return __('firewatch::messages.cap_how', ['characters' => '2,000']);
}

function sizeHow(): string
{
    return __('firewatch::messages.size_how', ['characters' => '24,000']);
}

function boundedAnswer(array $result, array $truncated = [], ?array $cuttable = null): Answer
{
    return new Answer(
        'overview',
        1790776800.25,
        'UTC',
        Window::between(null, null, 'UTC'),
        'A summary.',
        null,
        $result,
        new Coverage(CoverageState::OK, [], History::unknown(null, null), oldest: 1790776000.5, newest: 1790776700.0, records: 3),
        [['id' => 'console-requests', 'kind' => 'structural', 'message' => 'Requests only over HTTP.']],
        ['A note.'],
        $truncated,
        [['tool' => 'describe', 'arguments' => [], 'why' => 'the store']],
        $cuttable,
    );
}

function bulkyRows(int $count, string $column = 'text', int $length = 1900): array
{
    return array_map(fn (int $number) => [$column => $number.str_repeat('x', $length)], range(1, $count));
}

function jsonCharacters(Answer $answer): int
{
    return mb_strlen(json_encode($answer->toArray(), RecordMapper::JSON_FLAGS));
}

describe('rows', function () {
    it('fetches one row beyond the limit', function () {
        expect(Rows::fetch(20))->toBe(21);
    });

    it('is complete when the reader returned exactly the limit', function () {
        $page = Rows::bound([1, 2, 3], 3);

        expect($page->rows)->toBe([1, 2, 3])
            ->and($page->more)->toBeFalse();
    });

    it('is complete when the reader returned fewer than the limit', function () {
        $page = Rows::bound([1, 2], 3);

        expect($page->rows)->toBe([1, 2])
            ->and($page->more)->toBeFalse();
    });

    it('cuts the row beyond the limit and states there are more', function () {
        $page = Rows::bound([1, 2, 3, 4], 3);

        expect($page->rows)->toBe([1, 2, 3])
            ->and($page->more)->toBeTrue();
    });

    it('states a cut by the limit with an unknown match count', function () {
        $page = Rows::bound([1, 2, 3, 4], 3);

        expect($page->truncation('slowest', 'Pass a larger limit.'))->toBe(['section' => 'slowest', 'shown' => 3, 'matched' => null, 'reason' => 'limit', 'how' => 'Pass a larger limit.'])
            ->and(Rows::bound([1, 2], 3)->truncation('slowest', 'Pass a larger limit.'))->toBeNull();
    });
});

describe('cells', function () {
    it('leaves a cell of 2,000 characters whole', function () {
        $answer = boundedAnswer(['text' => str_repeat('é', 2000)]);

        expect($answer->toArray()['result']['text'])->toBe(str_repeat('é', 2000))
            ->and($answer->toArray()['truncated'])->toBe([]);
    });

    it('cuts a longer cell at 2,000 characters and says how many it cut', function (string $character) {
        $answer = boundedAnswer(['text' => str_repeat($character, 2013)]);

        expect($answer->toArray()['result']['text'])->toBe(str_repeat($character, 2000).'... [truncated, 13 characters]');
    })->with(['an ASCII character' => ['x'], 'two bytes' => ['é'], 'four bytes' => ['😀']]);

    it('cuts cells inside a list, a table and a nested value, and states each section once', function () {
        $long = str_repeat('x', 2001);

        $answer = boundedAnswer([
            'plain' => $long,
            'rows' => [['a' => $long, 'b' => 'short'], ['a' => $long, 'b' => $long]],
            'untouched' => 'short',
        ]);

        expect($answer->toArray()['truncated'])->toBe([
            ['section' => 'plain', 'shown' => 1, 'matched' => null, 'reason' => 'cap', 'how' => capHow()],
            ['section' => 'rows', 'shown' => 3, 'matched' => null, 'reason' => 'cap', 'how' => capHow()],
        ]);
    });

    it('prints a cut section in markdown with the cells it cut', function () {
        $markdown = boundedAnswer(['plain' => str_repeat('x', 2001)])->toMarkdown();

        expect($markdown)->toContain('- **plain**: '.str_repeat('x', 2000).'... [truncated, 1 characters]')
            ->and($markdown)->toContain('Truncated: plain had cells cut at 2,000 characters, 1 in all (cap). '.capHow());
    });

    it('does not cut a key or a number', function () {
        $key = str_repeat('k', 2001);

        $answer = boundedAnswer([$key => 12345678901234567890.5]);

        expect(array_keys($answer->toArray()['result']))->toBe([$key]);
    });
});

describe('the answer budget', function () {
    it('keeps an answer under 24,000 characters whole', function () {
        $answer = boundedAnswer(['rows' => bulkyRows(10)]);

        expect($answer->toArray()['result']['rows'])->toHaveCount(10)
            ->and($answer->toArray()['truncated'])->toBe([]);
    });

    it('drops whole rows from the tail of the lowest-priority list, and says so', function () {
        $answer = boundedAnswer(['first' => bulkyRows(5), 'second' => bulkyRows(30)]);
        $envelope = $answer->toArray();

        expect($envelope['result']['first'])->toHaveCount(5)
            ->and(count($envelope['result']['second']))->toBeLessThan(30)->toBeGreaterThan(0)
            ->and(jsonCharacters($answer))->toBeLessThanOrEqual(24000)
            ->and(jsonCharacters($answer) + mb_strlen(json_encode(bulkyRows(1)[0])))->toBeGreaterThan(24000)
            ->and($envelope['result']['second'])->toBe(array_slice(bulkyRows(30), 0, count($envelope['result']['second'])))
            ->and($envelope['truncated'])->toBe([
                ['section' => 'second', 'shown' => count($envelope['result']['second']), 'matched' => 30, 'reason' => 'size', 'how' => sizeHow()],
            ]);
    });

    it('empties the lowest-priority list before it touches the one above', function () {
        $answer = boundedAnswer(['first' => bulkyRows(10), 'second' => bulkyRows(10), 'third' => bulkyRows(10)]);
        $envelope = $answer->toArray();

        expect($envelope['result']['third'])->toBe([])
            ->and($envelope['result']['first'])->toHaveCount(10)
            ->and(count($envelope['result']['second']))->toBeLessThan(10)
            ->and(array_column($envelope['truncated'], 'section'))->toBe(['third', 'second'])
            ->and($envelope['truncated'][0])->toMatchArray(['shown' => 0, 'matched' => 10, 'reason' => 'size']);
    });

    it('never drops the first row of the first list', function () {
        $answer = boundedAnswer(['first' => [array_fill_keys(range('a', 'n'), str_repeat('x', 1900))], 'second' => bulkyRows(3)]);

        expect($answer->toArray()['result']['first'])->toHaveCount(1)
            ->and($answer->toArray()['result']['second'])->toBe([])
            ->and(jsonCharacters($answer))->toBeGreaterThan(24000);
    });

    it('drops the tail of the first list when nothing below it is left', function () {
        $answer = boundedAnswer(['first' => bulkyRows(30)]);
        $envelope = $answer->toArray();

        expect(count($envelope['result']['first']))->toBeLessThan(30)->toBeGreaterThan(0)
            ->and($envelope['result']['first'][0])->toBe(bulkyRows(1)[0])
            ->and(jsonCharacters($answer))->toBeLessThanOrEqual(24000);
    });

    it('drops rows only from the lists it is told it may cut, in the order they are named', function () {
        $answer = boundedAnswer(['first' => bulkyRows(8), 'second' => bulkyRows(8), 'third' => bulkyRows(8), 'fourth' => bulkyRows(2)], cuttable: ['second', 'first']);
        $envelope = $answer->toArray();

        expect($envelope['result']['second'])->toBe([])
            ->and(count($envelope['result']['first']))->toBeLessThan(8)->toBeGreaterThan(0)
            ->and($envelope['result']['third'])->toHaveCount(8)
            ->and($envelope['result']['fourth'])->toHaveCount(2)
            ->and(jsonCharacters($answer))->toBeLessThanOrEqual(24000)
            ->and(array_column($envelope['truncated'], 'section'))->toBe(['second', 'first']);
    });

    it('keeps the first row of the last list it may cut, and every list it may not, when the answer stays over its budget', function () {
        $answer = boundedAnswer(['first' => bulkyRows(5), 'second' => bulkyRows(14)], cuttable: ['first']);
        $envelope = $answer->toArray();

        expect($envelope['result']['first'])->toBe(bulkyRows(1))
            ->and($envelope['result']['second'])->toHaveCount(14)
            ->and(jsonCharacters($answer))->toBeGreaterThan(24000)
            ->and($envelope['truncated'])->toBe([['section' => 'first', 'shown' => 1, 'matched' => 5, 'reason' => 'size', 'how' => sizeHow()]]);
    });

    it('never drops the summary, the blind spots, the notes or the next calls', function () {
        $envelope = boundedAnswer(['first' => bulkyRows(2), 'second' => bulkyRows(40)])->toArray();

        expect($envelope['summary'])->toBe('A summary.')
            ->and($envelope['blind_spots'])->toHaveCount(1)
            ->and($envelope['notes'])->toBe(['A note.'])
            ->and($envelope['next'])->toHaveCount(1);
    });

    it('turns a cut by the limit into a cut by size, keeping the count it matched', function () {
        $answer = boundedAnswer(
            ['first' => bulkyRows(5), 'second' => bulkyRows(30)],
            [['section' => 'second', 'shown' => 30, 'matched' => 45, 'reason' => 'limit', 'how' => 'Pass the cursor.']],
        );

        expect($answer->toArray()['truncated'])->toHaveCount(1)
            ->and($answer->toArray()['truncated'][0])->toMatchArray(['section' => 'second', 'matched' => 45, 'reason' => 'size', 'how' => sizeHow()]);
    });

    it('keeps a cut by the limit that the budget did not touch', function () {
        $entry = ['section' => 'first', 'shown' => 3, 'matched' => null, 'reason' => 'limit', 'how' => 'Pass the cursor.'];

        expect(boundedAnswer(['first' => bulkyRows(3)], [$entry])->toArray()['truncated'])->toBe([$entry]);
    });

    it('answers in markdown with the rows the JSON keeps', function () {
        $answer = boundedAnswer(['first' => bulkyRows(5), 'second' => bulkyRows(30)]);
        $kept = count($answer->toArray()['result']['second']);

        expect(substr_count($answer->toMarkdown(), "\n| "))->toBe(5 + $kept + 4)
            ->and($answer->toMarkdown())->toContain("Truncated: second shows {$kept} of 30 (size). ".sizeHow());
    });

    it('keeps an answer of exactly 24,000 characters and drops a row from one of 24,001', function () {
        $build = function (int $padding) {
            $rows = bulkyRows(11);
            $rows[] = ['text' => str_repeat('y', $padding)];

            return boundedAnswer(['first' => bulkyRows(1), 'second' => $rows]);
        };
        $overhead = jsonCharacters($build(0));
        $padding = 24000 - $overhead;

        expect($padding)->toBeBetween(0, 2000)
            ->and(jsonCharacters($build($padding)))->toBe(24000)
            ->and($build($padding)->toArray()['result']['second'])->toHaveCount(12)
            ->and($build($padding + 1)->toArray()['result']['second'])->toHaveCount(11);
    });

    it('states cells cut only in the rows the budget kept', function () {
        $long = str_repeat('x', 2001);
        $rows = array_map(fn (int $number) => ['text' => $long], range(1, 30));

        $envelope = boundedAnswer(['first' => bulkyRows(1), 'second' => $rows])->toArray();
        $kept = count($envelope['result']['second']);

        expect($kept)->toBeLessThan(30)
            ->and($envelope['truncated'])->toHaveCount(2)
            ->and($envelope['truncated'])->toContain(['section' => 'second', 'shown' => $kept, 'matched' => null, 'reason' => 'cap', 'how' => capHow()])
            ->and(array_column($envelope['truncated'], 'reason'))->toEqualCanonicalizing(['cap', 'size']);
    });
});
