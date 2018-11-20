<?php
    /**
     * Document all error codes.
     * Error codes used in code are constants so they are easily used in algorithms and index lookup.
     * Not intended for UI consumption. Instead, use the string table and localized language to
     * map the error code into a UI string.
     *
     * Date: 2/7/16
     */

// TODO: This should be automatically generated from the data in Enginesis.error_messages
abstract class EnginesisErrors {
    const NO_ERROR = '';
    const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';

    const CMS_LOCKED = 'CMS_LOCKED';
    const DUPLICATE_ENTRY = 'DUPLICATE_ENTRY';
    const EMAIL_IN_USE = 'EMAIL_IN_USE';
    const INVALID_ID = 'INVALID_ID';
    const INVALID_LOGIN = 'INVALID_LOGIN';
    const INVALID_PARAMETER = 'INVALID_PARAMETER';
    const INVALID_SECONDARY_PASSWORD = 'INVALID_SECONDARY_PASSWORD';
    const INVALID_TOKEN = 'INVALID_TOKEN';
    const INVALID_USER_ID = 'INVALID_USER_ID';
    const INVALID_USER_NAME = 'INVALID_USER_NAME';
    const NAME_IN_USE = 'NAME_IN_USE';
    const NOT_LOGGED_IN = 'NOT_LOGGED_IN';
    const PASSWORD_EXPIRED = 'PASSWORD_EXPIRED';
    const REGISTRATION_NOT_CONFIRMED = 'REGISTRATION_NOT_CONFIRMED';
    const SERVER_DID_NOT_REPLY = 'SERVER_DID_NOT_REPLY';
    const SERVER_RESPONSE_NOT_VALID = 'SERVER_RESPONSE_NOT_VALID';
    const SYSTEM_ERROR = 'SYSTEM_ERROR';
    const SERVICE_ERROR = 'SERVICE_ERROR';
    const TOKEN_EXPIRED = 'TOKEN_EXPIRED';
}

// TODO: This should be automatically generated from the data in Enginesis.error_messages and be indexed by $language_code
$errorCodeTable = array (
    EnginesisErrors::CMS_LOCKED => 'Enginesis CMS is locked. Try again later.',
    EnginesisErrors::DUPLICATE_ENTRY => 'Object or data already exists matching that identity.',
    EnginesisErrors::EMAIL_IN_USE => 'The email address is already in use. Please choose a different email address.',
    EnginesisErrors::INVALID_ID => 'The identifier specified is not valid or does not identify an object of this type.',
    EnginesisErrors::INVALID_LOGIN => 'The login credentials provided do not match.',
    EnginesisErrors::INVALID_PARAMETER => 'A required parameter is missing or not valid in this context.',
    EnginesisErrors::INVALID_SECONDARY_PASSWORD => 'Your confirmation token is not valid or it has expired.',
    EnginesisErrors::INVALID_TOKEN => 'The token you are trying to use is not valid.',
    EnginesisErrors::INVALID_USER_ID => 'There is no user registered with the provided information.',
    EnginesisErrors::NAME_IN_USE => 'The user name is already in use. Please choose another user name.',
    EnginesisErrors::NOT_LOGGED_IN => 'You are not logged in. The requested service requires a logged in user.',
    EnginesisErrors::NOT_IMPLEMENTED => 'Service is not implemented.',
    EnginesisErrors::PASSWORD_EXPIRED => 'Your confirmation token has expired.',
    EnginesisErrors::REGISTRATION_NOT_CONFIRMED => 'Registration has not been confirmed.',
    EnginesisErrors::SERVER_DID_NOT_REPLY => 'The service did not respond to our request. Please check your network connection.',
    EnginesisErrors::SERVER_RESPONSE_NOT_VALID => 'The service responded with an unexpected response. Please check your network connection and the operation of the service.',
    EnginesisErrors::SERVICE_ERROR => 'The service responded with an unexpected error. Please check your network connection and the operation of the service.',
    EnginesisErrors::SYSTEM_ERROR => 'There was a system error processing your request. Information has been sent to support to remedy the problem.',
    EnginesisErrors::TOKEN_EXPIRED => 'The token you are trying to use is either invalid or it has past its expiration date.',
);

/**
 * @param $status_msg
 * @return mixed
 */
function errorToLocalString ($status_msg) {
    global $errorCodeTable;
    global $language_code;

    if (isset($errorCodeTable[$status_msg])) {
        $status_msg = $errorCodeTable[$status_msg];
    }
    return $status_msg;
}