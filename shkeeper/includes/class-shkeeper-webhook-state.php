<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shkeeper_Webhook_State
{
    const VALIDATION_SUCCEEDED                 = 'validation_succeeded';
    const VALIDATION_FAILED_MISSED_SIGNATURE   = 'missed_shkeeper_signature';
    const VALIDATION_FAILED_EMPTY_BODY         = 'empty_body';
    const VALIDATION_FAILED_API_KEY_INVALID    = 'api_key_invalid';
    const SHKEEPER_HTTP_SIGNATURE              = 'HTTP_X_SHKEEPER_API_KEY';
}