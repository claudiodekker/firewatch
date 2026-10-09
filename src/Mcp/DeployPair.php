<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\RecordType;
use SQLite3;

/**
 * @internal
 *
 * @phpstan-import-type Side from Comparison
 */
class DeployPair implements Boundary
{
    /**
     * A valid call by a deploy pair, as a refusal shows it.
     */
    public const EXAMPLE = 'compare(type: "request", deploy_before: "<deploy>", deploy_after: "<another deploy>")';

    /**
     * What the after deploy must be, as the refusal of an equal one says it.
     */
    protected const DIFFERENT = 'a deploy other than deploy_before';

    /**
     * Create a new deploy pair instance.
     */
    protected function __construct(
        public readonly string $before,
        public readonly string $after,
    ) {
        //
    }

    /**
     * Read the deploy pair a call passes: two different, exact deploy strings, or a refusal.
     */
    public static function read(mixed $before, mixed $after): self
    {
        if ($after === null) {
            throw Refusal::missing(argument: 'deploy_after', accepted: 'an exact deploy string, with deploy_before', example: self::EXAMPLE);
        }

        if ($before === null) {
            throw Refusal::missing(argument: 'deploy_before', accepted: 'an exact deploy string, with deploy_after', example: self::EXAMPLE);
        }

        $pair = new self(self::deploy('deploy_before', $before), self::deploy('deploy_after', $after));

        if ($pair->before !== $pair->after) {
            return $pair;
        }

        $shown = json_encode($after, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: 'deploy_after', expected: self::DIFFERENT, value: $shown, accepted: self::DIFFERENT, example: self::EXAMPLE);
    }

    /**
     * Read one deploy of the pair: an exact deploy string, which is never empty, since an empty one marks a record with no deploy.
     */
    protected static function deploy(string $argument, mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $shown = json_encode($value, JSON_THROW_ON_ERROR);

        throw Refusal::invalid(argument: $argument, expected: 'an exact deploy string', value: $shown, accepted: 'an exact deploy string, which is not empty', example: self::EXAMPLE);
    }

    /**
     * Get every record of each deploy in the whole window.
     *
     * @return array{Side, Side}
     */
    public function sides(Window $window, ?float $coverageStart): array
    {
        return [
            Comparison::side(since: $window->since(), until: $window->until(), deploy: $this->before, coverageStart: $coverageStart, countsEarlier: false),
            Comparison::side(since: $window->since(), until: $window->until(), deploy: $this->after, coverageStart: $coverageStart, countsEarlier: false),
        ];
    }

    /**
     * Refuse no window: a pair divides any window by deploy.
     */
    public function refuseOutside(Window $window): void
    {
        //
    }

    /**
     * Determine if the boundary is an instant, which a deploy pair is not.
     */
    public function isInstant(): bool
    {
        return false;
    }

    /**
     * Get no straddling count: a record belongs to its deploy whenever it ran.
     *
     * @param  Side  $before
     */
    public function straddling(SQLite3 $connection, RecordType $type, array $before, ?string $group): ?int
    {
        return null;
    }

    /**
     * Get the pair as an empty answer names it among the filters of the call.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return ["deploy_before: {$this->before}", "deploy_after: {$this->after}"];
    }

    /**
     * Get the summary of a comparison that ran, from one deploy to the other.
     */
    public function summary(Comparison $comparison): string
    {
        return trans_choice('firewatch::messages.compare_pair_summary', $comparison->matched(), [
            'groups' => $comparison->matched(),
            'type' => $comparison->type->value,
            'by' => $comparison->by->value,
            'changes' => $comparison->counts(),
            'before' => $this->before,
            'after' => $this->after,
        ]);
    }

    /**
     * Get the summary of a comparison whose deploy holds no record of the type in the window, naming it.
     */
    public function emptySideSummary(Comparison $comparison, string $side): string
    {
        return __('firewatch::messages.compare_empty_deploy_summary', [
            'deploy' => $side === 'before' ? $this->before : $this->after,
            'side' => $side,
            'type' => $comparison->type->value,
        ]);
    }

    /**
     * Get the note of a comparison that ran on nothing, which deploys the window holds may fix.
     */
    public function notEvaluatedNote(): string
    {
        return __('firewatch::messages.compare_pair_not_evaluated_note');
    }

    /**
     * Get the notes of the pair on a comparison: what it cannot separate.
     *
     * @return list<string>
     */
    public function notes(Comparison $comparison, bool $sinceOmitted, ?float $oldest): array
    {
        return $this->fixedNotes();
    }

    /**
     * Get the note every answer of a deploy pair carries: what it cannot separate.
     *
     * @return list<string>
     */
    public function fixedNotes(): array
    {
        return [__('firewatch::messages.compare_deploy_pair_note')];
    }

    /**
     * Get a call that lists the records of the first group under each deploy that holds some.
     *
     * @return list<array{arguments: array<string, string>, why: string}>
     */
    public function occurrences(Comparison $comparison): array
    {
        return array_map(fn (string $deploy) => [
            'arguments' => ['deploy' => $deploy],
            'why' => __('firewatch::messages.compare_next_occurrences_deploy', ['deploy' => $deploy]),
        ], $comparison->firstDeploys());
    }
}
