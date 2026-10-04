<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

/**
 * @internal
 */
enum Verdict: string
{
    case FINDINGS = 'findings';
    case CLEAN = 'clean';
    case NOT_EVALUATED = 'not_evaluated';
}
