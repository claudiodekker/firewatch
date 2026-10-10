<?php

use ClaudioDekker\Firewatch\Console\Concerns\FormatsFileSizes;

it('formats a size as Number::fileSize does with one decimal, without the intl extension', function (int $bytes, string $formatted) {
    $formatter = new class
    {
        use FormatsFileSizes;

        public function format(int $bytes): string
        {
            return $this->fileSize($bytes);
        }
    };

    expect($formatter->format($bytes))->toBe($formatted);
})->with([
    'zero' => [0, '0.0 B'],
    'the last size in bytes' => [921, '921.0 B'],
    'the first size in kilobytes' => [922, '0.9 KB'],
    'one kilobyte' => [1024, '1.0 KB'],
    'a tie rounded down to even' => [1280, '1.2 KB'],
    'a tie rounded up to even' => [1792, '1.8 KB'],
    'the largest size in a unit' => [943718, '921.6 KB'],
    'the largest integer' => [PHP_INT_MAX, '8.0 EB'],
]);
