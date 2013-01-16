<?php

namespace Stomping\Error;

/**
 * Raised when an in-flight exclusive operation is called more than once.
 */
class StompAlreadyRunningError extends StompExclusiveOperationError
{

}