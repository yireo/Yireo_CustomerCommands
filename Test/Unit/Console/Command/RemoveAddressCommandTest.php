<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\RemoveAddressCommand;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;
use Yireo\CustomerCommands\Console\RemovalConfirmation;

final class RemoveAddressCommandTest extends TestCase
{
    private AddressRepositoryInterface&MockObject $addressRepository;
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private State&MockObject $state;

    protected function setUp(): void
    {
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->state = $this->createMock(State::class);
        $this->state->method('emulateAreaCode')
            ->willReturnCallback(fn (string $areaCode, callable $callback) => $callback());
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:remove', $this->createCommand()->getName());
    }

    public function testRunsInAdminhtmlArea(): void
    {
        $this->setAddress(12, 42);
        $this->state->expects($this->once())->method('emulateAreaCode')->with('adminhtml');

        $this->createCommandTester()->execute(['address_id' => '12', '--force' => true]);
    }

    public function testRemovesAddressWithForce(): void
    {
        $this->setAddress(12, 42);
        $this->addressRepository->expects($this->once())->method('deleteById')->with(12)->willReturn(true);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['address_id' => '12', '--force' => true]));
        $this->assertStringContainsString('Address with ID 12 has been removed.', $commandTester->getDisplay());
    }

    public function testRefusesWithoutForceWhenNotInteractive(): void
    {
        $this->setAddress(12, 42);
        $this->addressRepository->expects($this->never())->method('deleteById');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '12'], ['interactive' => false]));
        $this->assertStringContainsString('Use --force', $commandTester->getDisplay());
    }

    public function testAsksForConfirmation(): void
    {
        $this->setAddress(12, 42);
        $this->addressRepository->expects($this->once())->method('deleteById')->with(12);

        $commandTester = $this->createCommandTester();
        $commandTester->setInputs(['yes']);

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['address_id' => '12']));
        $this->assertStringContainsString(
            'Remove address with ID 12 (Main Street 1, Austin, US) of customer ID 42? [y/N]',
            $commandTester->getDisplay()
        );
    }

    public function testDoesNotRemoveWhenDeclined(): void
    {
        $this->setAddress(12, 42);
        $this->addressRepository->expects($this->never())->method('deleteById');

        $commandTester = $this->createCommandTester();
        $commandTester->setInputs(['n']);

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '12']));
        $this->assertStringContainsString('Aborted: Nothing was removed', $commandTester->getDisplay());
    }

    public function testFailsForUnknownAddress(): void
    {
        $this->addressRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('addressId', 999));
        $this->addressRepository->expects($this->never())->method('deleteById');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '999', '--force' => true]));
        $this->assertStringContainsString('Address with ID 999 does not exist.', $commandTester->getDisplay());
    }

    public function testRejectsInvalidAddressId(): void
    {
        $this->addressRepository->expects($this->never())->method('getById');
        $this->addressRepository->expects($this->never())->method('deleteById');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '12abc', '--force' => true]));
        $this->assertStringContainsString('Invalid address ID "12abc"', $commandTester->getDisplay());
    }

    public function testRemovesAddressOwnedByCustomer(): void
    {
        $this->setAddress(12, 42);
        $this->customerRepository->method('getById')->with(42)->willReturn($this->createCustomer(42));
        $this->addressRepository->expects($this->once())->method('deleteById')->with(12);

        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute(['address_id' => '12', '--customer_id' => '42', '--force' => true]);
        $this->assertSame(Command::SUCCESS, $statusCode);
    }

    public function testRefusesAddressOfOtherCustomer(): void
    {
        $this->setAddress(12, 42);
        $this->customerRepository->method('get')->with('other@example.com')->willReturn($this->createCustomer(7));
        $this->addressRepository->expects($this->never())->method('deleteById');

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute([
            'address_id' => '12',
            '--customer_email' => 'other@example.com',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString(
            'Address with ID 12 does not belong to customer ID 7.',
            $commandTester->getDisplay()
        );
    }

    public function testFailsForOtherErrorsWithEscapedMessage(): void
    {
        $this->setAddress(12, 42);
        $this->addressRepository->method('deleteById')
            ->willThrowException(new LocalizedException(new Phrase("Database <fg=red>gone</>\x1b[2J")));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['address_id' => '12', '--force' => true]));
        $this->assertStringContainsString('An error occurred: Database <fg=red>gone</>', $commandTester->getDisplay());
        $this->assertStringNotContainsString("\x1b", $commandTester->getDisplay());
    }

    public function testRequiresAddressIdArgument(): void
    {
        $this->expectException(RuntimeException::class);

        $this->createCommandTester()->execute([]);
    }

    private function createCommand(): RemoveAddressCommand
    {
        return new RemoveAddressCommand(
            $this->addressRepository,
            new CustomerResolver($this->customerRepository, $this->createStub(StoreManagerInterface::class)),
            new RemovalConfirmation(),
            new OutputSanitizer(),
            $this->state
        );
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester($this->createCommand());
    }

    private function setAddress(int $addressId, int $customerId): void
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getId')->willReturn($addressId);
        $address->method('getCustomerId')->willReturn($customerId);
        $address->method('getStreet')->willReturn(['Main Street 1']);
        $address->method('getCity')->willReturn('Austin');
        $address->method('getCountryId')->willReturn('US');
        $this->addressRepository->method('getById')->with($addressId)->willReturn($address);
    }

    private function createCustomer(int $customerId): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);

        return $customer;
    }
}
