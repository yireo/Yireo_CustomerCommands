<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Customer\Test\Fixture\Customer;
use Magento\TestFramework\Fixture\DataFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Yireo\CustomerCommands\Console\Command\AddAddressCommand;

final class AddAddressCommandTest extends TestCase
{
    use CustomerAddressTrait;

    #[DataFixture(Customer::class, as: 'customer')]
    public function testAddsAddressWithRegionName(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(AddAddressCommand::class, [
            '--customer_email' => $customer->getEmail(),
            '--firstname' => 'Jane',
            '--lastname' => 'Doe',
            '--street' => '1 Congress Avenue',
            '--city' => 'Austin',
            '--postcode' => '78701',
            '--region' => 'Texas',
            '--country' => 'US',
            '--telephone' => '0123456789',
            '--company' => 'Yireo',
        ]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());

        $addresses = $this->getAddresses((int)$customer->getId());
        $this->assertCount(1, $addresses);

        $address = $addresses[0];
        $this->assertStringContainsString(
            sprintf('Successfully added address (ID: %d) to customer %s', $address->getId(), $customer->getEmail()),
            $commandTester->getDisplay()
        );
        $this->assertSame(['1 Congress Avenue'], $address->getStreet());
        $this->assertSame('Austin', $address->getCity());
        $this->assertSame('78701', $address->getPostcode());
        $this->assertSame('US', $address->getCountryId());
        $this->assertSame('Yireo', $address->getCompany());
        $this->assertSame('TX', $address->getRegion()->getRegionCode());
    }

    #[DataFixture(Customer::class, as: 'customer')]
    public function testAddsAddressWithoutRegionByCustomerId(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(AddAddressCommand::class, $this->getDutchAddressInput([
            '--customer_id' => (string)$customer->getId(),
        ]));

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());

        $addresses = $this->getAddresses((int)$customer->getId());
        $this->assertCount(1, $addresses);
        $this->assertSame('NL', $addresses[0]->getCountryId());
        $this->assertEmpty($addresses[0]->getRegionId());
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testDoesNotChangeDefaultAddressesWithoutFlags(): void
    {
        $customer = $this->getFixtureCustomer();
        $defaultBilling = $customer->getDefaultBilling();
        $defaultShipping = $customer->getDefaultShipping();
        $this->assertNotEmpty($defaultBilling);

        $commandTester = $this->runCommand(AddAddressCommand::class, $this->getDutchAddressInput([
            '--customer_id' => (string)$customer->getId(),
        ]));
        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());

        $customer = $this->reloadCustomer((int)$customer->getId());
        $this->assertCount(2, $this->getAddresses((int)$customer->getId()));
        $this->assertSame($defaultBilling, $customer->getDefaultBilling());
        $this->assertSame($defaultShipping, $customer->getDefaultShipping());
    }

    #[DataFixture(Customer::class, ['addresses' => [[]]], 'customer')]
    public function testSetsDefaultAddressesWithFlags(): void
    {
        $customer = $this->getFixtureCustomer();
        $oldDefaultBilling = $customer->getDefaultBilling();

        $commandTester = $this->runCommand(AddAddressCommand::class, $this->getDutchAddressInput([
            '--customer_id' => (string)$customer->getId(),
            '--default-billing' => null,
            '--default-shipping' => null,
        ]));
        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());

        $newAddress = null;
        foreach ($this->getAddresses((int)$customer->getId()) as $address) {
            if ($address->getCountryId() === 'NL') {
                $newAddress = $address;
            }
        }
        $this->assertNotNull($newAddress);

        $customer = $this->reloadCustomer((int)$customer->getId());
        $this->assertNotSame($oldDefaultBilling, $customer->getDefaultBilling());
        $this->assertSame((string)$newAddress->getId(), (string)$customer->getDefaultBilling());
        $this->assertSame((string)$newAddress->getId(), (string)$customer->getDefaultShipping());
    }

    #[DataFixture(Customer::class, as: 'customer')]
    public function testFailsForInvalidAddress(): void
    {
        $customer = $this->getFixtureCustomer();

        $commandTester = $this->runCommand(AddAddressCommand::class, [
            '--customer_id' => (string)$customer->getId(),
            '--firstname' => 'Jane',
        ]);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Error saving address:', $commandTester->getDisplay());
        $this->assertCount(0, $this->getAddresses((int)$customer->getId()));
    }

    public function testFailsForUnknownCustomer(): void
    {
        $commandTester = $this->runCommand(AddAddressCommand::class, $this->getDutchAddressInput([
            '--customer_email' => 'unknown-' . uniqid() . '@example.com',
        ]));

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }

    /**
     * @param array<string, string|null> $input
     * @return array<string, string|null>
     */
    private function getDutchAddressInput(array $input): array
    {
        return array_merge([
            '--firstname' => 'Jan',
            '--lastname' => 'Jansen',
            '--street' => 'Dorpsstraat 5',
            '--city' => 'Utrecht',
            '--postcode' => '3511 AA',
            '--country' => 'NL',
            '--telephone' => '0301234567',
        ], $input);
    }
}
