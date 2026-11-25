# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 25 November 2026
### Added
- Confirmation prompt for `customer:address:remove` and `customer:address:remove-all`, with a `--force` option to skip it
- `--dry-run` option for `customer:address:remove-all`
- `--website` option to look up a customer by email within a specific website

### Changed
- BREAKING: the address removal commands refuse to remove anything in non-interactive mode unless `--force` is given
- `customer:address:remove-all` exits with a failure code when some addresses could not be removed
- `--default-billing` and `--default-shipping` of `customer:address:add` are now flags without a value
- Passing both `--customer_id` and `--customer_email` is rejected

### Removed
- PHP 8.1 support (PHP 8.2 up to 8.5 is supported)

### Fixed
- Installing Magento with this module enabled failed with "Table eav_entity_type doesn't exist"
- Crash "Area code is not set" in the list, add and remove-all commands for customers with addresses
- An unknown customer ID or email reports "Unable to load customer" instead of throwing an exception
- `customer:address:add` no longer crashes when `--region` is omitted
- Compatibility with `symfony/console` v5

### Security
- Stored address data and error messages are escaped before being written to the terminal, preventing terminal escape sequence injection
- Strict validation of customer, country code, region, address ID and option lengths; `customer:address:remove` checks that the address belongs to the given customer

## [1.0.1] - 08 April 2026
### Fixed
- PHP 8.4 compat

## [1.0.0] - 07 January 2026
### Fixed
- Finalize commands
- Add address as well
- Fix wrong check
- Allow by email; Output with table
- Adding all files to git via Yireo Command
