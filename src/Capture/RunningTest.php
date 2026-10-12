<?php

namespace ClaudioDekker\Firewatch\Capture;

use NunoMaduro\Collision\Contracts\Adapters\Phpunit\HasPrintableTestCaseName;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
readonly class RunningTest
{
    /**
     * The variable a parallel run gives each of its workers a token in.
     */
    protected const WORKER_TOKEN = 'TEST_TOKEN';

    /**
     * Create a new running test instance.
     */
    public function __construct(
        public ?string $test,
        public ?string $worker,
    ) {
        //
    }

    /**
     * Find the test that is running and the worker that runs it.
     */
    public static function find(): self
    {
        return new self(static::test(), static::worker());
    }

    /**
     * Get the nearest test case on the call stack and its test, as the runner prints them, or null when no test is running.
     */
    protected static function test(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $case = $frame['object'] ?? null;

            if ($case instanceof TestCase) {
                return static::name($case);
            }
        }

        return null;
    }

    /**
     * Get the name of a test case and its test, with its data set.
     */
    protected static function name(TestCase $case): string
    {
        // PHPUnit's own name of a Pest test is a generated method, so Pest's description is read where the case has one.
        if ($case instanceof HasPrintableTestCaseName) {
            return $case::getPrintableTestCaseName().'::'.$case->getPrintableTestCaseMethodName();
        }

        return $case::class.'::'.$case->nameWithDataSet();
    }

    /**
     * Get the token of the worker of a parallel run, or null in a serial run.
     */
    protected static function worker(): ?string
    {
        $token = $_SERVER[static::WORKER_TOKEN] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }
}
