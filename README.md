# PayFast Gateway

This is a nonmerchant gateway for Blesta that integrates with [PayFast](https://gopayfast.com/).

## Install the Gateway

1. You can install the gateway via composer:

    ```
    composer require blesta/gopayfast
    ```

2. Upload the source code to a /components/gateways/nonmerchant/gopayfast/ directory within
your Blesta installation path.

    For example:

    ```
    /var/www/html/blesta/components/nonmerchant/gopayfast/
    ```

3. Log in to your admin Blesta account and navigate to
> Settings > Payment Gateways

4. Find the PayFast gateway and click the "Install" button to install it

5. You're done!

### Settings

|Setting|Description|
|-------|-----------|
|Merchant ID|Issued by PayFast when you sign up|
|Merchant Name|Must match the name registered with PayFast. It forms part of the payment signature, so a mismatch causes every transaction to be rejected|
|Secured Key|Issued by PayFast when you sign up. Stored encrypted|
|Sandbox|Routes payments to the PayFast UAT environment for testing|

### Currencies

PayFast processes payments in PKR only.

### Blesta Compatibility

|Blesta Version|Gateway Version|
|--------------|---------------|
|>= v6.0.0|v1.0.0|
