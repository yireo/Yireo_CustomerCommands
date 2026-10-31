<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Customer\Test\Fixture\Customer;
use Magento\TestFramework\Fixture\DataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Yireo\CustomerCommands\Console\Command\RemoveAllAddressesCommand;

final class RemoveAllAddressesCommandTest extends TestCase
{
    use CustomerAddressTrait;

    #[DataFixture(Customer::class, ['addresses' => [[], []]], 'customer')]
    #[DataFixture(Customer::class, ['addresses' => [[]]], 'other_customer')]
    public function testRemovesAllAddressesOfCustomerOnly(): void
    {
        $customer = $this->getFixtureCustomer();
        $otherCustomer = $this->getFixtureCustomer('other_customer');

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_email' => $customer->getEmail(),
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString(
            sprintf('Successfully removed 2 address(es) for customer ID %d.', $customer->getId()),
            $commandTester->getDisplay()
        );
        $this->assertCount(0, $this->getAddresses((int)$customer->getId()));
        $this->assertCount(1, $this->getAddresses((int)$otherCustomer->getId()));
    }

    #[DataFixture(Customer::class, as: 'customer')]
    public function testReportsCustomerWithoutAddresses(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString(
            sprintf('No addresses found for customer ID %d.', $customer->getId()),
            $commandTester->getDisplay()
        );
    }

    public function testFailsForUnknownCustomer(): void
    {
        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_email' => 'unknown-' . uniqid() . '@example.com',
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }
}
