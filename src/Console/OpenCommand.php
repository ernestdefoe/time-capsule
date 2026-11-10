<?php

namespace ErnestDefoe\TimeCapsule\Console;

use ErnestDefoe\TimeCapsule\Opener;
use Flarum\Console\AbstractCommand;

/** Scheduled every minute (extend.php). Safe to run by hand, or twice at once: see Opener. */
class OpenCommand extends AbstractCommand
{
    public function __construct(private readonly Opener $opener)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('time-capsule:open')->setDescription('Open the time capsules whose day has come.');
    }

    protected function fire(): int
    {
        $opened = $this->opener->run();
        if ($opened) {
            $this->info("Opened {$opened}.");
        }

        return 0;
    }
}
