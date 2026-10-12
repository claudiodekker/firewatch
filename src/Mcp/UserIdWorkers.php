<?php

namespace ClaudioDekker\Firewatch\Mcp;

use SQLite3;

/**
 * @internal
 */
class UserIdWorkers
{
    /**
     * The most workers that are counted for one user id.
     */
    protected const COUNTED = 100;

    /**
     * Get the note that a user id read without a worker is several actors, in the snapshot of the connection, or null when the records hold the id under one worker at most.
     *
     * No worker counts as one, so an id recorded outside a parallel run and by one of its workers is two actors.
     */
    public static function note(SQLite3 $connection, string $userId): ?string
    {
        $held = Stored::rows($connection, 'SELECT DISTINCT worker FROM records WHERE user_id = :user_id LIMIT :limit', [
            'user_id' => $userId,
            'limit' => Rows::fetch(self::COUNTED),
        ]);

        $workers = count($held);

        if ($workers < 2) {
            return null;
        }

        return __($workers > self::COUNTED ? 'firewatch::messages.user_id_workers_more_note' : 'firewatch::messages.user_id_workers_note', [
            'user_id' => $userId,
            'count' => min($workers, self::COUNTED),
        ]);
    }
}
