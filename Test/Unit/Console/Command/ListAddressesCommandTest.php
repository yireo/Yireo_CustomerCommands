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
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\ListAddressesCommand;

final class ListAddressesCommandTest extends TestCase
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
        $this->state = $this->createMock(State::class);
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createStub(SearchCriteria::class));
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:list', $this->createCommand()->getName());
    }

    public function testFailsWithoutCustomerIdOrEmail(): void
    {
        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute([]));
        $this->assertStringContainsString('Please supply either customer ID or email', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomerId(): void
    {
        $this->customerRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('customerId', 999));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '999']));
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomerEmail(): void
    {
        $this->customerRepository->method('get')
            ->willThrowException(NoSuchEntityException::singleField('email', 'unknown@example.com'));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_email' => 'unknown@example.com']));
        $this->assertStringContainsString('Unable to load customer', $commandTester->getDisplay());
    }

    public function testLoadsCustomerById(): void
    {
        $this->customerRepository->expects($this->once())->method('getById')->with('42')
            ->willReturn($this->createCustomer(42));
        $this->customerRepository->expects($this->never())->method('get');
        $this->searchCriteriaBuilder->expects($this->once())->method('addFilter')->with('parent_id', 42);
        $this->setAddresses([]);

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));
        $this->assertStringContainsString('No addresses found for this customer.', $commandTester->getDisplay());
    }

    public function testLoadsCustomerByEmail(): void
    {
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com')
            ->willReturn($this->createCustomer(7));
        $this->searchCriteriaBuilder->expects($this->once())->method('addFilter')->with('parent_id', 7);
        $this->setAddresses([]);

        $this->assertSame(
            Command::SUCCESS,
            $this->createCommandTester()->execute(['--customer_email' => 'jane@example.com'])
        );
    }

    public function testEmailWinsOverCustomerId(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(1));
        $this->customerRepository->method('get')->willReturn($this->createCustomer(2));
        $this->searchCriteriaBuilder->expects($this->once())->method('addFilter')->with('parent_id', 2);
        $this->setAddresses([]);

        $this->createCommandTester()->execute(['--customer_id' => '1', '--customer_email' => 'jane@example.com']);
    }

    public function testRendersAddressTable(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42));
        $this->setAddresses([
            $this->createAddress(11, ['Main Street 1', 'Floor 2'], 'Austin', '78701', 'US'),
            $this->createAddress(12, ['Dorpsstraat 5'], 'Utrecht', '3511 AA', 'NL'),
        ]);

        $commandTester = $this->createCommandTester();
        $this->assertSame(Command::SUCCESS, $commandTester->execute(['--customer_id' => '42']));

        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('Street address', $display);
        $this->assertMatchesRegularExpression('/\|\s*11\s*\|\s*Jane\s*\|\s*Doe\s*\|\s*Main Street 1, Floor 2\s*\|\s*Austin\s*\|\s*78701\s*\|\s*US\s*\|/', $display);
        $this->assertMatchesRegularExpression('/\|\s*12\s*\|.*Dorpsstraat 5\s*\|\s*Utrecht\s*\|\s*3511 AA\s*\|\s*NL\s*\|/', $display);
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

    private function createCommand(): ListAddressesCommand
    {
        return new ListAddressesCommand(
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

    /**
     * @param string[] $street
     */
    private function createAddress(int $id, array $street, string $city, string $postcode, string $countryId): AddressInterface
    {
        $address = $this->createStub(AddressInterface::class);
        $address->method('getId')->willReturn($id);
        $address->method('getFirstname')->willReturn('Jane');
        $address->method('getLastname')->willReturn('Doe');
        $address->method('getStreet')->willReturn($street);
        $address->method('getCity')->willReturn($city);
        $address->method('getPostcode')->willReturn($postcode);
        $address->method('getCountryId')->willReturn($countryId);

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
