<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Commands\CopilotAuthCommand;

try {
    $exitCode = (new CopilotAuthCommand())->run();
    exit($exitCode);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
