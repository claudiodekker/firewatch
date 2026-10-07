<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
interface Ungrouped extends Detector
{
    /**
     * Get the call that lists the records of a finding.
     *
     * @param  array<string, mixed>  $finding  one this detector returned
     * @return array{tool: string, arguments: array<string, mixed>, why: string}
     */
    public function occurrencesOf(array $finding): array;
}
