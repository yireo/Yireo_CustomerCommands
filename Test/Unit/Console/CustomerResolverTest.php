<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Unit\Console;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Yireo\CustomerCommands\Console\CustomerResolver;

final class CustomerResolverTest extends TestCase
{
    private CustomerRepositoryInterface&MockObject $customerRepository;
    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
    }

    public function testAddsOptions(): void
    {
        $command = new Command('test');
        CustomerResolver::addOptions($command);

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('customer_id'));
        $this->assertTrue($definition->hasOption('customer_email'));
        $this->assertTrue($definition->hasOption('website'));
    }

    public function testFailsWithoutCustomerIdOrEmail(): void
    {
        $this->expectExceptionObject(new LocalizedException(__('Please supply either customer ID or email')));

        $this->createResolver()->resolve($this->createInput([]));
    }

    public function testFailsWithBothCustomerIdAndEmail(): void
    {
        $this->customerRepository->expects($this->never())->method('getById');
        $this->customerRepository->expects($this->never())->method('get');
        $this->expectExceptionMessage('Please supply either customer ID or email, not both');

        $this->createResolver()->resolve($this->createInput([
            '--customer_id' => '1',
            '--customer_email' => 'jane@example.com',
        ]));
    }

    #[DataProvider('getInvalidCustomerIds')]
    public function testRejectsInvalidCustomerId(string $customerId): void
    {
        $this->customerRepository->expects($this->never())->method('getById');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Invalid customer ID');

        $this->createResolver()->resolve($this->createInput(['--customer_id' => $customerId]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function getInvalidCustomerIds(): array
    {
        return [
            'trailing characters' => ['1abc'],
            'letters' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-5'],
            'decimal' => ['1.5'],
            'sql' => ['1 OR 1=1'],
        ];
    }

    public function testLoadsCustomerById(): void
    {
        $customer = $this->createCustomer(42, 1);
        $this->customerRepository->expects($this->once())->method('getById')->with(42)->willReturn($customer);

        $this->assertSame($customer, $this->createResolver()->resolve($this->createInput(['--customer_id' => '42'])));
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->customerRepository->expects($this->never())->method('get');
        $this->expectExceptionMessage('Invalid customer email "not-an-email"');

        $this->createResolver()->resolve($this->createInput(['--customer_email' => 'not-an-email']));
    }

    public function testLoadsCustomerByEmailWithoutWebsite(): void
    {
        $customer = $this->createCustomer(7, 1);
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com', null)
            ->willReturn($customer);
        $this->storeManager->expects($this->never())->method('getWebsite');

        $resolved = $this->createResolver()->resolve($this->createInput(['--customer_email' => 'jane@example.com']));

        $this->assertSame($customer, $resolved);
    }

    public function testLoadsCustomerByEmailInWebsite(): void
    {
        $customer = $this->createCustomer(7, 2);
        $this->storeManager->expects($this->once())->method('getWebsite')->with('second')
            ->willReturn($this->createWebsite(2));
        $this->customerRepository->expects($this->once())->method('get')->with('jane@example.com', 2)
            ->willReturn($customer);

        $resolved = $this->createResolver()->resolve($this->createInput([
            '--customer_email' => 'jane@example.com',
            '--website' => 'second',
        ]));

        $this->assertSame($customer, $resolved);
    }

    public function testFailsForUnknownWebsite(): void
    {
        $this->storeManager->method('getWebsite')
            ->willThrowException(new NoSuchEntityException(__('The website with code foo that was requested wasn\'t found.')));
        $this->customerRepository->expects($this->never())->method('get');
        $this->expectExceptionMessage('Unknown website "foo"');

        $this->createResolver()->resolve($this->createInput([
            '--customer_email' => 'jane@example.com',
            '--website' => 'foo',
        ]));
    }

    public function testFailsWhenCustomerIdBelongsToOtherWebsite(): void
    {
        $this->storeManager->method('getWebsite')->willReturn($this->createWebsite(2));
        $this->customerRepository->method('getById')->willReturn($this->createCustomer(42, 1));
        $this->expectExceptionMessage('Unable to load customer');

        $this->createResolver()->resolve($this->createInput(['--customer_id' => '42', '--website' => '2']));
    }

    public function testWrapsUnknownCustomer(): void
    {
        $this->customerRepository->method('getById')
            ->willThrowException(NoSuchEntityException::singleField('customerId', 999));

        try {
            $this->createResolver()->resolve($this->createInput(['--customer_id' => '999']));
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertNotInstanceOf(NoSuchEntityException::class, $e);
            $this->assertSame('Unable to load customer', $e->getMessage());
        }
    }

    public function testHasCustomerInput(): void
    {
        $resolver = $this->createResolver();

        $this->assertFalse($resolver->hasCustomerInput($this->createInput([])));
        $this->assertFalse($resolver->hasCustomerInput($this->createInput(['--customer_id' => ' '])));
        $this->assertTrue($resolver->hasCustomerInput($this->createInput(['--customer_id' => '1'])));
        $this->assertTrue($resolver->hasCustomerInput($this->createInput(['--customer_email' => 'a@example.com'])));
    }

    private function createResolver(): CustomerResolver
    {
        return new CustomerResolver($this->customerRepository, $this->storeManager);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function createInput(array $parameters): ArrayInput
    {
        $command = new Command('test');
        CustomerResolver::addOptions($command);

        return new ArrayInput($parameters, new InputDefinition($command->getDefinition()->getOptions()));
    }

    private function createCustomer(int $customerId, int $websiteId): CustomerInterface
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getWebsiteId')->willReturn($websiteId);

        return $customer;
    }

    private function createWebsite(int $websiteId): WebsiteInterface
    {
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn($websiteId);

        return $website;
    }
}
