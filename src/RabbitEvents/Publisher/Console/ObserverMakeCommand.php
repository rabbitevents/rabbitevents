<?php

declare(strict_types=1);

namespace RabbitEvents\Publisher\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * I copied this class from laravel 5.7.27 just because don't want to add the dependency to illuminate/framework
 * @taylorotwell sorry for this
 * @codeCoverageIgnore
 */
class ObserverMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'rabbitevents:make:observer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new observer class to publish events on model changes';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Observer';

    /**
     * Build the class with the given name.
     *
     * @param string $name
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function buildClass($name)
    {
        return $this->replaceModel(parent::buildClass($name), (string) $this->option('model') ?: $this->guessModel($name));
    }

    /**
     * "UserObserver" observes "User".
     */
    protected function guessModel(string $name): string
    {
        $class = class_basename($name);

        if (!str_ends_with($class, 'Observer') || $class === 'Observer') {
            throw new \InvalidArgumentException("Can't guess the model of [{$class}]: pass it with --model.");
        }

        return Str::beforeLast($class, 'Observer');
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub(): string
    {
        return __DIR__ . '/stubs/observer.stub';
    }

    /**
     * Replace the model for the given stub.
     *
     * @param string $stub
     * @param string $model
     * @return string
     */
    protected function replaceModel(string $stub, string $model): string
    {
        $model = str_replace('/', '\\', $model);

        $namespaceModel = $this->laravel->getNamespace() . $model;

        if (Str::startsWith($model, '\\')) {
            $stub = str_replace('NamespacedDummyModel', trim($model, '\\'), $stub);
        } else {
            $stub = str_replace('NamespacedDummyModel', $namespaceModel, $stub);
        }

        $stub = str_replace(
            "use {$namespaceModel};\nuse {$namespaceModel};",
            "use {$namespaceModel};",
            $stub
        );

        $model = class_basename(trim($model, '\\'));

        $stub = str_replace('DocDummyModel', Str::snake($model, ' '), $stub);

        $stub = str_replace('DummyModel', $model, $stub);

        return str_replace('dummyModel', Str::camel($model), $stub);
    }

    /**
     * Get the default namespace for the class.
     *
     * @param string $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\Observers';
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The model that an observer applies to.'],
        ];
    }
}
