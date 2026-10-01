<?php

namespace App\Console\Commands;

use App\Domain\System\ServerHealth;
use Illuminate\Console\Command;

/** Checks the server has what LotLink needs (handy right after installing on cPanel). Exit code 1 if anything fails. */
class LotLinkDoctor extends Command
{
    protected $signature = 'lotlink:doctor';

    protected $description = 'Check PHP, folders, the image repository, cron, queue, mail and search';

    public function handle(): int
    {
        $failed = false;
        $group = null;
        foreach (ServerHealth::checks() as $c) {
            if ($c['group'] !== $group) {
                $this->newLine();
                $this->line("<options=bold>{$c['group']}</>");
                $group = $c['group'];
            }
            $mark = match ($c['status']) {
                ServerHealth::OK => '<fg=green>✔</>',
                ServerHealth::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✘</>',
            };
            $this->line("  {$mark} {$c['label']}".($c['detail'] !== '' ? "  <fg=gray>{$c['detail']}</>" : ''));
            $failed = $failed || $c['status'] === ServerHealth::FAIL;
        }
        $this->newLine();
        $failed ? $this->error('Some checks failed.') : $this->info('All required checks passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
