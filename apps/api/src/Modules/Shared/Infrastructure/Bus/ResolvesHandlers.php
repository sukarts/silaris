<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Bus;

use Illuminate\Contracts\Container\Container;
use RuntimeException;

trait ResolvesHandlers
{
    public function __construct(private readonly Container $container) {}

    private function resolveHandler(object $message, string $suffix, string $replaced): object
    {
        $class = $message::class;
        if (! str_ends_with($class, $replaced)) {
            throw new RuntimeException("{$class} doit se terminer par {$replaced}");
        }
        $handlerClass = substr($class, 0, -strlen($replaced)).$suffix;
        if (! class_exists($handlerClass)) {
            throw new RuntimeException("Handler introuvable : {$handlerClass}");
        }

        return $this->container->make($handlerClass);
    }
}
