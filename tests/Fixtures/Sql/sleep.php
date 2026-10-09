<?php

stream_get_contents(STDIN);

fwrite(STDOUT, json_encode(['k' => 'columns', 'columns' => ['n']])."\n");

sleep(30);
