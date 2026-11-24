<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\RegionInterface;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Yireo\CustomerCommands\Console\Command\AddAddressCommand;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;

final class AddAddressCommandTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private AddressRepositoryInterface&MockObject $addressRepository;
    private AddressInterface&MockObject $address;
    private RegionCollectionFactory&MockObject $regionCollectionFactory;
    private RegionInterfaceFactory&MockObject $regionFactory;
    private State&MockObject $state;
    private ?AddressInterfaceFactory $addressFactory = null;
    private ?string $areaCode = null;

    /**
     * @var array<string, mixed>
     */
    private array $addressData = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $regionFilters = [];

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->addressRepository = $this->createMock(AddressRepositoryInterface::class);
        $this->regionCollectionFactory = $this->createMock(RegionCollectionFactory::class);
        $this->regionFactory = $this->createMock(RegionInterfaceFactory::class);
        $this->state = $this->createMock(State::class);
        $this->areaCode = null;
        $this->state->method('emulateAreaCode')
            ->willReturnCallback(function (string $areaCode, callable $callback) {
                $this->areaCode = $areaCode;
                $result = $callback();
                $this->areaCode = null;
                return $result;
            });

        $this->addressData = [];
        $this->regionFilters = [];
        $this->addressFactory = null;
        $this->address = $this->createMock(AddressInterface::class);
        $setters = [
            'setCustomerId', 'setFirstname', 'setLastname', 'setStreet', 'setCity', 'setPostcode',
            'setCountryId', 'setTelephone', 'setCompany', 'setRegionId', 'setRegion',
            'setIsDefaultShipping', 'setIsDefaultBilling',
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

    public function testRejectsBothCustomerIdAndEmail(): void
    {
        $this->addressRepository->expects($this->never())->method('save');

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute(['--customer_id' => '1', '--customer_email' => 'jane@example.com']);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('not both', $commandTester->getDisplay());
    }

    public function testAddsAddressWithAllOptions(): void
    {
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com')
            ->willReturn($this->createCustomer());
        $this->setRegionCollections([$this->createRegionCollection(57)]);
        $this->expectAddressToBeSaved(31);

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute([
            '--customer_email' => 'jane@example.com',
            '--firstname' => ' Jane ',
            '--lastname' => 'Doe',
            '--street' => 'Main Street 1',
            '--city' => 'Austin',
            '--postcode' => '78701',
            '--region' => '57',
            '--country' => 'us',
            '--telephone' => '0123456789',
            '--company' => 'Yireo',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode, $commandTester->getDisplay());
        $this->assertSame([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street' => ['Main Street 1'],
            'city' => 'Austin',
            'postcode' => '78701',
            'countryId' => 'US',
            'telephone' => '0123456789',
            'company' => 'Yireo',
            'regionId' => 57,
            'customerId' => 42,
        ], $this->addressData);
        $this->assertSame(
            [['country_id' => ['eq' => 'US'], 'main_table.region_id' => ['eq' => '57']]],
            $this->regionFilters
        );
        $this->assertStringContainsString(
            'Successfully added address (ID: 31) to customer jane@example.com (42).',
            $commandTester->getDisplay()
        );
    }

    public function testRejectsRegionIdOfOtherCountry(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->setRegionCollections([$this->createRegionCollection(0)]);
        $this->addressRepository->expects($this->never())->method('save');
        $this->setAddressFactory();

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute(['--customer_id' => '42', '--region' => '57', '--country' => 'NL']);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Region ID 57 does not exist for country NL', $commandTester->getDisplay());
    }

    public function testRequiresCountryForRegion(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->addressRepository->expects($this->never())->method('save');
        $this->setAddressFactory();

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42', '--region' => 'Texas']));
        $this->assertStringContainsString('Please supply a country when supplying a region', $commandTester->getDisplay());
    }

    public function testLooksUpRegionIdByName(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->setRegionCollections([$this->createRegionCollection(57)]);
        $this->expectAddressToBeSaved();

        $statusCode = $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--region' => 'Texas',
            '--country' => 'US',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertSame([['country_id' => ['eq' => 'US'], 'default_name' => ['eq' => 'Texas']]], $this->regionFilters);
        $this->assertSame(57, $this->addressData['regionId']);
    }

    public function testRejectsUnknownRegionNameForCountryWithRegions(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->setRegionCollections([$this->createRegionCollection(0), $this->createRegionCollection(0, 65)]);
        $this->addressRepository->expects($this->never())->method('save');
        $this->setAddressFactory();

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute(['--customer_id' => '42', '--region' => 'Atlantis', '--country' => 'US']);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString('Unknown region "Atlantis" for country US', $commandTester->getDisplay());
    }

    public function testKeepsRegionNameAsTextForCountryWithoutRegions(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->setRegionCollections([$this->createRegionCollection(0), $this->createRegionCollection(0, 0)]);
        $this->expectAddressToBeSaved();

        $region = $this->createMock(RegionInterface::class);
        $region->expects($this->once())->method('setRegion')->with('Utrecht')->willReturnSelf();
        $this->regionFactory->method('create')->willReturn($region);

        $statusCode = $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--region' => 'Utrecht',
            '--country' => 'NL',
        ]);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('regionId', $this->addressData);
        $this->assertSame($region, $this->addressData['region']);
    }

    public function testAddsAddressWithoutRegion(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->regionCollectionFactory->expects($this->never())->method('create');
        $this->expectAddressToBeSaved();

        $statusCode = $this->createCommandTester()->execute(['--customer_id' => '42', '--country' => 'NL']);

        $this->assertSame(Command::SUCCESS, $statusCode);
        $this->assertArrayNotHasKey('regionId', $this->addressData);
        $this->assertSame([], $this->addressData['street']);
    }

    public function testRejectsInvalidCountryId(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->addressRepository->expects($this->never())->method('save');
        $this->setAddressFactory();

        $commandTester = $this->createCommandTester();

        $this->assertSame(Command::FAILURE, $commandTester->execute(['--customer_id' => '42', '--country' => 'NLD']));
        $this->assertStringContainsString('Invalid country ID "NLD"', $commandTester->getDisplay());
    }

    public function testRejectsTooLongValues(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->addressRepository->expects($this->never())->method('save');
        $this->setAddressFactory();

        $commandTester = $this->createCommandTester();
        $statusCode = $commandTester->execute([
            '--customer_id' => '42',
            '--country' => 'NL',
            '--city' => str_repeat('a', 256),
        ]);

        $this->assertSame(Command::FAILURE, $statusCode);
        $this->assertStringContainsString(
            'Option --city must not be longer than 255 characters',
            $commandTester->getDisplay()
        );
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
            '--default-billing' => true,
            '--default-shipping' => true,
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
            '--default-billing' => true,
        ]);

        $this->assertTrue($this->addressData['isDefaultBilling']);
        $this->assertArrayNotHasKey('isDefaultShipping', $this->addressData);
    }

    public function testDefaultFlagsDoNotAcceptValuesOnCommandLine(): void
    {
        $this->addressRepository->expects($this->never())->method('save');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The "--default-billing" option does not accept a value.');

        $this->createCommand()->run(
            new ArgvInput(['bin/magento', '--customer_id=42', '--default-billing=0']),
            new BufferedOutput()
        );
    }

    public function testIgnoresNonBooleanDefaultFlagValues(): void
    {
        $this->customerRepository->method('getById')->willReturn($this->createCustomer());
        $this->expectAddressToBeSaved();

        $this->createCommandTester()->execute([
            '--customer_id' => '42',
            '--country' => 'NL',
            '--default-billing' => '0',
            '--default-shipping' => 'no',
        ]);

        $this->assertArrayNotHasKey('isDefaultBilling', $this->addressData);
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

    public function testEscapesCustomerEmailInOutput(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getEmail')->willReturn("<href=https://evil.test>x</>\x1b[2J@example.com");
        $this->customerRepository->method('getById')->willReturn($customer);
        $this->expectAddressToBeSaved(31);

        $commandTester = $this->createCommandTester();
        $commandTester->execute(['--customer_id' => '42', '--country' => 'NL'], ['decorated' => true]);

        $display = $commandTester->getDisplay();
        $this->assertStringContainsString('<href=https://evil.test>x</>@example.com', $display);
        $this->assertStringNotContainsString("\x1b[2J", $display);
        $this->assertStringNotContainsString("\x1b]8;", $display);
    }

    public function testRunsInAdminhtmlArea(): void
    {
        $this->customerRepository->method('getById')
            ->willReturnCallback(function () {
                $this->assertSame('adminhtml', $this->areaCode, 'Customer must be loaded within the emulated area');
                return $this->createCustomer();
            });
        $this->expectAddressToBeSaved();

        $this->assertSame(
            Command::SUCCESS,
            $this->createCommandTester()->execute(['--customer_id' => '42', '--country' => 'NL'])
        );
    }

    private function createCommand(?AddressInterfaceFactory $addressFactory = null): AddAddressCommand
    {
        return new AddAddressCommand(
            $this->addressRepository,
            $addressFactory ?? $this->createMock(AddressInterfaceFactory::class),
            $this->regionFactory,
            $this->regionCollectionFactory,
            new CustomerResolver($this->customerRepository, $this->createStub(StoreManagerInterface::class)),
            new OutputSanitizer(),
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

    private function createRegionCollection(int $firstItemId, int $size = 0): RegionCollection
    {
        $index = count($this->regionFilters);
        $this->regionFilters[$index] = [];

        $regionCollection = $this->createMock(RegionCollection::class);
        $regionCollection->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, array $condition) use ($index, $regionCollection) {
                $this->regionFilters[$index][$field] = $condition;
                return $regionCollection;
            });
        $regionCollection->method('getFirstItem')
            ->willReturn(new DataObject($firstItemId > 0 ? ['id' => $firstItemId] : []));
        $regionCollection->method('getSize')->willReturn($size);

        return $regionCollection;
    }

    /**
     * @param RegionCollection[] $collections
     */
    private function setRegionCollections(array $collections): void
    {
        $this->regionCollectionFactory->expects($this->exactly(count($collections)))->method('create')
            ->willReturnOnConsecutiveCalls(...$collections);
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
