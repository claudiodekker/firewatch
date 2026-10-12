<?php

namespace ClaudioDekker\Firewatch\Store;

use Closure;

/**
 * @internal
 */
class FileLock
{
    /**
     * The interval a held lock is polled at, in milliseconds.
     */
    protected const POLL_MILLISECONDS = 5;

    /**
     * The callback that waits the given microseconds between two attempts, or null to really wait.
     *
     * @var (Closure(int): void)|null
     */
    protected static ?Closure $sleepUsing = null;

    /**
     * Set the callback that waits between two attempts.
     *
     * @param  (Closure(int): void)|null  $callback
     */
    public static function sleepUsing(?Closure $callback): void
    {
        static::$sleepUsing = $callback;
    }

    /**
     * Take the exclusive lock on an open file, waiting up to the budget, and get what is left of it in milliseconds.
     *
     * @param  resource  $handle
     *
     * @throws StoreFailure
     */
    public static function acquire(mixed $handle, string $path, int $budgetMilliseconds): int
    {
        // The budget counts down by what was slept, so a frozen clock in the host can't stretch it.
        $remainingMilliseconds = $budgetMilliseconds;

        while (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (! $wouldBlock) {
                throw new StoreFailure(FailureKind::IO, "Firewatch could not lock [{$path}].");
            }

            if ($remainingMilliseconds <= 0) {
                throw new StoreFailure(FailureKind::BUSY, "Firewatch could not lock [{$path}] within {$budgetMilliseconds} ms.");
            }

            $pollMilliseconds = min($remainingMilliseconds, static::POLL_MILLISECONDS);

            static::sleep($pollMilliseconds * Microseconds::PER_MILLISECOND);

            $remainingMilliseconds -= $pollMilliseconds;
        }

        return $remainingMilliseconds;
    }

    /**
     * Wait the given microseconds.
     */
    protected static function sleep(int $microseconds): void
    {
        // An application's tests fake Laravel's Sleep, which would spend the budget without waiting.
        $sleep = static::$sleepUsing ?? usleep(...);

        $sleep($microseconds);
    }
}
