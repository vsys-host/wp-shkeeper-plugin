<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Shkeeper_Webhook_State
{
    const VALIDATION_SUCCEEDED                 = 'validation_succeeded';
    const VALIDATION_FAILED_EMPTY_HEADERS      = 'empty_headers';
    const VALIDATION_FAILED_EMPTY_BODY         = 'empty_body';
    const VALIDATION_FAILED_API_KEY_INVALID    = 'api_key_invalid';
}