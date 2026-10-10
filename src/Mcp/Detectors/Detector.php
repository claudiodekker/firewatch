<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\RecordType;

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
}
