<?php

namespace Exceptions;

use Exception;

class CsrfTokenNotExtractedException extends Exception
{
    protected $message = 'CSRF token used to perform search was not extracted';
}
