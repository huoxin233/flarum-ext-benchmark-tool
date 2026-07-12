<?php

/*
 * This file is part of huoxin/benchmark-tool.
 *
 * Copyright (c) 2026 huoxin.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace Huoxin\BenchmarkTool;

use Flarum\Extend;
use Huoxin\BenchmarkTool\Command\RunBenchmarkCommand;

return [
    (new Extend\Console())
        ->command(RunBenchmarkCommand::class),
];
