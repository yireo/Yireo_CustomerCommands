<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListAddressesCommand extends Command
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private AddressRepositoryInterface $addressRepository,
        private SearchCriteriaBuilder $searchCriteriaBuilder,
        string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('customer:address:list');
        $this->setDescription('List addresses of a customer');
        $this->addOption('customer_id', null, InputOption::VALUE_OPTIONAL, 'Customer ID');
        $this->addOption('customer_email', null, InputOption::VALUE_OPTIONAL, 'Customer email');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getOption('customer_id');
        $customerEmail = $input->getOption('customer_email');
        if (empty($customerId) && empty($customerEmail)) {
            $output->writeln('<error>Please supply either customer ID or email</error>');
            return Command::FAILURE;
        }

        $customer = null;
        if ($customerId > 0) {
            $customer = $this->customerRepository->getById($customerId);
        }

        if (!empty($customerEmail)) {
            $customer = $this->customerRepository->get($customerEmail);
        }

        if (empty($customer)) {
            $output->writeln('<error>Unable to load customer</error>');
            return Command::FAILURE;
        }


        $this->searchCriteriaBuilder->addFilter('parent_id', $customer->getId());
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $addresses = $this->addressRepository->getList($searchCriteria)->getItems();

        if (empty($addresses)) {
            $output->writeln('<info>No addresses found for this customer.</info>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'ID',
            'First name',
            'Last name',
            'Street address',
            'City',
            'Postcode',
            'Country'
        ]);

        foreach ($addresses as $address) {
            $table->addRow([
                $address->getId(),
                $address->getFirstname(),
                $address->getLastname(),
                implode(', ', $address->getStreet()),
                $address->getCity(),
                $address->getPostcode(),
                $address->getCountryId(),
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
