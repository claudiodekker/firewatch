<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;
use SQLite3;

/**
 * @internal
 */
interface Thresholdless extends Detector
{
    /**
     * Judge the records of the window, worst first, and list at most the limit of the findings.
     */
    public function judge(SQLite3 $connection, Window $window, ?string $group, int $limit): Judgement;
}
