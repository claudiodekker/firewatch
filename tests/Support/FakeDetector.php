<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Mcp\Detectors\Detector;
use ClaudioDekker\Firewatch\Mcp\Detectors\DetectorName;
use ClaudioDekker\Firewatch\Mcp\Detectors\Detectors;
use ClaudioDekker\Firewatch\Mcp\Detectors\Judgement;
use ClaudioDekker\Firewatch\Mcp\Detectors\Threshold;
use ClaudioDekker\Firewatch\Mcp\Window;
use ClaudioDekker\Firewatch\RecordType;
use Closure;
use SQLite3;

// A detector that answers what it is told to, so the framework is tested beyond the detectors that ship.
class FakeDetector implements Detector
{
    /**
     * Create a new fake detector instance.
     *
     * @param  Closure|null  $during  run when the detector judges, to move the clock
     */
    public function __construct(
        protected DetectorName $name,
        protected int $examined = 0,
        protected int $total = 0,
        protected ?Closure $during = null,
        protected ?Threshold $threshold = null,
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
        return $this->threshold;
    }

    /**
     * @return list<RecordType>
     */
    public function types(): array
    {
        return [RecordType::REQUEST];
    }

    public function judge(SQLite3 $connection, Window $window, int|float|null $threshold, ?string $group, int $limit): Judgement
    {
        if ($this->during !== null) {
            ($this->during)();
        }

        $findings = $this->total === 0 ? [] : [[
            'group' => str_repeat('a', 32),
            'name' => $this->name->value.' finding',
            'count' => $this->total,
        ]];

        return Judgement::of($this->name, $this->threshold?->describe($threshold), $this->examined, $this->total, $findings);
    }
}
