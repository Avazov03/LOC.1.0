<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused business action. The message is a plain Uzbek sentence safe to show the caller.
 */
class BusinessRuleException extends RuntimeException {}
