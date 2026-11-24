<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class CustomerResolver
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function addOptions(Command $command): void
    {
        $command->addOption('customer_id', null, InputOption::VALUE_REQUIRED, 'Customer ID');
        $command->addOption('customer_email', null, InputOption::VALUE_REQUIRED, 'Customer email');
        $command->addOption('website', null, InputOption::VALUE_REQUIRED, 'Website ID or code of the customer');
    }

    public function hasCustomerInput(InputInterface $input): bool
    {
        return !$this->isEmpty($input->getOption('customer_id'))
            || !$this->isEmpty($input->getOption('customer_email'));
    }

    /**
     * @throws LocalizedException
     */
    public function resolve(InputInterface $input): CustomerInterface
    {
        $customerId = $input->getOption('customer_id');
        $customerEmail = $input->getOption('customer_email');

        if ($this->isEmpty($customerId) && $this->isEmpty($customerEmail)) {
            throw new LocalizedException(__('Please supply either customer ID or email'));
        }

        if (!$this->isEmpty($customerId) && !$this->isEmpty($customerEmail)) {
            throw new LocalizedException(__('Please supply either customer ID or email, not both'));
        }

        $websiteId = $this->getWebsiteId($input->getOption('website'));

        try {
            $customer = $this->isEmpty($customerId)
                ? $this->getByEmail((string)$customerEmail, $websiteId)
                : $this->getById((string)$customerId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Unable to load customer'));
        }

        if ($websiteId !== null && (int)$customer->getWebsiteId() !== $websiteId) {
            throw new LocalizedException(__('Unable to load customer'));
        }

        return $customer;
    }

    /**
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function getById(string $customerId): CustomerInterface
    {
        $validCustomerId = filter_var($customerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validCustomerId === false) {
            throw new LocalizedException(__('Invalid customer ID "%1"', $customerId));
        }

        return $this->customerRepository->getById($validCustomerId);
    }

    /**
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function getByEmail(string $customerEmail, ?int $websiteId): CustomerInterface
    {
        if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(__('Invalid customer email "%1"', $customerEmail));
        }

        return $this->customerRepository->get($customerEmail, $websiteId);
    }

    /**
     * @throws LocalizedException
     */
    private function getWebsiteId(mixed $website): ?int
    {
        if ($this->isEmpty($website)) {
            return null;
        }

        try {
            return (int)$this->storeManager->getWebsite((string)$website)->getId();
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('Unknown website "%1"', (string)$website));
        }
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === false || trim((string)$value, " \f\n\r\t\v\x00") === '';
    }
}
