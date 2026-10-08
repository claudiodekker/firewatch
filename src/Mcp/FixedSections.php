<?php

namespace ClaudioDekker\Firewatch\Mcp;

use SQLite3;

/**
 * @internal
 */
class FixedSections
{
    /**
     * The decimals of a share in an answer.
     */
    protected const PERCENT_DECIMALS = 1;

    /**
     * Create a new fixed sections instance.
     *
     * @param  array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}  $errorRate
     */
    protected function __construct(
        public readonly array $errorRate,
    ) {
        //
    }

    /**
     * Read the sections of the window, in the fixed order.
     */
    public static function read(SQLite3 $connection, Window $window): self
    {
        $errorRate = self::errorRate($connection, $window);

        return new self(errorRate: $errorRate);
    }

    /**
     * Get the sections as the keys of a result, in the fixed order.
     *
     * @return array<string, mixed>
     */
    public function result(): array
    {
        return [
            'error_rate' => $this->errorRate,
        ];
    }

    /**
     * Read the server errors and client errors among the requests of the window, with their shares of the requests that have a status.
     *
     * @return array{requests: int, with_status: int, server_errors: int, server_error_pct: float|null, client_errors: int, client_error_pct: float|null}
     */
    protected static function errorRate(SQLite3 $connection, Window $window): array
    {
        $status = Stored::number('status_code');
        $serverError = Failure::serverError("({$status})");
        $clientError = Failure::clientError("({$status})");

        /** @var array{requests: int, with_status: int, server_errors: int, client_errors: int} $counts */
        $counts = Stored::rows($connection, "SELECT count(*) AS requests, count({$status}) AS with_status,
            count(*) FILTER (WHERE {$serverError}) AS server_errors,
            count(*) FILTER (WHERE {$clientError}) AS client_errors
            FROM requests WHERE {$window->condition()}", window: $window)[0];

        return [
            'requests' => $counts['requests'],
            'with_status' => $counts['with_status'],
            'server_errors' => $counts['server_errors'],
            'server_error_pct' => self::share($counts['server_errors'], $counts['with_status']),
            'client_errors' => $counts['client_errors'],
            'client_error_pct' => self::share($counts['client_errors'], $counts['with_status']),
        ];
    }

    /**
     * Get a part as a percentage of a whole, or null for a whole of nothing.
     */
    protected static function share(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round(Ranking::PERCENT * $part / $whole, self::PERCENT_DECIMALS);
    }
}
