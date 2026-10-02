<?php

const README_MAX_LINES = 150;

const DOCUMENT_MAX_LINE_LENGTH = 600;

const CHANGELOG_MAX_ENTRY_LENGTH = 400;

test('the README is a short guide', function () {
    $lines = file(__DIR__.'/../../README.md', FILE_IGNORE_NEW_LINES);

    expect(count($lines))->toBeLessThanOrEqual(README_MAX_LINES);
});

test('every README line is readable', function () {
    $lines = file(__DIR__.'/../../README.md', FILE_IGNORE_NEW_LINES);
    $longest = max(array_map('strlen', $lines));

    expect($longest)->toBeLessThanOrEqual(DOCUMENT_MAX_LINE_LENGTH);
});

test('every changelog entry is a few sentences', function () {
    $lines = file(__DIR__.'/../../CHANGELOG.md', FILE_IGNORE_NEW_LINES);
    $longest = max(array_map('strlen', $lines));

    expect($longest)->toBeLessThanOrEqual(CHANGELOG_MAX_ENTRY_LENGTH);
});
