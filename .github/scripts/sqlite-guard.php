<?php

use ClaudioDekker\Firewatch\Store\Sqlite;

require __DIR__.'/../../vendor/autoload.php';

$role = $argv[1] ?? '';
$version = Sqlite::version();
$floor = Sqlite::MINIMUM_VERSION;

$fixedIn = array_column((new ReflectionClassConstant(Sqlite::class, 'WAL_RESET_BUG'))->getValue(), 1);
usort($fixedIn, version_compare(...));
$fixedIn = end($fixedIn);

echo "SQLite3::version() = {$version} (role: {$role}, floor {$floor}, fixed in {$fixedIn})\n";

$problem = match ($role) {
    'old' => Sqlite::isTooOld($version)
        ? "is below the store floor {$floor}"
        : (! Sqlite::hasWalResetBug($version) ? 'is outside every WAL_RESET_BUG range, so the mitigation would not run' : null),
    'new' => version_compare($version, $fixedIn, '<')
        ? "is below {$fixedIn}, so it still has the WAL-reset bug"
        : null,
    default => "has an unknown role '{$role}'",
};

if ($problem !== null) {
    fwrite(STDERR, "::error::SQLite {$version} {$problem}; pick another image for this cell.\n");
    exit(1);
}
