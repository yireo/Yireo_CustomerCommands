<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\AddAddressCommand;

final class AddAddressCommandTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private AddressRepositoryInterface&MockObject $addressRepository;
    private AddressInterface&MockObject $address;
    private RegionCollectionFactory&MockObject $regionCollectionFactory;
    private State&MockObject $state;
    private ?AddressInterfaceFactory $addressFactory = null;

    /**
     * @var array<string, mixed>
     */
    private array $addressData = [];

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
        $this->regionCollectionFactory = $this->createMock(RegionCollectionFactory::class);
        $this->state = $this->createMock(State::class);

        $this->addressData = [];
        $this->addressFactory = null;
        $this->address = $this->createMock(AddressInterface::class);
        $setters = [
            'setCustomerId', 'setFirstname', 'setLastname', 'setStreet', 'setCity', 'setPostcode',
            'setCountryId', 'setTelephone', 'setCompany', 'setRegionId', 'setIsDefaultShipping', 'setIsDefaultBilling',
        ];
        foreach ($setters as $setter) {
            $this->address->method($setter)->willReturnCallback(function (mixed $value) use ($setter) {
                $this->addressData[lcfirst(substr($setter, 3))] = $value;
                return $this->address;
            });
        }
    }

    public function testCommandName(): void
    {
        $this->assertSame('customer:address:add', $this->createCommand()->getName());
    }

    public function testFailsWithoutCustomerIdOrEmail(): void
    {
        $this->addressRepository->expects($this->never())->method('save');

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute([]));
        $this->assertStringContainsString('Please supply either customer ID or email', $commandTester->getDisplay());
    }

    public function testFailsForUnknownCustomerId(): void
    {
        $this->customerRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('customerId', 999));
        $this->addressRepository->expects($this->never())->method('save');

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

    public function testAddsAddressWithAllOptions(): void
    {
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com')
            ->willReturn($this->createCustomer());
        $this->state->expects($this->once())->method('setAreaCode')->with('frontend');
        $this->expectAddressToBeSaved(31);

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute([
            '--customer_email' => 'jane@example.com',
            '--firstname' => 'Jane',
            '--lastname' => 'Doe',
            '--street' => 'Main Street 1',
            '--city' => 'Austin',
            '--postcode' => '78701',
            '--region' => '57',
            '--country' => 'US',
            '--telephone' => '0123456789',
            '--company' => 'Yireo',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame([
            'customerId' => 42,
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street' => ['Main Street 1'],
            'city' => 'Austin',
            'postcode' => '78701',
            'countryId' => 'US',
            'telephone' => '0123456789',
            'company' => 'Yireo',
            'regionId' => 57,
        ], $this->addressData);
        $this->assertStringContainsString(
            'Successfully added address (ID: 31) to customer jane@example.com (42).',
            $commandTester->getDisplay()
        );
    }

    public function testLooksUpRegionIdByName(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $filters = [];
        $regionCollection = $this->createMock(RegionCollection::class);
        $regionCollection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, array $condition) use (&$filters, $regionCollection) {
                $filters[$field] = $condition;
                return $regionCollection;
            });
        $regionCollection->method('getFirstItem')->willReturn(new DataObject(['id' => 57]));
        $this->regionCollectionFactory->expects($this->once())->method('create')->willReturn($regionCollection);

        $statusCode = $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--region' => 'Texas',
            '--country' => 'US',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame(['country_id' => ['eq' => 'US'], 'default_name' => ['eq' => 'Texas']], $filters);
        $this->assertSame(57, $this->addressData['regionId']);
    }

    public function testSkipsUnknownRegionName(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $regionCollection = $this->createMock(RegionCollection::class);
        $regionCollection->method('addFieldToFilter')->willReturnSelf();
        $regionCollection->method('getFirstItem')->willReturn(new DataObject());
        $this->regionCollectionFactory->method('create')->willReturn($regionCollection);

        $statusCode = $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--region' => 'Atlantis',
            '--country' => 'US',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('regionId', $this->addressData);
    }

    public function testAddsAddressWithoutRegion(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->regionCollectionFactory->expects($this->never())->method('create');
        $this->expectAddressToBeSaved();

        $statusCode = $this->createCommandTester()->execute(['--customer_id' => '42', '--country' => 'NL']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('regionId', $this->addressData);
    }

    public function testDoesNotSetDefaultsWithoutFlags(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $this->createCommandTester()->execute(['--customer_id' => '42', '--country' => 'NL']);

        $this->assertArrayNotHasKey('isDefaultBilling', $this->addressData);
        $this->assertArrayNotHasKey('isDefaultShipping', $this->addressData);
    }

    public function testSetsDefaultsWithFlags(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--country' => 'NL',
            '--default-billing' => null,
            '--default-shipping' => '1',
        ]);

        $this->assertTrue($this->addressData['isDefaultBilling']);
        $this->assertTrue($this->addressData['isDefaultShipping']);
    }

    public function testSetsOnlyDefaultBillingFlag(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--country' => 'NL',
            '--default-billing' => null,
        ]);

        $this->assertTrue($this->addressData['isDefaultBilling']);
        $this->assertArrayNotHasKey('isDefaultShipping', $this->addressData);
    }

    public function testFailsWhenSavingFails(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->setAddressFactory();
        $this->addressRepository->method('save')
            ->willThrowException(InputException::requiredField('firstname'));

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42', '--country' => 'NL']));
        $this->assertStringContainsString('Error saving address: "firstname" is required.', $commandTester->getDisplay());
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
                return $this->createCustomer();
            });
        $this->expectAddressToBeSaved();

        $this->createCommandTester()->execute(['--customer_id' => '42', '--country' => 'NL']);
    }

    private function createCommand(?AddressInterfaceFactory $addressFactory = null): AddAddressCommand
    {
        return new AddAddressCommand(
            $this->customerRepository,
            $this->addressRepository,
            $addressFactory ?? $this->createMock(AddressInterfaceFactory::class),
            $this->regionCollectionFactory,
            $this->state
        );
    }

    private function createCommandTester(): CommandTester
    {
        return new CommandTester($this->createCommand($this->addressFactory));
    }

    private function createCustomer(): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn('jane@example.com');

        return $customer;
    }

    private function setAddressFactory(): void
    {
        $addressFactory = $this->createMock(AddressInterfaceFactory::class);
        $addressFactory->method('create')->willReturn($this->address);
        $this->addressFactory = $addressFactory;
    }

    private function expectAddressToBeSaved(int $addressId = 1): void
    {
        $this->setAddressFactory();

        $savedAddress = $this->createStub(AddressInterface::class);
        $savedAddress->method('getId')->willReturn($addressId);
        $this->addressRepository->expects($this->once())->method('save')->with($this->address)
            ->willReturn($savedAddress);
    }
}
