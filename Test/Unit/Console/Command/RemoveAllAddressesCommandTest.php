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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\RemoveAllAddressesCommand;

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
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:remove-all', $this->createCommand()->getName());
    }

    public function testFailsWithoutCustomerIdOrEmail(): void
    {
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute([]));
        $this->assertStringContainsString('Please supply either customer ID or email', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomer(): void
    {
        $this->customerRepository->method('get')
            ->willThrowException(NoSuchEntityException::singleField('email', 'unknown@example.com'));
        $this->addressRepository->expects($this->never())->method('delete');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_email' => 'unknown@example.com']));
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }

    public function testReportsCustomerWithoutAddresses(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([]);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $this->assertStringContainsString('No addresses found for customer ID 42.', $commandTester->getDisplay());
    }

    public function testRemovesAllAddressesOfCustomer(): void
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

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $this->assertSame($addresses, $deleted);
        $this->assertStringContainsString(
            'Successfully removed 2 address(es) for customer ID 42.',
            $commandTester->getDisplay()
        );
    }

    public function testContinuesAfterFailingDelete(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([$this->createAddress(11), $this->createAddress(12), $this->createAddress(13)]);
        $this->addressRepository->expects($this->exactly(3))->method('delete')
            ->willReturnCallback(function (AddressInterface $address) {
                if ($address->getId() === 12) {
                    throw new LocalizedException(new Phrase('Locked'));
                }

                return true;
            });

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Could not delete address ID 12: Locked', $display);
        $this->assertStringContainsString('Successfully removed 2 address(es) for customer ID 42.', $display);
    }

    public function testReportsCustomerIdWhenLoadedByEmail(): void
    {
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com')
            ->willReturn($this->createCustomer(7));
        $this->setAddresses([$this->createAddress(11)]);
        $this->addressRepository->method('delete')->willReturn(true);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_email' => 'jane@example.com']));
        $this->assertStringContainsString(
            'Successfully removed 1 address(es) for customer ID 7.',
            $commandTester->getDisplay()
        );
    }

    public function testSetsAreaCodeBeforeLoadingCustomer(): void
    {
        $areaCode = null;
        $this->state->expects($this->once())->method('setAreaCode')->with('frontend')
            ->willReturnCallback(function (string $code) use (&$areaCode) {
                $areaCode = $code;
            });
        $this->customerRepository->method('getById')
            ->willReturnCallback(function () use (&$areaCode) {
                $this->assertSame('frontend', $areaCode, 'Area code must be set before loading the customer');
                return $this->createCustomer(42);
            });
        $this->setAddresses([]);

        $this->createCommandTester()->execute(['--customer_id' => '42']);
    }

    private function createCommand(): RemoveAllAddressesCommand
    {
        return new RemoveAllAddressesCommand(
            $this->customerRepository,
            $this->addressRepository,
            $this->searchCriteriaBuilder,
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
