<?php
/**
 * PayFast (Pakistan) API
 *
 *  - Live: https://ipg1.apps.net.pk
 *  - UAT:  https://ipguat.apps.net.pk
 *
 * API documentation: https://gopayfast.com/docs/
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.gopayfast.lib
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class GopayfastApi
{
    /**
     * @var array The API hosts, keyed by environment
     */
    private $api_hosts = [
        'live' => 'https://ipg1.apps.net.pk',
        'sandbox' => 'https://ipguat.apps.net.pk'
    ];

    /**
     * @var array The transaction status API hosts, keyed by environment
     */
    private $status_hosts = [
        'live' => 'https://ipg1.apps.net.pk:8443',
        'sandbox' => 'https://ipguat.apps.net.pk:8443'
    ];

    /**
     * @var string The merchant ID
     */
    private $merchant_id;

    /**
     * @var string The secured key
     */
    private $secured_key;

    /**
     * @var bool True to use the UAT environment, false for live
     */
    private $sandbox;

    /**
     * @var array The URL and parameters of the last request made
     */
    private $last_request = ['url' => null, 'params' => []];

    /**
     * @var string The raw body returned by the last request
     */
    private $last_response;

    /**
     * @var int The HTTP response code returned by the last request
     */
    private $response_code;

    /**
     * Initializes the API
     *
     * @param string $merchant_id The merchant ID
     * @param string $secured_key The secured key
     * @param bool $sandbox True to send requests to the UAT environment, false for live
     */
    public function __construct($merchant_id, $secured_key, $sandbox = false)
    {
        $this->merchant_id = $merchant_id;
        $this->secured_key = $secured_key;
        $this->sandbox = (bool) $sandbox;

        Loader::loadComponents($this, ['Net']);
    }

    /**
     * Requests a one time access token, which authorises the payment that follows.
     * The basket ID and amount must match those sent with the payment form.
     *
     * @param string $basket_id The unique ID of this payment attempt
     * @param string $amount The amount to be charged, to 2 decimal places
     * @param string $currency The ISO 4217 currency code of the payment
     * @return stdClass The API response, or null if it could not be decoded
     */
    public function getAccessToken($basket_id, $amount, $currency = 'PKR')
    {
        return $this->apiRequest(
            $this->getTokenUrl(),
            [
                'MERCHANT_ID' => $this->merchant_id,
                'SECURED_KEY' => $this->secured_key,
                'BASKET_ID' => $basket_id,
                'TXNAMT' => $amount,
                'CURRENCY_CODE' => $currency
            ],
            'POST'
        );
    }

    /**
     * Requests a bearer token for the transaction status API. This is a different
     * credential from the payment access token, and is not interchangeable with it.
     *
     * @return stdClass The API response, or null if it could not be decoded
     */
    public function getStatusToken()
    {
        return $this->apiRequest(
            $this->getStatusApiUrl('token'),
            [
                'merchant_id' => $this->merchant_id,
                'grant_type' => 'client_credentials',
                'secured_key' => $this->secured_key
            ],
            'POST'
        );
    }

    /**
     * Fetches the authoritative record of a transaction from PayFast.
     *
     * PayFast answers with HTTP 200 even for a transaction ID it does not recognise,
     * the outcome is carried by the record's status_code.
     *
     * @param string $transaction_id The ID PayFast assigned the transaction
     * @param string $access_token A token from getStatusToken()
     * @return stdClass The transaction record, or null if it could not be decoded
     */
    public function getTransaction($transaction_id, $access_token)
    {
        return $this->apiRequest(
            $this->getStatusApiUrl('transaction/' . rawurlencode($transaction_id)),
            [],
            'GET',
            $access_token
        );
    }

    /**
     * Returns the URL a token is requested from, for the configured environment
     *
     * @return string The GetAccessToken URL
     */
    public function getTokenUrl()
    {
        return $this->getApiUrl('Transaction/GetAccessToken');
    }

    /**
     * Returns the URL the client's browser posts the payment form to, for the
     * configured environment
     *
     * @return string The PostTransaction URL
     */
    public function getFormUrl()
    {
        return $this->getApiUrl('Transaction/PostTransaction');
    }

    /**
     * Builds the URL of the given endpoint for the configured environment
     *
     * @param string $endpoint The endpoint, relative to the API base
     * @return string The full endpoint URL
     */
    public function getApiUrl($endpoint)
    {
        return $this->getApiBaseUrl() . ltrim((string) $endpoint, '/');
    }

    /**
     * Builds the base URL of the API for the configured environment
     *
     * @return string The API base URL, with a trailing slash
     */
    public function getApiBaseUrl()
    {
        return $this->api_hosts[$this->sandbox ? 'sandbox' : 'live'] . '/Ecommerce/api/';
    }

    /**
     * Builds the validation hash PayFast sends with every IPN, so it can be compared
     * against the one received. It is the lowercase hex SHA256 of the basket ID, secured
     * key, merchant ID and error code joined by pipes:
     *
     *  sha256('BAS-01|jdnkaabcks|102|000')
     *
     * Being keyed on the secured key, which only PayFast and this merchant hold, a hash
     * that matches proves the notification came from PayFast and that the basket ID and
     * error code in it are the ones PayFast sent. No other field is covered by it.
     *
     * @param string $basket_id The basket ID the notification names
     * @param string $error_code The error code the notification carries
     * @return string The lowercase hex SHA256 validation hash
     */
    public function getValidationHash($basket_id, $error_code)
    {
        return hash(
            'sha256',
            $basket_id . '|' . $this->secured_key . '|' . $this->merchant_id . '|' . $error_code
        );
    }

    /**
     * Builds the URL of the given transaction status endpoint for the configured environment
     *
     * @param string $endpoint The endpoint, relative to the status API base
     * @return string The full endpoint URL
     */
    public function getStatusApiUrl($endpoint)
    {
        return $this->getStatusApiBaseUrl() . ltrim((string) $endpoint, '/');
    }

    /**
     * Builds the base URL of the transaction status API for the configured environment
     *
     * @return string The status API base URL, with a trailing slash
     */
    public function getStatusApiBaseUrl()
    {
        return $this->status_hosts[$this->sandbox ? 'sandbox' : 'live'] . '/api/';
    }

    /**
     * Returns the URL and parameters of the last request made, so the caller can log it
     *
     * @return array An array containing:
     *  - url The URL the request was sent to
     *  - params The parameters sent with the request
     */
    public function lastRequest()
    {
        return $this->last_request;
    }

    /**
     * Returns the raw body returned by the last request
     *
     * @return string The raw response body
     */
    public function lastResponse()
    {
        return $this->last_response;
    }

    /**
     * Returns the HTTP response code returned by the last request
     *
     * @return int The HTTP response code
     */
    public function responseCode()
    {
        return $this->response_code;
    }

    /**
     * Sends a request to the API
     *
     * @param string $url The URL to request
     * @param array $params The parameters to send with the request
     * @param string $type The HTTP request type
     * @param string $access_token An access token to authenticate the request with (optional)
     * @return stdClass The decoded response, or null if the response is not an object
     */
    private function apiRequest($url, array $params = [], $type = 'POST', $access_token = null)
    {
        $this->last_request = ['url' => $url, 'params' => $params];

        // A new connection is used for every request, options set by one request would
        // otherwise carry over to the next
        $http = $this->Net->create('Http');

        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if (!empty($access_token)) {
            $headers[] = 'Authorization: Bearer ' . $access_token;
        }
        $http->setHeaders($headers);

        $this->last_response = ($type == 'GET')
            ? (string) $http->get($url, http_build_query($params))
            : (string) $http->post($url, http_build_query($params));
        $this->response_code = $http->responseCode();

        $response = json_decode($this->last_response);

        return is_object($response) ? $response : null;
    }
}
