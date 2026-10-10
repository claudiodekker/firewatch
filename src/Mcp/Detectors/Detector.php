<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 */
interface Detector
{
    /**
     * Get the name of the shape the detector judges.
     */
    public function name(): DetectorName;

    /**
     * Get the record types the detector examines, which decide the blind spots and the coverage of its answer.
     *
     * @return list<RecordType>
     */
    public function types(): array;

    /**
     * Judge the records of the window, worst first, and list at most the limit of the findings.
     */
    public function judge(SQLite3 $connection, Window $window, ?string $group, int $limit): Judgement;
}
