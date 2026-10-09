<?php

stream_get_contents(STDIN);

fwrite(STDOUT, json_encode(['k' => 'columns', 'columns' => ['n'], 'reads' => []])."\n");

sleep(30);
