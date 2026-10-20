<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;
use RabbitEvents\Listener\ListenerServiceProvider;
use Throwable;

class EventsCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Discover and cache the application\'s RabbitEvents listeners';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->call('rabbitevents:clear');

        $listeners = $this->getListeners();

        $cachePath = $this->laravel->bootstrapPath(ListenerServiceProvider::CACHE_FILE);

        try {
            file_put_contents(
                $cachePath,
                '<?php return ' . var_export($listeners, true) . ';'
            );

            $this->info('RabbitEvents listeners cached successfully.');
        } catch (Throwable $e) {
            $this->error('Failed to cache RabbitEvents listeners: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function getListeners(): array
    {
        $listeners = [];

        foreach ($this->laravel->getProviders(ListenerServiceProvider::class) as $provider) {
            $listeners = array_merge_recursive($listeners, $provider->attributeListeners());
        }

        return $listeners;
    }
}
