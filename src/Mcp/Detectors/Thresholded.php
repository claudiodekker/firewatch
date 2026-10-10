<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;
use SQLite3;

/**
 * @internal
 */
interface Thresholded extends Detector
{
    /**
     * Get the threshold the detector takes.
     */
    public function threshold(): Threshold;

    /**
     * Judge the records of the window against a threshold, worst first, and list at most the limit of the findings.
     *
     * @param  int|float  $threshold  the value the call asked for, else the default of the threshold
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement;
}
