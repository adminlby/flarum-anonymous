<?php

require __DIR__.'/../vendor/autoload.php';

if (! getenv('FLARUM_TEST_TMP_DIR')) {
    putenv('FLARUM_TEST_TMP_DIR='.__DIR__.'/tmp');
}
