<?php

namespace ClaudioDekker\Firewatch\Console\Commands;

use ClaudioDekker\Firewatch\Actions\ClearStore;
use ClaudioDekker\Firewatch\Configuration\Configuration;
use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\RecordType;
use ClaudioDekker\Firewatch\Store\FailureKind;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreFailure;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use SQLite3Exception;

/**
 * @api
 */
class ClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firewatch:clear {--type= : Clear only the records of one type} {--drop : Rebuild the store in place, discarding everything including diagnostics} {--force : Skip the confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the records in the Firewatch store';

    /**
     * Execute the console command.
     */
    public function handle(ClearStore $clear, Configuration $configuration): int
    {
        $type = $this->type();

        if ($type === false) {
            return self::FAILURE;
        }

        if ($this->option('drop') && $type !== null) {
            $this->error(__('firewatch::messages.clear.drop_with_type'));

            return self::FAILURE;
        }

        $unusable = $clear->unusable();

        // A dropped store may be damaged or of another schema version, which is what a drop is for.
        $rebuildable = $this->option('drop') && $unusable?->state->isRebuildable() === true;

        if ($unusable !== null && ! $rebuildable) {
            return $this->refuse($unusable, $configuration->database);
        }

        if (! $this->confirmed($type, $configuration->database)) {
            return self::FAILURE;
        }

        if ($this->option('drop')) {
            return $this->drop($clear, $unusable?->state, $configuration->database);
        }

        $result = $this->unlessBusy(fn () => $clear->clear($type));

        if ($result === null) {
            return self::FAILURE;
        }

        $sizes = ['before' => Number::fileSize($result['before'], precision: 1), 'after' => Number::fileSize($result['after'], precision: 1)];

        $this->line($type === null
            ? __('firewatch::messages.clear.cleared', ['records' => number_format($result['records']), 'users' => number_format($result['users']), ...$sizes])
            : __('firewatch::messages.clear.cleared_type', ['records' => number_format($result['records']), 'type' => $type->value, ...$sizes]));

        if ($result['truncated']) {
            $this->line(__('firewatch::messages.clear.log_in_use'));
        }

        return self::SUCCESS;
    }

    /**
     * Rebuild the store and say what happened.
     */
    protected function drop(ClearStore $clear, ?StoreState $state, string $path): int
    {
        $result = $this->unlessBusy(fn () => $clear->drop($state));

        if ($result === null) {
            return self::FAILURE;
        }

        $this->line($result['damaged']
            ? __('firewatch::messages.clear.replaced_damaged', ['file' => basename($path).'.corrupt'])
            : __('firewatch::messages.clear.rebuilt', ['path' => $path, 'before' => Number::fileSize($result['before'], precision: 1), 'after' => Number::fileSize($result['after'], precision: 1)]));

        if ($result['truncated']) {
            $this->line(__('firewatch::messages.clear.log_in_use'));
        }

        return self::SUCCESS;
    }

    /**
     * Run a clear or a drop, and say so and get null when the store stays busy for the whole wait.
     *
     * @template TResult of array
     *
     * @param  Closure(): TResult  $action
     * @return TResult|null
     */
    protected function unlessBusy(Closure $action): ?array
    {
        try {
            return $action();
        } catch (SQLite3Exception|StoreFailure $exception) {
            if (FailureKind::of($exception) !== FailureKind::BUSY) {
                throw $exception;
            }

            $this->error(__('firewatch::messages.clear.busy'));

            return null;
        }
    }

    /**
     * Read the type to clear: null for none, false after refusing one that is not among the twelve record types.
     */
    protected function type(): RecordType|false|null
    {
        $option = $this->option('type');

        if ($option === null) {
            return null;
        }

        $type = is_string($option) ? RecordType::tryFrom($option) : null;

        if ($type !== null && $type !== RecordType::USER) {
            return $type;
        }

        $this->error(__('firewatch::messages.clear.unknown_type', [
            'type' => is_string($option) ? $option : '',
            'types' => implode(', ', array_map(fn (RecordType $type) => $type->value, RecordType::events())),
        ]));

        return false;
    }

    /**
     * Ask for the confirmation, which is no by default, unless it is forced; without a way to ask, it is not given.
     */
    protected function confirmed(?RecordType $type, string $path): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error(__('firewatch::messages.clear.not_forced'));

            return false;
        }

        $question = match (true) {
            (bool) $this->option('drop') => __('firewatch::messages.clear.confirm_drop', ['path' => $path]),
            $type === null => __('firewatch::messages.clear.confirm', ['path' => $path]),
            default => __('firewatch::messages.clear.confirm_type', ['path' => $path, 'type' => $type->value]),
        };

        if ($this->confirm($question, false)) {
            return true;
        }

        $this->error(__('firewatch::messages.clear.declined'));

        return false;
    }

    /**
     * Say why the store can't be cleared, and get the exit code: a store that does not exist has nothing to clear, which is not a failure.
     */
    protected function refuse(StoreUnusable $unusable, string $path): int
    {
        if ($unusable->state === StoreState::ABSENT) {
            $this->line(__('firewatch::messages.clear.nothing'));

            return self::SUCCESS;
        }

        $this->error(match ($unusable->state) {
            StoreState::SCHEMA_MISMATCH => __('firewatch::messages.clear.schema', ['found' => (string) $unusable->found, 'expected' => Schema::VERSION]),
            StoreState::CORRUPT => __('firewatch::messages.clear.damaged'),
            StoreState::FOREIGN => __('firewatch::messages.clear.foreign', ['path' => $path]),
            StoreState::UNAVAILABLE => __('firewatch::messages.clear.sqlite', ['version' => (string) $unusable->found, 'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION]),
            StoreState::BUSY => __('firewatch::messages.clear.busy'),
        });

        return self::FAILURE;
    }
}
