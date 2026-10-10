<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors\Concerns;

use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Window;
use SQLite3;

/**
 * @internal
 */
trait JudgesAtItsDefault
{
    /**
     * Judge the records of the window against the default of the threshold, worst first, and list at most the limit of the findings.
     */
    public function judge(SQLite3 $connection, Window $window, ?string $group, int $limit): Judgement
    {
        return $this->judgeAt($connection, $window, $this->threshold()->default, $group, $limit);
    }
}
