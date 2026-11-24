<?php
declare(strict_types=1);

namespace Yireo\CustomerCommands\Console\Command;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yireo\CustomerCommands\Console\CustomerResolver;
use Yireo\CustomerCommands\Console\OutputSanitizer;
use Yireo\CustomerCommands\Console\RemovalConfirmation;

class RemoveAddressCommand extends Command
{
    public function __construct(
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly CustomerResolver $customerResolver,
        private readonly RemovalConfirmation $removalConfirmation,
        private readonly OutputSanitizer $outputSanitizer,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('customer:address:remove');
        $this->setDescription('Remove a customer address by ID');
        $this->addArgument('address_id', InputArgument::REQUIRED, 'Address ID');
        $this->customerResolver->addOptions($this);
        $this->removalConfirmation->addOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return (int)$this->state->emulateAreaCode(
            Area::AREA_ADMINHTML,
            fn () => $this->removeAddress($input, $output)
        );
    }

    private function removeAddress(InputInterface $input, OutputInterface $output): int
    {
        $addressId = filter_var(
            $input->getArgument('address_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($addressId === false) {
            $output->writeln(sprintf(
                '<error>Invalid address ID "%s"</error>',
                $this->outputSanitizer->sanitize($input->getArgument('address_id'))
            ));
            return Command::FAILURE;
        }

        try {
            $address = $this->addressRepository->getById($addressId);

            if ($this->customerResolver->hasCustomerInput($input)) {
                $customer = $this->customerResolver->resolve($input);
                if ((int)$address->getCustomerId() !== (int)$customer->getId()) {
                    $output->writeln(sprintf(
                        '<error>Address with ID %d does not belong to customer ID %d.</error>',
                        $addressId,
                        $customer->getId()
                    ));
                    return Command::FAILURE;
                }
            }

            $question = sprintf(
                'Remove address with ID %d (%s) of customer ID %d?',
                $addressId,
                $this->getAddressSummary($address),
                $address->getCustomerId()
            );
            if (!$this->removalConfirmation->isConfirmed($input, $output, $question)) {
                return Command::FAILURE;
            }

            $this->addressRepository->deleteById($addressId);
            $output->writeln(sprintf('<info>Address with ID %d has been removed.</info>', $addressId));
        } catch (NoSuchEntityException $e) {
            $output->writeln(sprintf('<error>Address with ID %d does not exist.</error>', $addressId));
            return Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln(sprintf(
                '<error>An error occurred: %s</error>',
                $this->outputSanitizer->sanitize($e->getMessage())
            ));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getAddressSummary(AddressInterface $address): string
    {
        $parts = array_filter([
            implode(', ', $address->getStreet() ?? []),
            (string)$address->getCity(),
            (string)$address->getCountryId(),
        ]);

        return $this->outputSanitizer->sanitize(implode(', ', $parts));
    }
}
