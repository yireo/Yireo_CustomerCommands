<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RemovalConfirmation
{
    public function addOption(Command $command): void
    {
        $command->addOption('force', 'f', InputOption::VALUE_NONE, 'Remove without asking for confirmation');
    }

    public function isConfirmed(InputInterface $input, OutputInterface $output, string $question): bool
    {
        if ($input->getOption('force') === true) {
            return true;
        }

        if (!$input->isInteractive()) {
            $output->writeln('<error>Nothing was removed: Use --force to remove without confirmation</error>');
            return false;
        }

        $confirmed = (new QuestionHelper())->ask($input, $output, new ConfirmationQuestion($question . ' [y/N] ', false));
        if ($confirmed !== true) {
            $output->writeln('<comment>Aborted: Nothing was removed</comment>');
            return false;
        }

        return true;
    }
}
