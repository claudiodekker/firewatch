<?php

use ClaudioDekker\Firewatch\Mcp\CompiledView;

it('prints the template a footer names in the host\'s separator, relative to the base path when under it', function (string $template, string $basePath, string $separator, string $printed) {
    expect(CompiledView::printed($template, $basePath, $separator))->toBe($printed);
})->with([
    'windows footer with a mixed separator, under the base path' => ['D:\\app\\resources\\views/posts.blade.php', 'D:\\app', '\\', 'resources\\views\\posts.blade.php'],
    'windows footer with a mixed separator, outside the base path' => ['D:\\pkg\\workbench\\resources\\views/posts.blade.php', 'D:\\app', '\\', 'D:\\pkg\\workbench\\resources\\views\\posts.blade.php'],
    'posix footer under the base path' => ['/srv/app/resources/views/posts.blade.php', '/srv/app', '/', 'resources/views/posts.blade.php'],
    'posix footer outside the base path' => ['/srv/pkg/resources/views/posts.blade.php', '/srv/app', '/', '/srv/pkg/resources/views/posts.blade.php'],
    'a sibling directory that shares the base path\'s prefix' => ['/srv/application/resources/views/posts.blade.php', '/srv/app', '/', '/srv/application/resources/views/posts.blade.php'],
]);
