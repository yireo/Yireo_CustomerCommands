<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

class AddAddressCommand extends Command
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly State $state,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('customer:address:add');
        $this->setDescription('Add a new address to a customer');
        $this->addOption('customer_id', null, InputOption::VALUE_OPTIONAL, 'Customer ID');
        $this->addOption('customer_email', null, InputOption::VALUE_OPTIONAL, 'Customer email');
        $this->addOption('firstname', null, InputOption::VALUE_OPTIONAL, 'First Name');
        $this->addOption('lastname', null, InputOption::VALUE_OPTIONAL, 'Last Name');
        $this->addOption('street', null, InputOption::VALUE_OPTIONAL, 'Street');
        $this->addOption('city', null, InputOption::VALUE_OPTIONAL, 'City');
        $this->addOption('postcode', null, InputOption::VALUE_OPTIONAL, 'Postcode');
        $this->addOption('region', null, InputOption::VALUE_OPTIONAL, 'Region');
        $this->addOption('country', null, InputOption::VALUE_OPTIONAL, 'Country ID');
        $this->addOption('telephone', null, InputOption::VALUE_OPTIONAL, 'Telephone');
        $this->addOption('company', null, InputOption::VALUE_OPTIONAL, 'Company');
        $this->addOption('default-billing', null, InputOption::VALUE_OPTIONAL, 'Default Billing');
        $this->addOption('default-shipping', null, InputOption::VALUE_OPTIONAL, 'Default Shipping');
    }

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

        $this->state->setAreaCode('frontend');

        try {
            $address = $this->addressFactory->create();
            $address->setCustomerId($customer->getId());
            $address->setFirstname($input->getOption('firstname'));
            $address->setLastname($input->getOption('lastname'));
            $address->setStreet([$input->getOption('street')]);
            $address->setCity($input->getOption('city'));
            $address->setPostcode($input->getOption('postcode'));
            $address->setCountryId($input->getOption('country'));
            $address->setTelephone($input->getOption('telephone'));
            $address->setCompany($input->getOption('company'));

            $region = $input->getOption('region');
            if (is_numeric($region)) {
                $address->setRegionId($region);
            } else {
                $regionId = $this->getRegionId($input->getOption('country'), $region);
                if ($regionId > 0) {
                    $address->setRegionId($regionId);
                }
            }

            if ($input->hasOption('default-shipping')) {
                $address->setIsDefaultShipping(true);
            }

            if ($input->hasOption('default-billing')) {
                $address->setIsDefaultBilling(true);
            }

            $savedAddress = $this->addressRepository->save($address);
            $output->writeln(sprintf(
                '<info>Successfully added address (ID: %d) to customer %s (%d).</info>',
                $savedAddress->getId(),
                $customer->getEmail(),
                $customer->getId()
            ));
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error saving address: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getRegionId(string $countryId, string $regionName): int
    {
        $regionCollection = $this->regionCollectionFactory->create();
        $regionCollection->addFieldToFilter('country_id', ['eq' => $countryId]);
        $regionCollection->addFieldToFilter('default_name', ['eq' => $regionName]);
        $regionId = (int)$regionCollection->getFirstItem()->getId();

        return $regionId;
    }
}
