<?php

declare(strict_types=1);

namespace RabbitEvents\Listener\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RabbitEvents\Listener\ListenerServiceProvider;

class EventsClearCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitevents:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all cached RabbitEvents listeners';

    /**
     * Execute the console command.
     *
     * @param Filesystem $files
     * @return int
     */
    public function handle(Filesystem $files): int
    {
        $files->delete($this->laravel->bootstrapPath(ListenerServiceProvider::CACHE_FILE));

        $this->info('RabbitEvents listeners cache cleared successfully.');

        return 0;
    }
}
