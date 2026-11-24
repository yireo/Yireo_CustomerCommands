<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Customer\Test\Fixture\Customer;
use Magento\TestFramework\Fixture\DataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Yireo\CustomerCommands\Console\Command\RemoveAddressCommand;

final class RemoveAddressCommandTest extends TestCase
{
    use CustomerAddressTrait;

    #[DataFixture(Customer::class, ['addresses' => [[], []]], 'customer')]
    public function testRemovesSingleAddress(): void
    {
        $customer = $this->getFixtureCustomer();
        [$removedAddress, $keptAddress] = $this->getAddresses((int)$customer->getId());
        $removedAddressId = (int)$removedAddress->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, [
            'address_id' => (string)$removedAddressId,
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString(
            sprintf('Address with ID %d has been removed.', $removedAddressId),
            $commandTester->getDisplay()
        );
        $this->assertFalse($this->addressExists($removedAddressId));
        $this->assertTrue($this->addressExists((int)$keptAddress->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRefusesWithoutForceWhenNotInteractive(): void
    {
        $customer = $this->getFixtureCustomer();
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, ['address_id' => (string)$addressId]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Use --force', $commandTester->getDisplay());
        $this->assertTrue($this->addressExists($addressId));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRemovesAfterInteractiveConfirmation(): void
    {
        $customer = $this->getFixtureCustomer();
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, ['address_id' => (string)$addressId], ['y']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertFalse($this->addressExists($addressId));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    #[DataFixture(Customer::class, as: 'other_customer')]
    public function testRefusesAddressOfOtherCustomer(): void
    {
        $customer = $this->getFixtureCustomer();
        $otherCustomer = $this->getFixtureCustomer('other_customer');
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, [
            'address_id' => (string)$addressId,
            '--customer_email' => $otherCustomer->getEmail(),
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString(
            sprintf('Address with ID %d does not belong to customer ID %d.', $addressId, $otherCustomer->getId()),
            $commandTester->getDisplay()
        );
        $this->assertTrue($this->addressExists($addressId));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRemovesAddressOwnedByGivenCustomer(): void
    {
        $customer = $this->getFixtureCustomer();
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, [
            'address_id' => (string)$addressId,
            '--customer_id' => (string)$customer->getId(),
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertFalse($this->addressExists($addressId));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRejectsAddressIdWithTrailingCharacters(): void
    {
        $customer = $this->getFixtureCustomer();
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();

        $commandTester = $this->runCommand(RemoveAddressCommand::class, [
            'address_id' => $addressId . 'abc',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid address ID', $commandTester->getDisplay());
        $this->assertTrue($this->addressExists($addressId));
    }

    public function testFailsForUnknownAddress(): void
    {
        $commandTester = $this->runCommand(RemoveAddressCommand::class, [
            'address_id' => '99999999',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Address with ID 99999999 does not exist.', $commandTester->getDisplay());
    }
}
