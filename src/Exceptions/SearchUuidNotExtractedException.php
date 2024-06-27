<?php

namespace Exceptions;

use Exception;

class SearchUuidNotExtractedException extends Exception
{
    protected $message = 'Search was not performed';
}
