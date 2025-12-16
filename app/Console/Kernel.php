<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\ImportPdfs;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<int, class-string>
     */
    protected $commands = [
        ImportPdfs::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Example:
        // $schedule->command('import:pdfs "C:\path\to\pdfs"')->daily();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // Load commands in the Commands directory (keeps auto-discovery working)
        $this->load(__DIR__ . '/Commands');

        // Load console routes if present
        if (file_exists(base_path('routes/console.php'))) {
            require base_path('routes/console.php');
        }
    }
}
