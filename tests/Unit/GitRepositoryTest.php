<?php

use ClaudioDekker\Firewatch\Tests\Support\GitRepository;

it('keeps its repository apart from the one the environment points git at', function () {
    $outer = GitRepository::create();
    $outerHead = $outer->head();
    $outerBranches = $outer->run('branch', '--all');
    $previous = getenv('GIT_DIR');

    putenv('GIT_DIR='.$outer->path.'/.git');
    $_SERVER['GIT_DIR'] = $outer->path.'/.git';

    try {
        $inner = GitRepository::create();
        $inner->run('switch', '--create=feature');
        $inner->commit();
    } finally {
        unset($_SERVER['GIT_DIR']);
        $previous === false ? putenv('GIT_DIR') : putenv("GIT_DIR={$previous}");
    }

    try {
        expect($outer->head())->toBe($outerHead)
            ->and($outer->run('branch', '--all'))->toBe($outerBranches)
            ->and($inner->run('rev-list', '--count', 'HEAD'))->toBe('2')
            ->and($inner->run('branch', '--show-current'))->toBe('feature');
    } finally {
        $outer->delete();
        $inner->delete();
    }
})->group('process');
