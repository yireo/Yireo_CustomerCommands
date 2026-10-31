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

        $commandTester = $this->runCommand(RemoveAddressCommand::class, ['address_id' => (string)$removedAddressId]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode(), $commandTester->getDisplay());
        $this->assertStringContainsString(
            sprintf('Address with ID %d has been removed.', $removedAddressId),
            $commandTester->getDisplay()
        );
        $this->assertFalse($this->addressExists($removedAddressId));
        $this->assertTrue($this->addressExists((int)$keptAddress->getId()));
    }

    public function testFailsForUnknownAddress(): void
    {
        $commandTester = $this->runCommand(RemoveAddressCommand::class, ['address_id' => '99999999']);

        $this->assertSame(Command::FAILURE, $commandTester->getStatusCode());
        $this->assertStringContainsString('Address with ID 99999999 does not exist.', $commandTester->getDisplay());
    }
}
