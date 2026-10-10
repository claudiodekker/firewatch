<?php

use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\Store\Writer;

require __DIR__.'/../../vendor/autoload.php';

$role = $argv[1] ?? '';
$version = SQLite3::version()['versionString'];
$floor = ModeResolver::MINIMUM_SQLITE_VERSION;

$fixedIn = array_column((new ReflectionClassConstant(Writer::class, 'WAL_RESET_BUG'))->getValue(), 1);
usort($fixedIn, version_compare(...));
$fixedIn = end($fixedIn);

echo "SQLite3::version() = {$version} (role: {$role}, floor {$floor}, fixed in {$fixedIn})\n";

$problem = match ($role) {
    'old' => version_compare($version, $floor, '<')
        ? "is below the store floor {$floor}"
        : (! Writer::hasWalResetBug($version) ? 'is outside every WAL_RESET_BUG range, so the mitigation would not run' : null),
    'new' => version_compare($version, $fixedIn, '<')
        ? "is below {$fixedIn}, so it still has the WAL-reset bug"
        : null,
    default => "has an unknown role '{$role}'",
};

if ($problem !== null) {
    fwrite(STDERR, "::error::SQLite {$version} {$problem}; pick another image for this cell.\n");
    exit(1);
}
