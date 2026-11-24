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

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testEscapesMarkupAndControlCharactersFromStoredAddress(): void
    {
        $customer = $this->getFixtureCustomer();
        $addressId = (int)$this->getAddresses((int)$customer->getId())[0]->getId();
        $this->updateAddressInDatabase($addressId, [
            'firstname' => "<href=https://evil.test>Click</>\x1b]8;;https://evil.test\x07",
            'street' => "<error>Fake</error>\x1b[2J",
        ]);

        $commandTester = $this->runCommand(ListAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('<href=https://evil.test>Click</>', $display);
        $this->assertStringContainsString('<error>Fake</error>', $display);
        $this->assertStringNotContainsString("\x1b", $display);
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testRejectsBothCustomerIdAndEmail(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(ListAddressesCommand::class, [
            '--customer_id' => (string)$customer->getId(),
            '--customer_email' => $customer->getEmail(),
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('not both', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomer(): void
    {
        $commandTester = $this->runCommand(ListAddressesCommand::class, ['--customer_id' => '99999999']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }
}
