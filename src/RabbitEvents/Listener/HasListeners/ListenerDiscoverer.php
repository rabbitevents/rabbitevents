<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\HasListeners;

use Illuminate\Support\Str;
use RabbitEvents\Listener\Attributes\Listener;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

class ListenerDiscoverer
{
    /**
     * Get all the listeners from the given path.
     *
     * @param string $path
     * @param string $basePath
     * @param string $baseNamespace
     * @return array
     */
    public static function discover(string $path, string $basePath, string $baseNamespace): array
    {
        // Laravel 11+ applications have no app/Listeners directory by default.
        if (!is_dir($path)) {
            return [];
        }

        $listeners = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() === 'php') {
                $class = static::classFromFile($file, $basePath, $baseNamespace);

                if (static::isValidListener($class)) {
                    $listeners[] = $class;
                }
            }
        }

        return $listeners;
    }

    /**
     * Extract the class name from the given file path.
     *
     * @param SplFileInfo $file
     * @param string $basePath
     * @param string $baseNamespace
     * @return string
     */
    protected static function classFromFile(SplFileInfo $file, string $basePath, string $baseNamespace): string
    {
        $class = trim(Str::replaceFirst($basePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);

        return $baseNamespace . str_replace(
            [DIRECTORY_SEPARATOR, '.php'],
            ['\\', ''],
            $class
        );
    }

    /**
     * Determine if the class is a valid listener.
     *
     * @param string $class
     * @return bool
     */
    protected static function isValidListener(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        return !$reflection->isAbstract() &&
            (static::hasListenerAttribute($reflection) || static::hasPublicMethods($reflection));
    }

    protected static function hasListenerAttribute(ReflectionClass $reflection): bool
    {
        return $reflection->getAttributes(Listener::class) !== [];
    }

    protected static function hasPublicMethods(ReflectionClass $reflection): bool
    {
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Listener::class) !== []) {
                return true;
            }
        }

        return false;
    }
    /**
     * Get the events from the listener attributes.
     *
     * @param string $class
     * @return array
     */
    public static function eventsFromClass(string $class): array
    {
        $listeners = [];
        $reflection = new ReflectionClass($class);

        // Class Level Attributes
        foreach ($reflection->getAttributes(Listener::class) as $attribute) {
            /** @var Listener $instance */
            $instance = $attribute->newInstance();
            $listeners[$instance->event][] = [$class, 'handle'];
        }

        // Method Level Attributes
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Listener::class) as $attribute) {
                /** @var Listener $instance */
                $instance = $attribute->newInstance();
                $listeners[$instance->event][] = [$class, $method->getName()];
            }
        }

        return $listeners;
    }
}
