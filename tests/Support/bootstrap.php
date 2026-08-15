<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

/**
 * Loads the scheduler test doubles.
 *
 * These live outside the Composer autoload map on purpose: they are a stand-in for a scheduler the
 * package will ship for real, and nothing under src/ may ever be able to reach them.
 */
declare(strict_types=1);

require_once __DIR__ . '/FakePoller.php';
require_once __DIR__ . '/FakeCoroutine.php';
require_once __DIR__ . '/FakeScheduler.php';
