<?php

namespace App\Bots\Connectors;

use RuntimeException;

/**
 * An API connector could not get its data (no connection, a refused key, an error answer).
 */
class ApiFailure extends RuntimeException {}
