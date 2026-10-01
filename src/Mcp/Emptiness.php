<?php

namespace ClaudioDekker\Firewatch\Mcp;

use ClaudioDekker\Firewatch\ModeResolver;
use ClaudioDekker\Firewatch\Store\Schema;
use ClaudioDekker\Firewatch\Store\StoreState;
use ClaudioDekker\Firewatch\Store\StoreUnusable;

/**
 * @internal
 */
class Emptiness
{
    /**
     * Create a new emptiness instance.
     *
     * @param  int|null  $population  the records the answer was drawn from: none for a missing or unusable store, the whole store for an empty window, the records before the filters for no match
     */
    public function __construct(
        public readonly EmptyKind $kind,
        public readonly ?int $population,
        public readonly string $message,
    ) {
        //
    }

    /**
     * Get an empty answer for a store no application process has written.
     */
    public static function noStore(string $path): self
    {
        return new self(EmptyKind::NO_STORE, null, __('firewatch::messages.no_store', ['path' => $path]));
    }

    /**
     * Get an empty answer for a store that can't be read: no store when it is absent, otherwise an unusable store with the line that says why.
     */
    public static function of(StoreUnusable $unusable, string $path): self
    {
        if ($unusable->state === StoreState::ABSENT) {
            return self::noStore($path);
        }

        $reason = Coverage::reason($unusable);

        $message = match ($unusable->state) {
            StoreState::CORRUPT => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.corrupt')]),
            StoreState::BUSY => __('firewatch::messages.store_unusable.unreadable', ['path' => $path, 'cause' => __('firewatch::messages.store_causes.busy')]),
            default => __('firewatch::messages.store_unusable.'.$reason, ['path' => $path, 'found' => $unusable->found, 'expected' => Schema::VERSION, 'version' => $unusable->found, 'minimum' => ModeResolver::MINIMUM_SQLITE_VERSION]),
        };

        return new self(EmptyKind::STORE_UNUSABLE, null, $message);
    }

    /**
     * Get an empty answer for a store that holds no records.
     */
    public static function storeEmpty(string $path): self
    {
        return new self(EmptyKind::STORE_EMPTY, 0, __('firewatch::messages.store_empty', ['path' => $path]));
    }

    /**
     * Get an empty answer for a window that holds none of the store's records.
     */
    public static function windowEmpty(int $records): self
    {
        return new self(EmptyKind::WINDOW_EMPTY, $records, __('firewatch::messages.window_empty', ['population' => $records]));
    }

    /**
     * Get an empty answer for filters that matched none of the records they were applied to.
     *
     * @param  list<string>  $filters
     */
    public static function noMatch(int $records, array $filters): self
    {
        return new self(EmptyKind::NO_MATCH, $records, __('firewatch::messages.no_match', ['population' => $records, 'filters' => implode(', ', $filters)]));
    }

    /**
     * Get the fixed summary line for this kind, which never says there are no problems.
     */
    public function summary(): string
    {
        return __('firewatch::messages.empty_summary.'.$this->kind->value);
    }
}
