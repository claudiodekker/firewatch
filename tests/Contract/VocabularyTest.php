<?php

const AVOIDED_IN_CODE = ['envelope' => 'answer'];

function glossaryAvoids(string $word): bool
{
    return preg_match('/^_Avoid_:.*\b'.preg_quote($word, '/').'\b/mi', file_get_contents(__DIR__.'/../../GLOSSARY.md')) === 1;
}

test('the words the glossary says to avoid stay out of the code and the standards', function (string $word, string $use) {
    $root = realpath(__DIR__.'/../..');
    $files = [$root.'/CODING_STANDARDS.md'];

    foreach (['src', 'tests'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && $file->getFilename() !== 'VocabularyTest.php') {
                $files[] = $file->getPathname();
            }
        }
    }

    expect(glossaryAvoids($word))->toBeTrue("GLOSSARY.md no longer lists {$word} as avoided");

    $found = array_values(array_filter($files, fn (string $path) => stripos(file_get_contents($path), $word) !== false));

    expect(array_map(fn (string $path) => substr($path, strlen($root) + 1), $found))->toBe([], "Say {$use} instead of {$word}.");
})->with(array_map(null, array_keys(AVOIDED_IN_CODE), AVOIDED_IN_CODE));
