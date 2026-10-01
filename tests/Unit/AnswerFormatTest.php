<?php

use ClaudioDekker\Firewatch\Mcp\AnswerFormat;

it('reads a format argument exactly', function (mixed $argument, ?AnswerFormat $format) {
    expect(AnswerFormat::fromArgument($argument))->toBe($format);
})->with([
    'an absent argument' => [null, AnswerFormat::MARKDOWN],
    'markdown' => ['markdown', AnswerFormat::MARKDOWN],
    'json' => ['json', AnswerFormat::JSON],
    'json in capitals' => ['JSON', null],
    'json with spaces' => [' json ', null],
    'another format' => ['xml', null],
    'an empty string' => ['', null],
    'a number' => [1, null],
]);
