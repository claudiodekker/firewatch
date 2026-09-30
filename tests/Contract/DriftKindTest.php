<?php

use ClaudioDekker\Firewatch\Capture\DriftKind;

test('the drift kinds are the six of the design', function () {
    expect(array_column(DriftKind::cases(), 'value'))->toBe(['unknown_type', 'unknown_version', 'unknown_field', 'missing_field', 'structure', 'version']);
});
