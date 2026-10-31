import {test, defineAddressBookTests} from '@yireo-customercommands/customer-commands';

/**
 * Runs the customer:address:* commands and checks their result in the address book of the Luma theme
 *
 * See customer-address-book-hyva-test.spec.ts for Hyva
 */
test.describe('Customer address commands (Luma)', function () {
    defineAddressBookTests('luma');
});
