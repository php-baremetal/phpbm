<?php

declare(strict_types=1);

namespace App\Commands;

use App\PhpbmException;
use App\Support\ConsoleOutput;
use LaravelZero\Framework\Commands\Command;
use PhpBaremetal\Spec\SpecException;

abstract class PhpbmCommand extends Command
{
    final public function handle(): int
    {
        try {
            return $this->perform();
        } catch (PhpbmException|SpecException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\RuntimeException|\ErrorException $e) {
            if ($this->output->isVeryVerbose()) {
                throw $e;
            }
            $this->error($e->getMessage() . ' (run with -vv for details)');
            return self::FAILURE;
        }
    }

    abstract protected function perform(): int;

    protected function out(): ConsoleOutput
    {
        return new ConsoleOutput($this->output);
    }
}
