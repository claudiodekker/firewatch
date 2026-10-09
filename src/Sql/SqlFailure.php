<?php

namespace ClaudioDekker\Firewatch\Sql;

use ClaudioDekker\Firewatch\Mcp\ErrorCode;
use ClaudioDekker\Firewatch\Sql\Child\Denied;
use ClaudioDekker\Firewatch\Sql\Child\Unavailable;
use RuntimeException;

/**
 * @internal
 */
class SqlFailure extends RuntimeException
{
    /**
     * Create a new SQL failure instance.
     *
     * @param  string|null  $name  the function, table or action a denial names
     * @param  string|null  $detail  SQLite's message, the child's stderr, or what broke
     */
    public function __construct(
        public readonly ErrorCode $error,
        public readonly ?Denied $denied = null,
        public readonly ?string $name = null,
        public readonly ?Unavailable $unavailable = null,
        public readonly ?string $detail = null,
    ) {
        parent::__construct("The SQL tool ended with {$error->value}: {$detail}");
    }

    /**
     * Get the failure of a statement the policy refuses.
     */
    public static function notAllowed(Denied $denied, ?string $name = null): self
    {
        return new self(ErrorCode::NOT_ALLOWED, denied: $denied, name: $name);
    }

    /**
     * Get the failure of a statement SQLite could not compile or run, with SQLite's message.
     */
    public static function invalid(string $message): self
    {
        return new self(ErrorCode::INVALID_SQL, detail: $message);
    }

    /**
     * Get the failure of a call whose isolation could not be established.
     */
    public static function unavailable(Unavailable $reason): self
    {
        return new self(ErrorCode::UNAVAILABLE, unavailable: $reason);
    }

    /**
     * Get the failure of a child that ended without a complete result, with the start of its stderr.
     */
    public static function aborted(string $stderr): self
    {
        return new self(ErrorCode::ABORTED, detail: $stderr);
    }

    /**
     * Get the failure of a statement whose first row is over the answer budget.
     */
    public static function rowTooLarge(): self
    {
        return new self(ErrorCode::ROW_TOO_LARGE);
    }

    /**
     * Get the failure of the SQL pipeline itself, saying what broke.
     */
    public static function failed(string $detail): self
    {
        return new self(ErrorCode::FAILED, detail: $detail);
    }
}
