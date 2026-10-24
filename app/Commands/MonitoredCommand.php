<?php

namespace App\Commands;

use App\Core\Cron\CronMonitor;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * A scheduled (cron) command. Subclasses implement handle() instead of run(); this class reports the start and end of
 * every run to the cron monitor (Admin > Cron jobs). Recording never changes the job's own result: a monitoring error
 * is logged and ignored, an exception is recorded and then re-thrown, and --dry-run is not recorded because it does no
 * real work. Declare the job in its module's 'cron' registry entry so the monitor knows when to expect it.
 */
abstract class MonitoredCommand extends BaseCommand
{
    private ?string $note = null;

    final public function run(array $params)
    {
        $dry     = array_key_exists('dry-run', $params) || CLI::getOption('dry-run') !== null;
        $monitor = new CronMonitor();
        $runId   = $dry ? null : $monitor->start($this->name);

        try {
            $exit = $this->handle($params);
        } catch (Throwable $e) {
            $monitor->finish($runId, EXIT_ERROR, $e::class . ': ' . $e->getMessage());

            throw $e;
        }

        $monitor->finish($runId, is_int($exit) ? $exit : EXIT_SUCCESS, $this->note);

        return $exit;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return int|null EXIT_SUCCESS (or null) when the job did its work
     */
    abstract protected function handle(array $params);

    /**
     * One line shown against this run on the Cron jobs page (the reason for a failure, or a short summary).
     */
    protected function note(string $message): void
    {
        $this->note = $message;
    }
}
