<?php

use ClaudioDekker\Firewatch\Mcp\Detectors\MessageShape;

it('replaces ids and numbers by placeholders and nothing else', function (string $message, string $shape) {
    expect(MessageShape::of($message)->text)->toBe($shape);
})->with([
    'a UUID' => ['Payment 3fa85f64-5717-4562-b3fc-2c963f66afa6 declined', 'Payment <id> declined'],
    'a UUID in capitals' => ['Payment 3FA85F64-5717-4562-B3FC-2C963F66AFA6 declined', 'Payment <id> declined'],
    'a UUID after hex letters, as one id' => ['aaaa12345678-1234-1234-1234-123456789abc', 'aaaa<id>'],
    'a UUID before hex letters, as one id' => ['12345678-1234-1234-1234-123456789abcdef', '<id>def'],
    'a hex run of eight' => ['token deadbeef expired', 'token <id> expired'],
    'a hex run of forty' => ['commit '.str_repeat('a1', 20).' failed', 'commit <id> failed'],
    'a hex run in capitals' => ['token DEADBEEF expired', 'token <id> expired'],
    'a hex run of seven, kept but for its digits' => ['token deadbe7 expired', 'token deadbe<n> expired'],
    'a word of six hex letters, kept' => ['a decade of faded cafe facades', 'a decade of faded cafe facades'],
    'a word of hex letters and digits, as an id' => ['The Facade123 broke', 'The <id> broke'],
    'a digit run' => ['Order 42 failed after 3 tries', 'Order <n> failed after <n> tries'],
    'a digit run of seven' => ['Order 1234567 failed', 'Order <n> failed'],
    'a digit run of eight, as an id' => ['Order 12345678 failed', 'Order <id> failed'],
    'digits inside a word' => ['utf8mb4 on db01', 'utf<n>mb<n> on db<n>'],
    'a time' => ['Timed out at 12:34', 'Timed out at <n>:<n>'],
    'a decimal' => ['Took 1.5 seconds', 'Took <n>.<n> seconds'],
    'a negative number' => ['Balance -12', 'Balance -<n>'],
    'the truncation marker' => ['Payload ... [truncated, 70000 bytes total]', 'Payload ... [truncated, <n> bytes total]'],
    'a quoted string and an email address, kept' => ['User "ann" <ann@example.test> locked', 'User "ann" <ann@example.test> locked'],
    'a digit of another script, kept' => ['Order ٤٢ failed', 'Order ٤٢ failed'],
    'multibyte text around a number' => ['Bestelling 42 geannuleerd: café gesloten', 'Bestelling <n> geannuleerd: café gesloten'],
    'a placeholder the message already held' => ['Saw <id> and <n> here', 'Saw <id> and <n> here'],
    'only an id' => ['3fa85f64-5717-4562-b3fc-2c963f66afa6', '<id>'],
    'only whitespace' => ["  \n", "  \n"],
    'nothing' => ['', ''],
]);

it('takes the longest literal run between the placeholders for the fragment', function (string $message, ?string $fragment) {
    expect(MessageShape::of($message)->fragment())->toBe($fragment);
})->with([
    'the longest run, trimmed' => ['Payment 3fa85f64-5717-4562-b3fc-2c963f66afa6 declined for order 4471', 'declined for order'],
    'the whole message when it has no placeholder' => ['The payment is slow.', 'The payment is slow.'],
    'the earliest of two runs as long as each other' => ['abc 1 xyz', 'abc'],
    'the earliest of three runs as long as each other' => ['ab 1 cd 2 ef', 'ab'],
    'a later run that is longer' => ['abc 1 wxyz', 'wxyz'],
    'the run with the most characters, not the most bytes' => ['ééé 1 abcd', 'abcd'],
    'a run of punctuation only' => ['12:34', ':'],
    'a run around a placeholder the message already held' => ['Saw <id> here', 'here'],
    'none when every run is whitespace' => ['3fa85f64-5717-4562-b3fc-2c963f66afa6 42 7', null],
    'none for only an id' => ['deadbeef', null],
    'none for only whitespace' => ["  \n", null],
    'none for nothing' => ['', null],
    'a run of exactly 200 characters, whole' => [str_repeat('x', 200).' 1', str_repeat('x', 200)],
    'the first 200 characters of a longer run' => [str_repeat('x', 201).' 1', str_repeat('x', 200)],
    'the first 200 characters of a multibyte run, not bytes' => [str_repeat('é', 250), str_repeat('é', 200)],
    'a multibyte character at the cut, kept whole' => [str_repeat('x', 199).'é'.str_repeat('y', 50), str_repeat('x', 199).'é'],
    'no whitespace the cut ends on' => [str_repeat('x', 199).' '.str_repeat('y', 50), str_repeat('x', 199)],
    'the run that was longest before the cut' => [str_repeat('x', 250).' 1 '.str_repeat('y', 500), str_repeat('y', 200)],
    'the earliest of two cut runs as long as each other' => [str_repeat('x', 250).' 1 '.str_repeat('y', 250), str_repeat('x', 200)],
]);

it('gives a fragment that every message of the shape holds', function (string $first, string $second) {
    $shape = MessageShape::of($first);

    expect(MessageShape::of($second)->text)->toBe($shape->text)
        ->and($first)->toContain($shape->fragment())
        ->and($second)->toContain($shape->fragment());
})->with([
    'ids and numbers' => ['Payment 3fa85f64-5717-4562-b3fc-2c963f66afa6 declined for order 4471', 'Payment deadbeefdeadbeef declined for order 9'],
    'a cut run' => [str_repeat('x', 199).' '.str_repeat('y', 50).' 1', str_repeat('x', 199).' '.str_repeat('y', 50).' 22'],
]);
