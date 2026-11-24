<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\RemovalConfirmation;

final class RemovalConfirmationTest extends TestCase
{
    public function testForceSkipsQuestion(): void
    {
        $tester = $this->createTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--force' => true], ['interactive' => false]));
        $this->assertStringNotContainsString('Remove it?', $tester->getDisplay());
    }

    public function testRefusesWhenNotInteractiveWithoutForce(): void
    {
        $tester = $this->createTester();

        $this->assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        $this->assertStringContainsString('Use --force to remove without confirmation', $tester->getDisplay());
    }

    public function testConfirmsWithYes(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['y']);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Remove it? [y/N]', $tester->getDisplay());
    }

    public function testDeclinesByDefault(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['']);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Aborted: Nothing was removed', $tester->getDisplay());
    }

    private function createTester(): CommandTester
    {
        $confirmation = new RemovalConfirmation();
        $command = new class ($confirmation) extends Command {
            public function __construct(private readonly RemovalConfirmation $confirmation)
            {
                parent::__construct('test:confirm');
            }

            protected function configure(): void
            {
                $this->confirmation->addOption($this);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->confirmation->isConfirmed($input, $output, 'Remove it?')
                    ? Command::SUCCESS
                    : Command::FAILURE;
            }
        };

        return new CommandTester($command);
    }
}
