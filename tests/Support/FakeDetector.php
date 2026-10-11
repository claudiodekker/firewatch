<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Mcp\Detectors\Detector;
use ClaudioDekker\Firewatch\Mcp\Detectors\DetectorName;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Detectors\Finding;
use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Detectors\Threshold;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\Markers;
use Closure;
use SQLite3;

class FakeDetector implements Detector
{
    /**
     * Create a new fake detector instance.
     *
     * @param  array<string, int|array<string, mixed>>  $saw
     */
    public function __construct(
        protected DetectorName $name,
        protected int $examined = 0,
        protected int $total = 0,
        protected ?Closure $during = null,
        protected array $saw = [],
    ) {
        //
    }

    /**
     * Make the detectors that ship exactly these, in this order.
     */
    public static function ship(self ...$detectors): void
    {
        $ids = [];

        foreach ($detectors as $detector) {
            $ids[] = $id = 'fake.'.$detector->name()->value;

            app()->instance($id, $detector);
        }

        app()->bind(Detectors::class, fn () => new Detectors(app(), $ids));
    }

    public function name(): DetectorName
    {
        return $this->name;
    }

    public function threshold(): ?Threshold
    {
        return null;
    }

    /**
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::REQUEST];
    }

    public function judge(SQLite3 $connection, Markers $meta, Window $window, int|float $threshold, ?string $group, int $limit): Judgement
    {
        if ($this->during !== null) {
            ($this->during)();
        }

        $findings = $this->total === 0 ? [] : [new Finding(
            group: str_repeat('a', 32),
            name: $this->name->value.' finding',
            count: $this->total,
            firstSeenAt: null,
            lastSeenAt: null,
            latestExecutionId: null,
            signedInActors: 0,
            withoutActor: 0,
            evidence: [],
        )];

        return Judgement::of($this->name, null, examined: $this->examined, total: $this->total, findings: $findings, saw: $this->saw);
    }
}
