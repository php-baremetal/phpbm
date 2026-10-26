<?php

declare(strict_types=1);

namespace App\Support;

use PhpBaremetal\Spec\Sdk\Output;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleOutput implements Output
{
    public function __construct(private OutputInterface $output)
    {
    }

    public function step(string $message): void
    {
        $this->output->writeln('<info>==></info> ' . OutputFormatter::escape($message));
    }

    public function line(string $message): void
    {
        $this->output->writeln(OutputFormatter::escape($message));
    }

    public function warning(string $message): void
    {
        $this->output->writeln('<comment>warning:</comment> ' . OutputFormatter::escape($message));
    }
}
