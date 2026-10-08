<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum Reason: string
{
    case NO_RECORDS = 'no_records';
    case SAMPLE_TOO_SMALL = 'sample_too_small';
    case EMPTY_SIDE = 'empty_side';
    case UNEQUAL_SPANS = 'unequal_spans';
    case OUTSIDE_COVERAGE = 'outside_coverage';
    case PREREQUISITE_MISSING = 'prerequisite_missing';
    case STORE_UNAVAILABLE = 'store_unavailable';
    case DEADLINE = 'deadline';
}
