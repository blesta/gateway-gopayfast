<?php
/**
 * en_us language for the PayFast (Pakistan) gateway.
 */
// Basics
$lang['Gopayfast.name'] = 'PayFast (Pakistan)';
$lang['Gopayfast.description'] = 'Accept card, wallet and bank payments in Pakistani Rupees through PayFast\'s hosted checkout.';


// Errors
$lang['Gopayfast.!error.merchant_id.valid'] = 'Merchant ID invalid.';
$lang['Gopayfast.!error.merchant_name.valid'] = 'Merchant Name invalid.';
$lang['Gopayfast.!error.secured_key.valid'] = 'Secured Key invalid.';
$lang['Gopayfast.!error.sandbox.valid'] = 'Sandbox value invalid.';


// Settings
$lang['Gopayfast.meta.merchant_id'] = 'Merchant ID';
$lang['Gopayfast.meta.merchant_name'] = 'Merchant Name';
$lang['Gopayfast.meta.merchant_name_note'] = 'The merchant name registered with PayFast, shown to the client on the payment page.';
$lang['Gopayfast.meta.secured_key'] = 'Secured Key';
$lang['Gopayfast.meta.sandbox'] = 'Sandbox';
$lang['Gopayfast.meta.sandbox_note'] = 'Sends payments to PayFast\'s UAT environment for testing, rather than the live one.';


// Process
$lang['Gopayfast.buildprocess.submit'] = 'Pay with PayFast';
$lang['Gopayfast.buildprocess.description'] = 'You will be redirected to PayFast to complete your payment securely.';
