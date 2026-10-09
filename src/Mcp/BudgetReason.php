<?php

namespace ClaudioDekker\Firewatch\Mcp;

/**
 * @internal
 */
enum BudgetReason: string
{
    case NO_BUDGET_CONFIGURED = 'no_budget_configured';
    case NO_MATCHING_BUDGET = 'no_matching_budget';
    case NO_MEASUREMENT = 'no_measurement';
    case NOT_RUN = 'not_run';
}
