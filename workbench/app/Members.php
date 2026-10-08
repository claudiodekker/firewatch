<?php

namespace Workbench\App;

use Illuminate\Auth\GenericUser;

class Members
{
    /**
     * The members who can sign in, by id.
     */
    protected const MEMBERS = [
        '7' => ['name' => 'Taylor Otwell', 'email' => 'taylor@example.com'],
        '8' => ['name' => 'Taylor Swift', 'email' => 'swift@example.com'],
        '9' => ['name' => 'Nuno Maduro', 'email' => 'nuno@example.com'],
    ];

    /**
     * Get the member with the id, or null for none.
     */
    public static function find(string $id): ?GenericUser
    {
        return isset(self::MEMBERS[$id]) ? new GenericUser(['id' => $id, ...self::MEMBERS[$id]]) : null;
    }
}
