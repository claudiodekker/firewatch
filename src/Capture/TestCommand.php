<?php

namespace ClaudioDekker\Firewatch\Capture;

/**
 * @internal
 */
class TestCommand
{
    /**
     * How many commands are running, counting the outer one and those it started.
     */
    public int $depth = 1;
}
