<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\Capture\RecordMapper;
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
     * Create a new answer instance.
     *
     * @param  float  $now  the store clock: Unix seconds with microseconds
     * @param  array<string, mixed>  $result  the tool's payload; a list of same-shaped rows prints as a table
     * @param  list<array{id: string, kind: string, message: string}>  $blindSpots
     * @param  list<string>  $notes
     * @param  list<array{section: string, shown: int, matched: int|null, reason: string, how: string}>  $truncated
     * @param  list<array{tool: string, arguments: array<string, mixed>, why: string}>  $next
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
    ) {
        if (count($notes) > self::LISTED || count($next) > self::LISTED) {
            throw new InvalidArgumentException('An answer carries at most '.self::LISTED.' notes and '.self::LISTED.' next calls.');
        }

        $this->summary = $this->fit($summary);
    }

    /**
     * Get the answer in the requested format: one text block of markdown, or the envelope as structured content beside the same JSON as one text block.
     */
    public function response(AnswerFormat $format): Response|ResponseFactory
    {
        return match ($format) {
            AnswerFormat::MARKDOWN => Response::text($this->toMarkdown()),
            AnswerFormat::JSON => Response::structured($this->toArray()),
        };
    }

    /**
     * Get the envelope, every key in its fixed order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
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
            'result' => $this->result === [] ? new stdClass : $this->result,
            'coverage' => $this->coverage->toArray(),
            'blind_spots' => $this->blindSpots,
            'notes' => $this->notes,
            'truncated' => $this->truncated,
            'next' => $this->next,
        ];
    }

    /**
     * Get the envelope as markdown, in the fixed layout: each line only when it applies.
     */
    public function toMarkdown(): string
    {
        $lines = [
            "## {$this->tool}",
            $this->summary,
            $this->window->line(),
            __('firewatch::messages.store_clock', ['time' => Instant::format($this->now, $this->timezone), 'epoch' => Instant::epoch($this->now)]),
        ];

        if ($this->empty !== null) {
            $lines[] = $this->empty->message;
        }

        array_push($lines, ...$this->resultLines());

        $lines[] = $this->coverage->line($this->timezone);

        foreach ($this->truncated as $entry) {
            $lines[] = __($entry['matched'] === null ? 'firewatch::messages.truncated_unknown' : 'firewatch::messages.truncated', $entry);
        }

        foreach ($this->notes as $note) {
            $lines[] = '> '.Markdown::cell($note);
        }

        foreach ($this->blindSpots as $blindSpot) {
            $lines[] = __('firewatch::messages.blind_spot', ['id' => $blindSpot['id'], 'message' => $blindSpot['message']]);
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
     * Get the result sections: a table for a list of same-shaped rows, a labelled line for anything else.
     *
     * @return list<string>
     */
    protected function resultLines(): array
    {
        $lines = [];

        foreach ($this->result as $label => $value) {
            if ($this->isTable($value)) {
                array_push($lines, '', "### {$label}", '', Markdown::table($value), '');

                continue;
            }

            $lines[] = "- **{$label}**: ".Markdown::cell($value);
        }

        return $lines;
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
     * Cut a summary to the most characters it has, at a word boundary, never mid-word.
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
