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
     * Get the threshold the detector takes.
     */
    public function threshold(): ?Threshold;

    /**
     * Get the record types the detector examines, which decide the blind spots and the coverage of its answer.
     *
     * @return list<RecordType>
     */
    public function types(): array;

    /**
     * Judge the records of the window, worst first, and list at most the limit of the findings.
     *
     * @param  int|float  $threshold  the value the call asked for, else the default of the threshold, and 0 for a detector that takes none
     */
    public function judge(SQLite3 $connection, Window $window, int|float $threshold, ?string $group, int $limit): Judgement;
}
