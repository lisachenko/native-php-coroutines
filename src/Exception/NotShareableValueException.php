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
 * A value was asked to cross a worker boundary and cannot.
 *
 * Under the Never-Serialize Rule there is no fallback path here: the runtime will not quietly
 * encode the value to get it across. So the message has one job — **name the remedy**. A developer
 * hitting this should not have to read the internals to learn that the fix is `persist()`, or
 * `SharedArray`, or a `Task`.
 */
final class NotShareableValueException extends \InvalidArgumentException implements CoroutineException
{
    public static function forArray(): self
    {
        return new self(
            'a plain array cannot cross a worker boundary: the engine grows a HashTable into '
            . 'process-local heap, which siblings cannot see. Use SharedArray instead.',
        );
    }

    /**
     * A closure was offered to a worker boundary without having been registered before the fork.
     *
     * Acceptance is **provenance — whether the closure was compiled before the fork barrier — and
     * never the closure's shape.** A post-fork closure cannot be recognised by inspection: the
     * substrate spikes found a stale address holding a different, perfectly valid `Closure`, which
     * on PHP 8.5 executed the *wrong function* rather than failing. Anything that looks at bound
     * variables, scope or arity to decide will pass that case and hand the caller a silent wrong
     * answer, which is why registration is the whole test.
     */
    public static function forClosure(): self
    {
        return new self(
            'a closure cannot cross a worker boundary unless it was registered before the fork: '
            . 'call $runtime->registerSharedClosure($name, $closure) while the pool is still being '
            . 'configured, and it is shareable for the life of the family. A closure created after '
            . 'the fork can never be shared — no inspection can tell it apart from a valid one at a '
            . 'stale address — so work created then travels as a Task instead.',
        );
    }

    /** @param resource $resource */
    public static function forResource($resource): self
    {
        return new self(
            sprintf(
                'a resource (%s) cannot cross a worker boundary: it names process-local kernel '
                . 'state. Open it inside the task instead.',
                get_resource_type($resource),
            ),
        );
    }

    public static function forObject(object $object): self
    {
        return new self(
            sprintf(
                'an instance of %s is not in the shared arena, so it cannot cross a worker '
                . 'boundary. Pass it through $runtime->persist($object) first.',
                $object::class,
            ),
        );
    }

    public static function forType(string $type): self
    {
        return new self(sprintf('a value of type %s cannot cross a worker boundary', $type));
    }
}
