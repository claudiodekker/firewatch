<?php

use ClaudioDekker\Firewatch\Tests\Support\GitRepository;
use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;

/**
 * Get the deploy Nightwatch ends up with in a new application whose git lookup starts at a path.
 */
function deployUnderGit(string $start): ?string
{
    test()->gitStart = $start;
    test()->refreshApplication();

    return config('nightwatch.deployment');
}

/**
 * Get a repository with a first commit, deleted when the test ends.
 */
function gitRepository(string $format = 'sha1'): GitRepository
{
    $repository = GitRepository::create($format);

    gitCleanup($repository->path);

    return $repository;
}

/**
 * Get a repository with no commit yet, deleted when the test ends.
 */
function gitUnbornRepository(): GitRepository
{
    $repository = GitRepository::init();

    gitCleanup($repository->path);

    return $repository;
}

/**
 * Delete a directory when the test ends.
 */
function gitCleanup(string $path): void
{
    test()->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($path));
}

/**
 * Get an empty directory outside any repository, deleted when the test ends.
 */
function gitEmptyDirectory(): string
{
    $path = GitRepository::temporaryPath();

    (new Filesystem)->ensureDirectoryExists($path);
    gitCleanup($path);

    return $path;
}

it('labels records with the commit a branch points to, read from a loose ref', function () {
    $repository = gitRepository();

    expect(deployUnderGit($repository->path))->toBe($repository->head())->toMatch('/^[0-9a-f]{40}$/');
});

it('reads a branch that only packed-refs holds', function () {
    $repository = gitRepository();
    $repository->run('pack-refs', '--all');

    expect(file_exists("{$repository->path}/.git/refs/heads/main"))->toBeFalse()
        ->and(deployUnderGit($repository->path))->toBe($repository->head());
});

it('reads a detached HEAD', function () {
    $repository = gitRepository();
    $repository->run('checkout', '--detach');
    $repository->commit();

    expect(deployUnderGit($repository->path))->toBe($repository->head());
});

it('reads a SHA-256 repository in full', function () {
    $repository = gitRepository(format: 'sha256');

    expect(deployUnderGit($repository->path))->toBe($repository->head())->toMatch('/^[0-9a-f]{64}$/');
});

it('keeps the commit when the working tree has uncommitted edits', function () {
    $repository = gitRepository();
    $head = $repository->head();
    file_put_contents("{$repository->path}/file.txt", 'edited');

    expect(deployUnderGit($repository->path))->toBe($head);
});

it('reads the repository from a subdirectory of it', function () {
    $repository = gitRepository();
    $subdirectory = "{$repository->path}/app/deep";
    (new Filesystem)->ensureDirectoryExists($subdirectory);

    expect(deployUnderGit($subdirectory))->toBe($repository->head());
});

it('reads a linked worktree\'s own HEAD and the refs of the repository it belongs to', function () {
    $repository = gitRepository();
    $worktree = GitRepository::temporaryPath();
    gitCleanup($worktree);
    $repository->run('worktree', 'add', $worktree, '-b', 'feature');
    $linked = new GitRepository($worktree);
    $linked->commit();

    expect(is_file("{$worktree}/.git"))->toBeTrue()
        ->and($linked->head())->not->toBe($repository->head())
        ->and(deployUnderGit($worktree))->toBe($linked->head())
        ->and(deployUnderGit($repository->path))->toBe($repository->head());
});

it('reads a linked worktree whose branch is only in packed-refs', function () {
    $repository = gitRepository();
    $worktree = GitRepository::temporaryPath();
    gitCleanup($worktree);
    $repository->run('worktree', 'add', $worktree, '-b', 'feature');
    $repository->run('pack-refs', '--all');

    expect(deployUnderGit($worktree))->toBe($repository->head());
});

it('reads a submodule, whose .git file names a relative git directory', function () {
    $module = gitRepository();
    $parent = gitRepository();
    $parent->run('submodule', 'add', $module->path, 'vendor/module');
    $checkout = new GitRepository("{$parent->path}/vendor/module");

    expect(is_file("{$checkout->path}/.git"))->toBeTrue()
        ->and(deployUnderGit($checkout->path))->toBe($module->head());
});

it('follows a .git file that names an absolute git directory', function () {
    $repository = gitRepository();
    $application = gitEmptyDirectory();
    file_put_contents("{$application}/.git", "gitdir: {$repository->path}/.git\n");

    expect(deployUnderGit($application))->toBe($repository->head());
});

it('lowercases the commit', function () {
    $repository = gitRepository();
    file_put_contents("{$repository->path}/.git/HEAD", strtoupper($repository->head())."\n");

    expect(deployUnderGit($repository->path))->toBe($repository->head());
});

it('skips the header and peeled lines of packed-refs', function () {
    $repository = gitUnbornRepository();
    $packed = "# pack-refs with: peeled fully-peeled sorted\n".str_repeat('a', 40)." refs/tags/v1\n^".str_repeat('b', 40)."\n".str_repeat('c', 40)." refs/heads/main\n";
    file_put_contents("{$repository->path}/.git/packed-refs", $packed);

    expect(deployUnderGit($repository->path))->toBe(str_repeat('c', 40));
});

it('gives no deploy and no notice for what it cannot read', function (Closure $arrange) {
    $repository = gitUnbornRepository();
    $arrange($repository);

    expect(deployUnderGit($repository->path))->toBeNull()
        ->and(notices())->toBe([]);
})->with([
    'an unborn branch' => fn () => null,
    'a ref that is not found' => fn (GitRepository $repository) => file_put_contents("{$repository->path}/.git/HEAD", "ref: refs/heads/missing\n"),
    'a reftable repository on a branch' => fn (GitRepository $repository) => file_put_contents("{$repository->path}/.git/HEAD", "ref: refs/heads/.invalid\n"),
    'a HEAD that does not parse' => fn (GitRepository $repository) => file_put_contents("{$repository->path}/.git/HEAD", "not a head\n"),
    'a ref file that does not hold a commit' => function (GitRepository $repository) {
        file_put_contents("{$repository->path}/.git/refs/heads/main", "garbage\n");
        file_put_contents("{$repository->path}/.git/HEAD", "ref: refs/heads/main\n");
    },
    'a ref that climbs out of the repository' => fn (GitRepository $repository) => file_put_contents("{$repository->path}/.git/HEAD", "ref: ../../HEAD\n"),
    'a HEAD that is a directory' => function (GitRepository $repository) {
        unlink("{$repository->path}/.git/HEAD");
        mkdir("{$repository->path}/.git/HEAD");
    },
    'a .git file that names nothing' => function (GitRepository $repository) {
        (new Filesystem)->deleteDirectory("{$repository->path}/.git");
        file_put_contents("{$repository->path}/.git", "gitdir: nowhere\n");
    },
    'a .git file that is not a gitdir line' => function (GitRepository $repository) {
        (new Filesystem)->deleteDirectory("{$repository->path}/.git");
        file_put_contents("{$repository->path}/.git", "nonsense\n");
    },
]);

it('gives no deploy when there is no repository', function () {
    expect(deployUnderGit(gitEmptyDirectory()))->toBeNull()
        ->and(notices())->toBe([]);
});

it('uses the git commit only when neither Firewatch nor Nightwatch has a deploy', function (array $variables, ?string $expected) {
    $repository = gitRepository();

    foreach ($variables as $name => $value) {
        setEnvironmentVariable($name, $value);
    }

    expect(deployUnderGit($repository->path))->toBe($expected ?? $repository->head());
})->with([
    'none' => [[], null],
    'FIREWATCH_DEPLOY' => [['FIREWATCH_DEPLOY' => 'firewatch-deploy'], 'firewatch-deploy'],
    'NIGHTWATCH_DEPLOY' => [['NIGHTWATCH_DEPLOY' => 'nightwatch-deploy'], 'nightwatch-deploy'],
    'FORGE_DEPLOY_COMMIT' => [['FORGE_DEPLOY_COMMIT' => 'forge-commit'], 'forge-commit'],
    'LARAVEL_CLOUD_DEPLOY_UUID' => [['LARAVEL_CLOUD_DEPLOY_UUID' => 'cloud-uuid'], 'cloud-uuid'],
    'VAPOR_COMMIT_HASH' => [['VAPOR_COMMIT_HASH' => 'vapor-hash'], 'vapor-hash'],
    'both' => [['FIREWATCH_DEPLOY' => 'firewatch-deploy', 'NIGHTWATCH_DEPLOY' => 'nightwatch-deploy'], 'firewatch-deploy'],
]);

it('leaves a deploy published in the application\'s nightwatch config file over git HEAD', function () {
    test()->gitStart = gitRepository()->path;
    config()->set('nightwatch.deployment', 'published-deploy');

    registerFirewatch();

    expect(config('nightwatch.deployment'))->toBe('published-deploy');
});

it('gives git HEAD the deploy, with no notice, when Nightwatch\'s default config file is missing', function () {
    $installed = InstalledVersions::getAllRawData()[0];
    $emptyInstall = gitEmptyDirectory();
    $moved = $installed;
    $moved['versions']['laravel/nightwatch']['install_path'] = $emptyInstall;
    InstalledVersions::reload($moved);
    test()->beforeApplicationDestroyed(fn () => InstalledVersions::reload($installed));
    $repository = gitRepository();

    expect(is_file("{$emptyInstall}/config/nightwatch.php"))->toBeFalse()
        ->and(deployUnderGit($repository->path))->toBe($repository->head())
        ->and(notices())->toBe([]);
});
