<?php

namespace ErnestDefoe\Janitor\Console;

use ErnestDefoe\Janitor\Janitor;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

class RunJanitorCommand extends AbstractCommand
{
    public function __construct(protected Janitor $janitor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('janitor:run')
            ->setDescription('Run all due Janitor housekeeping rules.')
            ->addOption('dry', null, InputOption::VALUE_NONE, 'Preview only — take no action, just log what would happen.');
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry');
        $results = $this->janitor->runDueRules($dry);

        if (! $results) {
            $this->info('Janitor: no rules are due.');

            return 0;
        }

        foreach ($results as $r) {
            $verb = $r['dry'] ? 'would apply to' : 'applied to';
            $n = $r['dry'] ? $r['matched'] : $r['applied'];
            $this->info(sprintf('Janitor [%s]: %s %d discussion(s)%s.', $r['rule'], $verb, $n, $r['capped'] ? ' (hit per-run cap)' : ''));
        }

        return 0;
    }
}
