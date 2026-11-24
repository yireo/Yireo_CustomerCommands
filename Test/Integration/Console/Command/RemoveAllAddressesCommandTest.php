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
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString(
            sprintf('Successfully removed 2 address(es) for customer ID %d.', $customer->getId()),
            $commandTester->getDisplay()
        );
        $this->assertCount(0, $this->getAddresses((int)$customer->getId()));
        $this->assertCount(1, $this->getAddresses((int)$otherCustomer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[], []]], 'customer')]
    public function testRefusesWithoutForceWhenNotInteractive(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Use --force', $commandTester->getDisplay());
        $this->assertCount(2, $this->getAddresses((int)$customer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[], []]], 'customer')]
    public function testKeepsAddressesWhenDeclined(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(
            RemoveAllAddressesCommand::class,
            ['--customer_id' => (string)$customer->getId()],
            ['n']
        );

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Aborted: Nothing was removed', $commandTester->getDisplay());
        $this->assertCount(2, $this->getAddresses((int)$customer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[], []]], 'customer')]
    public function testDryRunKeepsAddresses(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString('Dry run: Would remove 2 address(es)', $commandTester->getDisplay());
        $this->assertCount(2, $this->getAddresses((int)$customer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testLoadsCustomerByEmailInWebsite(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_email' => $customer->getEmail(),
            '--website' => 'base',
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertCount(0, $this->getAddresses((int)$customer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testFailsForUnknownWebsite(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_email' => $customer->getEmail(),
            '--website' => 'does_not_exist',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unknown website "does_not_exist"', $commandTester->getDisplay());
        $this->assertCount(1, $this->getAddresses((int)$customer->getId()));
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRejectsCustomerIdWithTrailingCharacters(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(RemoveAllAddressesCommand::class, [
            '--customer_id' => $customer->getId() . 'abc',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid customer ID', $commandTester->getDisplay());
        $this->assertCount(1, $this->getAddresses((int)$customer->getId()));
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
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }
}
