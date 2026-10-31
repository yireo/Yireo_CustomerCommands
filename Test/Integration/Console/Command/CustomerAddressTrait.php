<?php

declare(strict_types=1);

namespace Yireo\CustomerCommands\Test\Integration\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AddressRegistry;
use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Symfony\Component\Console\Tester\CommandTester;

trait CustomerAddressTrait
{
    /**
     * @param class-string $commandClass
     * @param array<string, mixed> $input
     */
    private function runCommand(string $commandClass, array $input): CommandTester
    {
        $command = Bootstrap::getObjectManager()->create($commandClass);
        $commandTester = new CommandTester($command);
        $commandTester->execute($input);

        return $commandTester;
    }

    private function getFixtureCustomer(string $name = 'customer'): CustomerInterface
    {
        $customerId = (int)DataFixtureStorageManager::getStorage()->get($name)->getId();

        return $this->reloadCustomer($customerId);
    }

    private function reloadCustomer(int $customerId): CustomerInterface
    {
        Bootstrap::getObjectManager()->get(CustomerRegistry::class)->remove($customerId);

        return $this->getCustomerRepository()->getById($customerId);
    }

    /**
     * @return AddressInterface[]
     */
    private function getAddresses(int $customerId): array
    {
        $searchCriteriaBuilder = Bootstrap::getObjectManager()->create(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->addFilter('parent_id', $customerId);

        return array_values($this->getAddressRepository()->getList($searchCriteriaBuilder->create())->getItems());
    }

    private function addressExists(int $addressId): bool
    {
        Bootstrap::getObjectManager()->get(AddressRegistry::class)->remove($addressId);

        try {
            $this->getAddressRepository()->getById($addressId);
            return true;
        } catch (NoSuchEntityException $e) {
            return false;
        }
    }

    private function getCustomerRepository(): CustomerRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(CustomerRepositoryInterface::class);
    }

    private function getAddressRepository(): AddressRepositoryInterface
    {
        return Bootstrap::getObjectManager()->get(AddressRepositoryInterface::class);
    }
}
