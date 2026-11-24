<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;

class AddAddressCommand extends Command
{
    private const MAX_LENGTH = 255;

    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory,
        private readonly RegionCollectionFactory $regionCollectionFactory,
        private readonly CustomerResolver $customerResolver,
        private readonly OutputSanitizer $outputSanitizer,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('customer:address:add');
        $this->setDescription('Add a new address to a customer');
        CustomerResolver::addOptions($this);
        $this->addOption('firstname', null, InputOption::VALUE_REQUIRED, 'First Name');
        $this->addOption('lastname', null, InputOption::VALUE_REQUIRED, 'Last Name');
        $this->addOption('street', null, InputOption::VALUE_REQUIRED, 'Street');
        $this->addOption('city', null, InputOption::VALUE_REQUIRED, 'City');
        $this->addOption('postcode', null, InputOption::VALUE_REQUIRED, 'Postcode');
        $this->addOption('region', null, InputOption::VALUE_REQUIRED, 'Region ID or name');
        $this->addOption('country', null, InputOption::VALUE_REQUIRED, 'Country ID (ISO 3166-1 alpha-2)');
        $this->addOption('telephone', null, InputOption::VALUE_REQUIRED, 'Telephone');
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Company');
        $this->addOption('default-billing', null, InputOption::VALUE_NONE, 'Make this the default billing address');
        $this->addOption('default-shipping', null, InputOption::VALUE_NONE, 'Make this the default shipping address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int)$this->state->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn () => $this->addAddress($input, $output)
        );
    }

    private function addAddress(InputInterface $input, OutputInterface $output): int
    {
        try {
            $customer = $this->customerResolver->resolve($input);
            $address = $this->createAddress($input);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $this->outputSanitizer->sanitize($e->getMessage()) . '</error>');
            return Command::FAILURE;
        }

        try {
            $address->setCustomerId($customer->getId());
            $savedAddress = $this->addressRepository->save($address);
            $output->writeln(sprintf(
                '<info>Successfully added address (ID: %d) to customer %s (%d).</info>',
                $savedAddress->getId(),
                $this->outputSanitizer->sanitize($customer->getEmail()),
                $customer->getId()
            ));
        } catch (\Exception $e) {
            $output->writeln(sprintf(
                '<error>Error saving address: %s</error>',
                $this->outputSanitizer->sanitize($e->getMessage())
            ));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @throws LocalizedException
     */
    private function createAddress(InputInterface $input): AddressInterface
    {
        $countryId = $this->getCountryId($input);
        $street = $this->getStringOption($input, 'street');

        $address = $this->addressFactory->create();
        $address->setFirstname($this->getStringOption($input, 'firstname'));
        $address->setLastname($this->getStringOption($input, 'lastname'));
        $address->setStreet($street === null ? [] : [$street]);
        $address->setCity($this->getStringOption($input, 'city'));
        $address->setPostcode($this->getStringOption($input, 'postcode'));
        $address->setCountryId($countryId);
        $address->setTelephone($this->getStringOption($input, 'telephone'));
        $address->setCompany($this->getStringOption($input, 'company'));

        $region = $this->getStringOption($input, 'region');
        if ($region !== null) {
            $this->setRegion($address, $region, $countryId);
        }

        if ($input->getOption('default-shipping') === true) {
            $address->setIsDefaultShipping(true);
        }

        if ($input->getOption('default-billing') === true) {
            $address->setIsDefaultBilling(true);
        }

        return $address;
    }

    /**
     * @throws LocalizedException
     */
    private function getStringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value, " \f\n\r\t\v\x00");
        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new LocalizedException(
                __('Option --%1 must not be longer than %2 characters', $name, self::MAX_LENGTH)
            );
        }

        return $value;
    }

    /**
     * @throws LocalizedException
     */
    private function getCountryId(InputInterface $input): ?string
    {
        $countryId = $this->getStringOption($input, 'country');
        if ($countryId === null) {
            return null;
        }

        $countryId = strtoupper($countryId);
        if (preg_match('/^[A-Z]{2}$/', $countryId) !== 1) {
            throw new LocalizedException(
                __('Invalid country ID "%1": Use a two-letter ISO 3166-1 code like "NL"', $countryId)
            );
        }

        return $countryId;
    }

    /**
     * @throws LocalizedException
     */
    private function setRegion(AddressInterface $address, string $region, ?string $countryId): void
    {
        if ($countryId === null) {
            throw new LocalizedException(__('Please supply a country when supplying a region'));
        }

        if (ctype_digit($region)) {
            $regionId = $this->findRegionId($countryId, 'main_table.region_id', $region);
            if ($regionId === 0) {
                throw new LocalizedException(
                    __('Region ID %1 does not exist for country %2', $region, $countryId)
                );
            }

            $address->setRegionId($regionId);
            return;
        }

        $regionId = $this->findRegionId($countryId, 'default_name', $region);
        if ($regionId > 0) {
            $address->setRegionId($regionId);
            return;
        }

        if ($this->hasRegions($countryId)) {
            throw new LocalizedException(__('Unknown region "%1" for country %2', $region, $countryId));
        }

        $address->setRegion($this->regionFactory->create()->setRegion($region));
    }

    private function findRegionId(string $countryId, string $field, string $value): int
    {
        $regionCollection = $this->regionCollectionFactory->create();
        $regionCollection->addFieldToFilter('country_id', ['eq' => $countryId]);
        $regionCollection->addFieldToFilter($field, ['eq' => $value]);

        return (int)$regionCollection->getFirstItem()->getId();
    }

    private function hasRegions(string $countryId): bool
    {
        $regionCollection = $this->regionCollectionFactory->create();
        $regionCollection->addFieldToFilter('country_id', ['eq' => $countryId]);

        return $regionCollection->getSize() > 0;
    }
}
