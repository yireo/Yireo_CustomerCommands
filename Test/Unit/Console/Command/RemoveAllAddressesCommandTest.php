<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressSearchResultsInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\RemoveAllAddressesCommand;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;
use Yireo\CustomerCommands\Console\RemovalConfirmation;

final class RemoveAllAddressesCommandTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private AddressRepositoryInterface&MockObject $addressRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;
    private State&MockObject $state;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteria::class));
        $this->state = $this->createMock(State::class);
        $this->state->method('emulateAreaCode')
            ->willReturnCallback(fn (string $areaCode, callable $callback) => $callback());
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:remove-all', $this->createCommand()->getName());
    }

    public function testRunsInAdminhtmlArea(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([]);
        $this->state->expects($this->once())->method('emulateAreaCode')->with('adminhtml');

        $this->createCommandTester()->execute(['--customer_id' => '42', '--force' => true]);
    }

    public function testFailsWithoutCustomerIdOrEmail(): void
    {
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--force' => true]));
        $this->assertStringContainsString('Please supply either customer ID or email', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomer(): void
    {
        $this->customerRepository->method('get')
            ->willThrowException(NoSuchEntityException::singleField('email', 'unknown@example.com'));
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute(['--customer_email' => 'unknown@example.com', '--force' => true]);
        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }

    public function testRejectsBothCustomerIdAndEmail(): void
    {
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute([
            '--customer_id' => '1',
            '--customer_email' => 'jane@example.com',
            '--force' => true,
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('not both', $commandTester->getDisplay());
    }

    public function testReportsCustomerWithoutAddresses(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([]);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $this->assertStringContainsString('No addresses found for customer ID 42.', $commandTester->getDisplay());
    }

    public function testRemovesAllAddressesOfCustomerWithForce(): void
    {
        $addresses = [$this->createAddress(11), $this->createAddress(12)];
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->searchCriteriaBuilder->expects($this->once())->method('addFilter')->with('parent_id', 42);
        $this->setAddresses($addresses);

        $deleted = [];
        $this->addressRepository->expects($this->exactly(2))->method('delete')
            ->willReturnCallback(function (AddressInterface $address) use (&$deleted) {
                $deleted[] = $address;
                return true;
            });

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42', '--force' => true]));
        $this->assertSame($addresses, $deleted);
        $this->assertStringContainsString(
            'Successfully removed 2 address(es) for customer ID 42.',
            $commandTester->getDisplay()
        );
    }

    public function testRefusesWithoutForceWhenNotInteractive(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11)]);
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42'], ['interactive' => false]));
        $this->assertStringContainsString('Use --force', $commandTester->getDisplay());
    }

    public function testAsksForConfirmation(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11), $this->createAddress(12)]);
        $this->addressRepository->expects($this->exactly(2))->method('delete')->willReturn(true);

        $commandTester = $this->createCommandTester();
        $commandTester->setInputs(['y']);

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $this->assertStringContainsString(
            'Remove 2 address(es) (IDs: 11, 12) of customer jane@example.com (ID 42)? [y/N]',
            $commandTester->getDisplay()
        );
    }

    public function testDoesNotRemoveWhenDeclined(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11)]);
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();
        $commandTester->setInputs(['no']);

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42']));
        $this->assertStringContainsString('Aborted: Nothing was removed', $commandTester->getDisplay());
    }

    public function testDryRunRemovesNothing(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11), $this->createAddress(12)]);
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42', '--dry-run' => true]));
        $this->assertStringContainsString(
            'Dry run: Would remove 2 address(es) (IDs: 11, 12) of customer jane@example.com (ID 42).',
            $commandTester->getDisplay()
        );
    }

    public function testFailsAfterPartiallyFailingDelete(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11), $this->createAddress(12), $this->createAddress(13)]);
        $this->addressRepository->expects($this->exactly(3))->method('delete')
            ->willReturnCallback(function (AddressInterface $address) {
                if ($address->getId() === 12) {
                    throw new LocalizedException(new Phrase("Locked <info>x</info>\x1b[2J"));
                }

                return true;
            });

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42', '--force' => true]));
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Could not delete address ID 12: Locked <info>x</info>', $display);
        $this->assertStringNotContainsString("\x1b", $display);
        $this->assertStringContainsString('Successfully removed 2 address(es) for customer ID 42.', $display);
        $this->assertStringContainsString('Failed to remove 1 address(es).', $display);
    }

    public function testReportsCustomerIdWhenLoadedByEmail(): void
    {
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com')
            ->willReturn($this->createCustomer(7));
        $this->setAddresses([$this->createAddress(11)]);
        $this->addressRepository->method('delete')->willReturn(true);

        $commandTester = $this->createCommandTester();

        $statusCode = $commandTester->execute(['--customer_email' => 'jane@example.com', '--force' => true]);
        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertStringContainsString(
            'Successfully removed 1 address(es) for customer ID 7.',
            $commandTester->getDisplay()
        );
    }

    private function createCommand(): RemoveAllAddressesCommand
    {
        return new RemoveAllAddressesCommand(
            $this->addressRepository,
            $this->searchCriteriaBuilder,
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

    private function createCustomer(int $customerId): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getEmail')->willReturn('jane@example.com');

        return $customer;
    }

    private function createAddress(int $addressId): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getId')->willReturn($addressId);

        return $address;
    }

    /**
     * @param AddressInterface[] $addresses
     */
    private function setAddresses(array $addresses): void
    {
        $searchResults = $this->createStub(AddressSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn($addresses);
        $this->addressRepository->method('getList')->willReturn($searchResults);
    }
}
