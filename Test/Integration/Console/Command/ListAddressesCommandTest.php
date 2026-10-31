<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Customer\Test\Fixture\Customer;
use Magento\TestFramework\Fixture\DataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Yireo\CustomerCommands\Console\Command\ListAddressesCommand;

final class ListAddressesCommandTest extends TestCase
{
    use CustomerAddressTrait;

    #[DataFixture(Customer::class, ['addresses' => [['city' => 'Boston'], ['city' => 'Springfield']]], 'customer')]
    public function testListsAddressesOfCustomer(): void
    {
        $customer = $this->getFixtureCustomer();
        $addresses = $this->getAddresses((int)$customer->getId());
        $this->assertCount(2, $addresses);

        $commandTester = $this->runCommand(ListAddressesCommand::class, [
            '--customer_email' => $customer->getEmail(),
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        foreach ($addresses as $address) {
            $this->assertMatchesRegularExpression('/\|\s*' . $address->getId() . '\s*\|/', $display);
            $this->assertStringContainsString(implode(', ', $address->getStreet()), $display);
        }
        $this->assertStringContainsString('Boston', $display);
        $this->assertStringContainsString('Springfield', $display);
    }

    #[DataFixture(Customer::class, as: 'customer')]
    public function testReportsCustomerWithoutAddresses(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(ListAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertStringContainsString('No addresses found for this customer.', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomer(): void
    {
        $commandTester = $this->runCommand(ListAddressesCommand::class, ['--customer_id' => '99999999']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }
}
