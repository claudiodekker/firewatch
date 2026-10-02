<?php

const README_MAX_LINES = 150;

const DOCUMENT_MAX_LINE_LENGTH = 600;

const CHANGELOG_MAX_ENTRY_LENGTH = 400;

it('keeps the README a short guide', function () {
    $lines = file(__DIR__.'/../../README.md', FILE_IGNORE_NEW_LINES);

    expect(count($lines))->toBeLessThanOrEqual(README_MAX_LINES);
});

it('keeps every README line readable', function () {
    $lines = file(__DIR__.'/../../README.md', FILE_IGNORE_NEW_LINES);
    $longest = max(array_map('strlen', $lines));

    expect($longest)->toBeLessThanOrEqual(DOCUMENT_MAX_LINE_LENGTH);
});

it('keeps every changelog entry to a few sentences', function () {
    $lines = file(__DIR__.'/../../CHANGELOG.md', FILE_IGNORE_NEW_LINES);
    $longest = max(array_map('strlen', $lines));

    expect($longest)->toBeLessThanOrEqual(CHANGELOG_MAX_ENTRY_LENGTH);
});
