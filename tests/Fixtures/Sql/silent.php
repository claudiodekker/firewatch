<?php

stream_get_contents(STDIN);

fwrite(STDERR, "stand-in stderr\n");
