<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\RemoveAddressCommand;

final class RemoveAddressCommandTest extends TestCase
{
    private AddressRepositoryInterface&MockObject $addressRepository;

    protected function setUp(): void
    {
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:remove', (new RemoveAddressCommand($this->addressRepository))->getName());
    }

    public function testRemovesAddress(): void
    {
        $this->addressRepository->expects($this->once())->method('deleteById')->with(12)->willReturn(true);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['address_id' => '12']));
        $this->assertStringContainsString('Address with ID 12 has been removed.', $commandTester->getDisplay());
    }

    public function testFailsForUnknownAddress(): void
    {
        $this->addressRepository->method('deleteById')
            ->willThrowException(NoSuchEntityException::singleField('addressId', 999));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '999']));
        $this->assertStringContainsString('Address with ID 999 does not exist.', $commandTester->getDisplay());
    }

    public function testFailsForOtherErrors(): void
    {
        $this->addressRepository->method('deleteById')
            ->willThrowException(new LocalizedException(new Phrase('Database is gone')));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '12']));
        $this->assertStringContainsString('An error occurred: Database is gone', $commandTester->getDisplay());
    }

    public function testRequiresAddressIdArgument(): void
    {
        $this->expectException(RuntimeException::class);

        $this->createCommandTester()->execute([]);
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester(new RemoveAddressCommand($this->addressRepository));
    }
}
