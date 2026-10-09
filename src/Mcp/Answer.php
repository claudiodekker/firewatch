<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
use Closure;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use stdClass;

/**
 * @internal
 */
class Answer
{
    /**
     * The most characters a summary has.
     */
    public const SUMMARY_CHARACTERS = 300;

    /**
     * The most notes, and the most next calls, an answer has.
     */
    public const LISTED = 5;

    /**
     * The summary, cut to fit.
     */
    public readonly string $summary;

    /**
     * The result and the truncated entries once the cells and the budget are bound.
     *
     * @var array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}|null
     */
    protected ?array $bounded = null;

    /**
     * Create a new answer instance.
     *
     * @param  float  $now  the store clock: Unix seconds with microseconds
     * @param  array<string, mixed>  $result  the tool's payload; a list of same-shaped rows prints as a table
     * @param  list<array<string, mixed>>  $blindSpots  each with its id, kind and message, and a condition with its facts as fields
     * @param  list<string>  $notes
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  list<array{tool: string, arguments: array<string, mixed>, why: string}>  $next
     * @param  list<string>|null  $cuttable  the result lists the budget may shorten, the first named first; null for every list, the last first
     * @param  (Closure(array<string, mixed>): array<string, mixed>)|null  $recount  restates what a result says of its own lists once the budget has shortened them
     */
    public function __construct(
        public readonly string $tool,
        public readonly float $now,
        public readonly string $timezone,
        public readonly Window $window,
        string $summary,
        public readonly ?Emptiness $empty,
        public readonly array $result,
        public readonly Coverage $coverage,
        public readonly array $blindSpots = [],
        public readonly array $notes = [],
        public readonly array $truncated = [],
        public readonly array $next = [],
        public readonly ?array $cuttable = null,
        protected ?Closure $recount = null,
    ) {
        if (count($notes) > self::LISTED || count($next) > self::LISTED) {
            throw new InvalidArgumentException('An answer carries at most '.self::LISTED.' notes and '.self::LISTED.' next calls.');
        }

        $this->summary = $this->fit($summary);
    }

    /**
     * Create a new answer that holds no result and states why.
     *
     * @param  list<array<string, mixed>>  $blindSpots
     * @param  list<string>  $notes
     */
    public static function empty(string $tool, float $now, string $timezone, Window $window, Emptiness $empty, Coverage $coverage, array $blindSpots, array $notes = []): self
    {
        return new self(tool: $tool, now: $now, timezone: $timezone, window: $window, summary: $empty->summary(), empty: $empty, result: [], coverage: $coverage, blindSpots: $blindSpots, notes: $notes);
    }

    /**
     * Get the answer in the requested format.
     */
    public function response(AnswerFormat $format): Response|ResponseFactory
    {
        return match ($format) {
            AnswerFormat::MARKDOWN => Response::text($this->toMarkdown()),
            AnswerFormat::JSON => Response::structured($this->toArray()),
        };
    }

    /**
     * Get the envelope.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        [$result, $truncated] = $this->bounded();

        return $this->envelope($result, $truncated);
    }

    /**
     * Get the result and the truncated entries with every cell cut to its cap and the answer cut to its budget.
     *
     * @return array{array<string, mixed>, list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>}
     */
    protected function bounded(): array
    {
        if ($this->bounded === null) {
            [$result, $truncated] = Bounds::capCells($this->result, $this->truncated);

            [$fitted, $truncated] = Bounds::fitAnswer(result: $result, truncated: $truncated, size: $this->size(...), cuttable: $this->cuttable);
            $recounted = Bounds::recountCaps(original: $this->result, fitted: $fitted, truncated: $truncated);

            $this->bounded = [$this->recounted($fitted), $recounted];
        }

        return $this->bounded;
    }

    /**
     * Get a result that states of its own lists what they hold now.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function recounted(array $result): array
    {
        return $this->recount === null ? $result : ($this->recount)($result);
    }

    /**
     * Get the length in characters of the envelope for a result, recounted, and truncated entries.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     */
    protected function size(array $result, array $truncated): int
    {
        $envelope = $this->envelope($this->recounted($result), $truncated);

        return mb_strlen(json_encode($envelope, RecordMapper::JSON_FLAGS));
    }

    /**
     * Get the envelope for a result and truncated entries.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @return array<string, mixed>
     */
    protected function envelope(array $result, array $truncated): array
    {
        return [
            'tool' => $this->tool,
            'now' => $this->now,
            'window' => $this->window->toArray(),
            'summary' => $this->summary,
            'empty' => $this->empty === null ? null : [
                'kind' => $this->empty->kind->value,
                'population' => $this->empty->population,
                'message' => $this->empty->message,
            ],
            'result' => $result === [] ? new stdClass : $result,
            'coverage' => $this->coverage->toArray(),
            'blind_spots' => $this->blindSpots,
            'notes' => $this->notes,
            'truncated' => $truncated,
            'next' => $this->next,
        ];
    }

    /**
     * Get the envelope as markdown.
     */
    public function toMarkdown(): string
    {
        [$result, $truncated] = $this->bounded();

        $lines = [
            "## {$this->tool}",
            $this->summary,
            $this->window->line(),
            __('firewatch::messages.store_clock', [
                'time' => Instant::format($this->now, $this->timezone),
                'epoch' => Instant::epoch($this->now),
            ]),
        ];

        if ($this->empty !== null) {
            $lines[] = $this->empty->message;
        }

        array_push($lines, ...$this->resultLines($result));

        $lines[] = $this->coverage->line($this->timezone);

        foreach ($truncated as $entry) {
            $key = match (true) {
                $entry['reason'] === TruncationReason::CAP->value => 'firewatch::messages.truncated_cap',
                $entry['matched'] === null => 'firewatch::messages.truncated_unknown',
                default => 'firewatch::messages.truncated',
            };

            $lines[] = __($key, [
                ...$entry,
                'characters' => number_format(Bounds::CELL_CHARACTERS),
            ]);
        }

        foreach ($this->notes as $note) {
            $lines[] = '> '.Markdown::cell($note);
        }

        foreach ($this->blindSpots as $blindSpot) {
            $lines[] = __('firewatch::messages.blind_spot', [
                'id' => $blindSpot['id'],
                'message' => $blindSpot['message'],
            ]);
        }

        if ($this->next !== []) {
            $lines[] = __('firewatch::messages.next');

            foreach ($this->next as $call) {
                $lines[] = '- '.$this->call($call).' - '.$call['why'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get the result sections.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    protected function resultLines(array $result): array
    {
        $lines = [];

        foreach ($result as $label => $value) {
            if ($label === 'rows' && $this->isGrid($result)) {
                array_push($lines, '', "### {$label}", '', Markdown::grid($result['columns'], $value), '');

                continue;
            }

            if ($this->isTable($value)) {
                array_push($lines, '', "### {$label}", '', Markdown::table($value), '');

                continue;
            }

            $lines[] = "- **{$label}**: ".Markdown::cell($value);
        }

        return $lines;
    }

    /**
     * Determine if the result holds rows positional to its columns, as the assistant's own SQL returns them.
     *
     * @param  array<string, mixed>  $result
     *
     * @phpstan-assert-if-true array{columns: list<string>, rows: non-empty-list<list<mixed>>} $result
     */
    protected function isGrid(array $result): bool
    {
        $columns = $result['columns'] ?? null;
        $rows = $result['rows'] ?? null;

        if (! is_array($columns) || ! array_is_list($columns) || ! is_array($rows) || $rows === [] || ! array_is_list($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== count($columns)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if a value is a list of rows that all have the same columns.
     *
     * @phpstan-assert-if-true non-empty-list<array<string, mixed>> $value
     */
    protected function isTable(mixed $value): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value) || ! is_array($value[0]) || $value[0] === [] || array_is_list($value[0])) {
            return false;
        }

        $columns = array_keys($value[0]);

        foreach ($value as $row) {
            if (! is_array($row) || array_keys($row) !== $columns) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a drill-down call as it is written to a tool.
     *
     * @param  array{tool: string, arguments: array<string, mixed>, why: string}  $call
     */
    protected function call(array $call): string
    {
        $arguments = array_map(
            fn (string $name, mixed $value) => $name.': '.json_encode($value, RecordMapper::JSON_FLAGS),
            array_keys($call['arguments']),
            $call['arguments'],
        );

        return $call['tool'].'('.implode(', ', $arguments).')';
    }

    /**
     * Cut a summary to the most characters it has, at a word boundary.
     */
    protected function fit(string $summary): string
    {
        $summary = trim((string) preg_replace('/\s+/', ' ', $summary));

        if (mb_strlen($summary) <= self::SUMMARY_CHARACTERS) {
            return $summary;
        }

        $cut = mb_substr($summary, 0, self::SUMMARY_CHARACTERS + 1);
        $boundary = mb_strrpos($cut, ' ');

        return rtrim(mb_substr($cut, 0, $boundary === false ? self::SUMMARY_CHARACTERS : $boundary), ' ,;:.-');
    }
}
