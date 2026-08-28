<?php
/**
 * PayFast Gateway
 *
 * Hosted checkout for PayFast (Pakistan), in PKR. A payment is a two step flow: a one
 * time access token is requested from GetAccessToken, then the client's browser posts
 * the payment to PostTransaction carrying that token. The basket ID and amount must be
 * identical across both calls or PayFast rejects the transaction.
 *
 * The callback that reports the outcome is not trusted. It only names a basket ID, which
 * is then looked up against PayFast over an authenticated server to server call, and the
 * status, amount, currency and transaction ID all come from that record. Anything that
 * cannot be verified is rejected rather than recorded.
 *
 * Two details were derived from PayFast's merchant integration guide and working live
 * integrations rather than the official reference, which is behind a bot check, and
 * should be confirmed before this handles production traffic:
 *
 *  - Success is taken to be an err_code of 000, 00 or 0.
 *  - The API base for the verification lookup is assumed to share the payment endpoints'
 *    base, giving {host}/Ecommerce/api/transaction/basket_id/{id}. It is isolated in
 *    getApiBaseUrl(). A wrong base rejects payments rather than approving bad ones.
 *
 * API documentation: https://gopayfast.com/docs/
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.gopayfast
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Gopayfast extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * @var array The API hosts, keyed by environment
     */
    private $api_hosts = [
        'live' => 'https://ipg1.apps.net.pk',
        'sandbox' => 'https://ipguat.apps.net.pk'
    ];

    /**
     * @var array Response codes PayFast returns for a successful transaction
     */
    private $success_codes = ['000', '00', '0'];

    /**
     * Construct a new nonmerchant gateway
     */
    public function __construct()
    {
        // Load configuration required by this gateway
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this gateway
        Language::loadLang('gopayfast', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the meta data for this particular gateway
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'gopayfast' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'merchant_id' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Gopayfast.!error.merchant_id.valid', true)
                ]
            ],
            'merchant_name' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Gopayfast.!error.merchant_name.valid', true)
                ]
            ],
            'secured_key' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Gopayfast.!error.secured_key.valid', true)
                ]
            ],
            'sandbox' => [
                'valid' => [
                    'rule' => ['in_array', ['true', 'false']],
                    'message' => Language::_('Gopayfast.!error.sandbox.valid', true)
                ]
            ]
        ];
        $this->Input->setRules($rules);

        // Set unset checkboxes
        $checkbox_fields = ['sandbox'];
        foreach ($checkbox_fields as $checkbox_field) {
            if (!isset($meta[$checkbox_field])) {
                $meta[$checkbox_field] = 'false';
            }
        }

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['secured_key'];
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form
     *
     * @param array $contact_info An array of contact info including:
     *  - id The contact ID
     *  - client_id The ID of the client this contact belongs to
     *  - user_id The user ID this contact belongs to (if any)
     *  - contact_type The type of contact
     *  - contact_type_id The ID of the contact type
     *  - first_name The first name on the contact
     *  - last_name The last name on the contact
     *  - title The title of the contact
     *  - company The company name of the contact
     *  - address1 The address 1 line of the contact
     *  - address2 The address 2 line of the contact
     *  - city The city of the contact
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-cahracter country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *  - description The Description of the charge
     *  - return_url The URL to redirect users to after a successful payment
     *  - recur An array of recurring info including:
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used in conjunction
     *          with term in order to determine the next recurring payment
     * @return string HTML markup required to render an authorization and capture payment form
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // Force 2-decimal places only
        $amount = number_format(round($amount, 2), 2, '.', '');

        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $client_id = ($contact_info['client_id'] ?? null);
        $invoices = $this->serializeInvoices($invoice_amounts ?? []);

        // PayFast echoes the basket ID back on every response, it is how a payment is
        // matched to the client once they return
        $basket_id = $this->buildBasketId($client_id);

        // The amount and basket ID must match between the token request and the payment
        // form, PayFast rejects the transaction otherwise
        $token = $this->getAccessToken($basket_id, $amount);
        if (empty($token)) {
            $this->Input->setErrors($this->getCommonError('general'));

            return null;
        }

        // Set the URL the client is returned to, carrying what is needed to record the payment
        $redirect_url = $this->appendQuery(
            ($options['return_url'] ?? null),
            ['amount' => $amount, 'invoices' => $invoices]
        );

        // Set the URL PayFast posts the transaction result to
        $callback_url = $this->appendQuery(
            Configure::get('Blesta.gw_callback_url') . Configure::get('Blesta.company_id') . '/gopayfast/',
            ['client_id' => $client_id, 'invoices' => $invoices]
        );

        $fields = [
            'MERCHANT_ID' => ($this->meta['merchant_id'] ?? null),
            'MERCHANT_NAME' => ($this->meta['merchant_name'] ?? null),
            'TOKEN' => $token,
            'PROCCODE' => '00',
            'TRAN_TYPE' => 'ECOMM_PURCHASE',
            'BASKET_ID' => $basket_id,
            'TXNAMT' => $amount,
            'CURRENCY_CODE' => ($this->currency ?? 'PKR'),
            'ORDER_DATE' => date('Y-m-d H:i:s'),
            'TXNDESC' => substr(($options['description'] ?? ''), 0, 200),
            'CUSTOMER_EMAIL_ADDRESS' => ($contact_info['email'] ?? null),
            'CUSTOMER_MOBILE_NO' => $this->getContactNumber($contact_info),
            'SUCCESS_URL' => $redirect_url,
            'FAILURE_URL' => $redirect_url,
            'CHECKOUT_URL' => $callback_url,
            'SIGNATURE' => $this->generateSignature($basket_id, $amount),
            'VERSION' => 'BLESTA-' . ($this->config->version ?? '1.0.0')
        ];

        $post_to = $this->getApiUrl('PostTransaction');

        $this->log($post_to, serialize($this->maskData($fields, ['TOKEN'])), 'input', true);

        $this->view->set('post_to', $post_to);
        $this->view->set('fields', $fields);
        $this->view->set('template', $this->getClientTemplate());

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this
     *      transaction's original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // PayFast has been observed posting the result and appending it to the callback
        // URL as a query string, accept either
        $response = array_merge($get, $post);

        // Nothing in the callback is trusted beyond the basket ID it names, everything
        // that decides the outcome is read back from PayFast below
        $basket_id = $this->fieldValue($response, ['basket_id', 'BASKET_ID', 'order_no', 'ORDER_NO']);
        $claimed_amount = $this->fieldValue($response, ['transaction_amount', 'TXNAMT', 'amount', 'AMOUNT']);

        $this->log(($_SERVER['REQUEST_URI'] ?? null), serialize($response), 'output', true);

        // The basket ID is issued by this gateway, a callback that does not carry one
        // recognisable as ours is not a response to a payment we started
        if (!$this->isOwnBasketId($basket_id, ($get['client_id'] ?? null))) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }

        // Ask PayFast what actually happened. A payment that cannot be verified is not
        // a payment, so every failure below records nothing
        $transaction = $this->getTransaction($basket_id, $claimed_amount);
        if (empty($transaction)) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }

        $verified = (array) $transaction;
        $error_code = $this->fieldValue($verified, ['err_code', 'ERR_CODE', 'status', 'STATUS', 'code', 'CODE']);

        if (!in_array((string) $error_code, $this->success_codes, true)) {
            $this->Input->setErrors($this->getCommonError('invalid'));

            return;
        }

        return [
            'client_id' => ($get['client_id'] ?? null),
            'amount' => $this->fieldValue(
                $verified,
                ['transaction_amount', 'TXNAMT', 'amount', 'AMOUNT', 'merchant_amount']
            ),
            'currency' => $this->fieldValue($verified, ['transaction_currency', 'CURRENCY_CODE', 'currency'])
                ?: ($this->currency ?? 'PKR'),
            'invoices' => $this->deSerializeInvoices($get['invoices'] ?? null),
            'status' => 'approved',
            'reference_id' => $basket_id,
            'transaction_id' => $this->fieldValue($verified, ['transaction_id', 'TRANSACTION_ID']),
            'parent_transaction_id' => null
        ];
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        $response = array_merge($get, $post);
        $error_code = $this->fieldValue($response, ['err_code', 'ERR_CODE', 'status', 'STATUS']);

        // The client is returned to this page for both outcomes, PayFast is given the same
        // URL for SUCCESS_URL and FAILURE_URL. The transaction is only recorded by validate(),
        // so report the outcome without approving anything here
        $status = in_array((string) $error_code, $this->success_codes, true) ? 'approved' : 'declined';

        return [
            'client_id' => ($get['client_id'] ?? null),
            'amount' => ($get['amount'] ?? null),
            'currency' => ($this->currency ?? 'PKR'),
            'invoices' => $this->deSerializeInvoices($get['invoices'] ?? null),
            'status' => $status,
            'transaction_id' => $this->fieldValue($response, ['transaction_id', 'TRANSACTION_ID']),
            'parent_transaction_id' => null
        ];
    }

    /**
     * Requests a one time access token, which authorises the payment that follows.
     * The basket ID and amount must match those sent with the payment form.
     *
     * @param string $basket_id The unique ID of this payment attempt
     * @param string $amount The amount to be charged, to 2 decimal places
     * @return string The access token, or null on failure
     */
    private function getAccessToken($basket_id, $amount)
    {
        if (!isset($this->Http)) {
            Loader::loadComponents($this, ['Net']);
            $this->Http = $this->Net->create('Http');
        }

        $params = [
            'MERCHANT_ID' => ($this->meta['merchant_id'] ?? null),
            'SECURED_KEY' => ($this->meta['secured_key'] ?? null),
            'BASKET_ID' => $basket_id,
            'TXNAMT' => $amount,
            'CURRENCY_CODE' => ($this->currency ?? 'PKR')
        ];

        $url = $this->getApiUrl('GetAccessToken');
        $this->log($url, serialize($this->maskData($params, ['SECURED_KEY'])), 'input', true);

        $response = json_decode($this->Http->post($url, http_build_query($params)));
        $token = ($response->ACCESS_TOKEN ?? null);

        $this->log($url, serialize($this->maskData((array) $response, ['ACCESS_TOKEN'])), 'output', !empty($token));

        return $token;
    }

    /**
     * Fetches the authoritative record of a transaction from PayFast.
     *
     * The callback carries the outcome as query/POST data, which anyone can forge. This
     * asks PayFast directly instead. The lookup is by basket ID rather than transaction
     * ID because the basket ID is issued by this gateway, so an attacker cannot choose
     * which record is inspected.
     *
     * @param string $basket_id The basket ID issued for the payment attempt
     * @param string $amount The amount claimed by the callback, used to obtain a token
     * @return stdClass The transaction record, or null if it could not be retrieved
     */
    private function getTransaction($basket_id, $amount)
    {
        $token = $this->getAccessToken($basket_id, $amount);
        if (empty($token)) {
            return null;
        }

        if (!isset($this->Http)) {
            Loader::loadComponents($this, ['Net']);
            $this->Http = $this->Net->create('Http');
        }

        $url = $this->getApiBaseUrl() . 'transaction/basket_id/' . rawurlencode($basket_id);

        $this->Http->setHeaders([
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Bearer ' . $token
        ]);

        $this->log($url, serialize(['basket_id' => $basket_id]), 'input', true);

        $body = $this->Http->get($url);
        $transaction = json_decode((string) $body);
        $success = ($this->Http->responseCode() == 200) && is_object($transaction);

        $this->log($url, serialize($success ? $transaction : (string) $body), 'output', $success);

        return $success ? $transaction : null;
    }

    /**
     * Builds the base URL of the API for the configured environment
     *
     * @return string The API base URL, with a trailing slash
     */
    private function getApiBaseUrl()
    {
        $host = $this->api_hosts[($this->meta['sandbox'] ?? 'false') == 'true' ? 'sandbox' : 'live'];

        return $host . '/Ecommerce/api/';
    }

    /**
     * Builds the URL of the given payment endpoint for the configured environment
     *
     * @param string $endpoint The name of the endpoint
     * @return string The full endpoint URL
     */
    private function getApiUrl($endpoint)
    {
        return $this->getApiBaseUrl() . 'Transaction/' . $endpoint;
    }

    /**
     * Generates the MD5 signature PayFast uses to verify the integrity of the payment request
     *
     * @param string $basket_id The unique ID of this payment attempt
     * @param string $amount The amount to be charged
     * @return string The MD5 signature
     */
    private function generateSignature($basket_id, $amount)
    {
        return md5(
            ($this->meta['merchant_id'] ?? '') . ':'
            . ($this->meta['merchant_name'] ?? '') . ':'
            . $amount . ':'
            . $basket_id
        );
    }

    /**
     * Builds a basket ID that is unique to this payment attempt and identifies the client
     *
     * @param int $client_id The ID of the client making the payment
     * @return string The basket ID
     */
    private function buildBasketId($client_id)
    {
        return 'BLESTA-' . (int) $client_id . '-' . time() . '-' . mt_rand(1000, 9999);
    }

    /**
     * Checks that the given basket ID is one issued by this gateway for the given client
     *
     * @param string $basket_id The basket ID returned by PayFast
     * @param int $client_id The ID of the client the response is for
     * @return bool True if the basket ID belongs to this client
     */
    private function isOwnBasketId($basket_id, $client_id)
    {
        if (empty($basket_id) || empty($client_id)) {
            return false;
        }

        return strpos((string) $basket_id, 'BLESTA-' . (int) $client_id . '-') === 0;
    }

    /**
     * Returns the first value present in the response under any of the given keys.
     * PayFast is inconsistent about the case of its response fields.
     *
     * @param array $response The response data
     * @param array $keys The keys to look for, in order of preference
     * @return mixed The value, or null if no key is present
     */
    private function fieldValue(array $response, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($response[$key]) && $response[$key] !== '') {
                return $response[$key];
            }
        }

        return null;
    }

    /**
     * Fetches the primary phone number for the given contact
     *
     * @param array $contact_info An array of contact info
     * @return string The phone number, or an empty string if there is none
     */
    private function getContactNumber(array $contact_info)
    {
        foreach (($contact_info['numbers'] ?? []) as $number) {
            $value = is_array($number) ? ($number['number'] ?? null) : ($number->number ?? null);
            if (!empty($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Appends the given parameters to a URL, preserving any query string already present
     *
     * @param string $url The URL to append to
     * @param array $params The parameters to append
     * @return string The resulting URL
     */
    private function appendQuery($url, array $params)
    {
        $separator = parse_url((string) $url, PHP_URL_QUERY) ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }

    /**
     * Determines which client template the payment form will be rendered in, so the
     * markup can match the theme
     *
     * @return string The client template directory name, defaults to 'bootstrap'
     */
    private function getClientTemplate()
    {
        try {
            Loader::loadModels($this, ['Companies']);
            $setting = $this->Companies->getSetting(Configure::get('Blesta.company_id'), 'client_view_dir');
        } catch (Throwable $e) {
            return 'bootstrap';
        }

        return !empty($setting->value) ? $setting->value : 'bootstrap';
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array $invoices A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }

        return $str;
    }

    /**
     * Deserializes a string of invoice info into an array
     *
     * @param string $str A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function deSerializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', (string) $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }

        return $invoices;
    }
}
