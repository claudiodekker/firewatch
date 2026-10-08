<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum ComparisonReason: string
{
    case SAMPLE_TOO_SMALL = 'sample_too_small';
    case EMPTY_SIDE = 'empty_side';
    case UNEQUAL_SPANS = 'unequal_spans';
    case OUTSIDE_COVERAGE = 'outside_coverage';
}
