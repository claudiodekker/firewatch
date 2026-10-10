<?php

namespace ClaudioDekker\Firewatch\Console\Concerns;

/**
 * @internal
 */
trait FormatsFileSizes
{
    /**
     * Format a byte count with one decimal, in the next unit up from 0.9 of it.
     */
    protected function fileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size / 1024 > 0.9 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return number_format(round($size, 1, PHP_ROUND_HALF_EVEN), 1).' '.$units[$unit];
    }
}
