<?php

const README_MAX_LINES = 230;

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

test('the documents and the output name the doctor command, never a doctor', function () {
    $root = __DIR__.'/../..';
    $files = [...glob("{$root}/*.md"), ...glob("{$root}/docs/adr/*.md"), "{$root}/lang/en/messages.php"];

    foreach ($files as $file) {
        expect(preg_match('/\\b(?:the|a) doctor\\b|\\bdoctor\'s\\b/i', file_get_contents($file)))->toBe(0, basename($file).' speaks of a doctor: write `firewatch:doctor`.');
    }
});
