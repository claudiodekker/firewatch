<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
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
     * Get what the detector judges, which decides whether it has anything to judge between a test's executions.
     */
    public function judges(): Subject;

    /**
     * Get the threshold the detector takes, or null when it takes none.
     */
    public function threshold(): ?Threshold;

    /**
     * Judge the records of the window against a threshold, worst first, and list at most the limit of the findings.
     *
     * @param  int|float  $threshold  the value the call asked for, else the default of the threshold; a detector without a threshold ignores it
     */
    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement;
}
