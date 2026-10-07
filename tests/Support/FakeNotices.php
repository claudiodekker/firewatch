<?php

namespace ClaudioDekker\Firewatch\Tests\Support;

use ClaudioDekker\Firewatch\Notices;

class FakeNotices implements Notices
{
    /**
     * The notices written, in order.
     *
     * @var list<string>
     */
    protected array $written = [];

    /**
     * Keep a notice instead of writing it.
     */
    public function write(string $notice): void
    {
        $this->written[] = $notice;
    }

    /**
     * Get the notices written, in order.
     *
     * @return list<string>
     */
    public function written(): array
    {
        return $this->written;
    }
}
