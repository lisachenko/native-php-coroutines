<?php

/**
 * Native PHP coroutines
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace Lisachenko\NativePhpCoroutines\Exception;

/**
 * Marker for every exception this runtime raises, so an application can catch the whole family.
 *
 * Each concrete exception also extends the SPL class that best describes it, so code that catches
 * \RuntimeException or \InvalidArgumentException keeps working.
 */
interface CoroutineException extends \Throwable {}
