<?php

use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Policy;

test('an action code SQLite does not have today is denied by its number', function () {
    expect(Policy::authorize(99, null, null))->toBe([Denied::ACTION, '99']);
});
