import {test, defineAddressBookTests} from '@yireo-customercommands/customer-commands';

/**
 * Runs the customer:address:* commands and checks their result in the address book of the Hyva theme
 *
 * See customer-address-book-test.spec.ts for Luma
 */
test.describe('Customer address commands (Hyva)', function () {
    defineAddressBookTests('hyva');
});
