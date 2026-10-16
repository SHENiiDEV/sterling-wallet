<?php

namespace App\Bots;

/**
 * Marker for connectors that poll a window of days: each row goes to the
 * report day of its own transaction time, not to the run's report date.
 */
interface IngestsByRowDate {}
