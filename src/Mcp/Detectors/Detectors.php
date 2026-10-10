<?php

namespace ClaudioDekker\Firewatch\Mcp\Detectors;

use ClaudioDekker\Firewatch\Mcp\History;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\Store\Markers;
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
        protected array $shipped = [NPlusOne::class, DatabaseBound::class, FailingRoutes::class, FailingJobs::class, QueueLatency::class, FailingTasks::class, ExceptionClusters::class, ErrorLogs::class, FailingHttp::class, Cache::class, Memory::class],
    ) {
        //
    }

    /**
     * Get the detectors that ship.
     *
     * @return list<Detector>
     */
    public function all(): array
    {
        return array_map(fn (string $detector) => $this->container->make($detector), $this->shipped);
    }

    /**
     * Get the detector that judges a shape.
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
     * Run every detector until the deadline has passed.
     *
     * @return list<Judgement>
     */
    public function count(SQLite3 $connection, Window $window, Deadline $deadline): array
    {
        return array_map(fn (Detector $detector) => $deadline->passed()
            ? Judgement::notEvaluated($detector->name(), $detector->threshold()?->describe(), Reason::DEADLINE)
            : $this->judge($connection, $detector, $window, threshold: null, group: null, limit: 1), $this->all());
    }

    /**
     * Judge the records of the window with a detector, and say so when history removed what it would have judged.
     */
    public function judge(SQLite3 $connection, Detector $detector, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        $resolved = $threshold ?? $detector->threshold()?->default ?? 0;
        $judgement = $detector->judge($connection, $window, $resolved, $group, $limit);

        return $judgement->afterRemoval(History::removedThrough(Markers::read($connection), $detector->types()), $window->since());
    }
}
