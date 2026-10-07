<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\Window;
use Illuminate\Contracts\Container\Container;
use SQLite3;

/**
 * @internal
 */
class Detectors
{
    /**
     * Create a new detectors instance.
     *
     * @param  list<string>  $shipped  the container ids of the detectors that ship, in the order of the catalogue
     */
    public function __construct(
        protected Container $container,
        protected array $shipped = [NPlusOne::class, DatabaseBound::class, FailingRoutes::class, FailingJobs::class, QueueLatency::class, Memory::class],
    ) {
        //
    }

    /**
     * Get the detectors that ship, in the order of the catalogue.
     *
     * @return list<Detector>
     */
    public function all(): array
    {
        return array_map(fn (string $detector) => $this->container->make($detector), $this->shipped);
    }

    /**
     * Get the detector that judges a shape, or null for a shape that does not ship.
     */
    public function named(string $shape): ?Detector
    {
        foreach ($this->all() as $detector) {
            if ($detector->name()->value === $shape) {
                return $detector;
            }
        }

        return null;
    }

    /**
     * Get the names of the shapes that ship.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (Detector $detector) => $detector->name()->value, $this->all());
    }

    /**
     * Run every detector at its default threshold, one finding deep, in the order of the catalogue, and not start one once the deadline has passed.
     *
     * @return list<Judgement>
     */
    public function count(SQLite3 $connection, Window $window, Deadline $deadline): array
    {
        return array_map(fn (Detector $detector) => $deadline->passed()
            ? Judgement::notEvaluated($detector->name(), $detector->threshold()?->describe(), Reason::DEADLINE)
            : $detector->judge($connection, $window, threshold: null, group: null, limit: 1), $this->all());
    }
}
