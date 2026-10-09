<?php

stream_get_contents(STDIN);

fwrite(STDOUT, json_encode(['k' => 'error', 'code' => 'unavailable', 'reason' => 'heap_limit'])."\n");
