<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

return \Glimpse\Application::create(\Glimpse\Settings::load(__DIR__ . '/config'));
