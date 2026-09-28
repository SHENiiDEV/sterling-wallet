<?php

namespace App\Reports\Generation;

use RuntimeException;

/**
 * The report can't be calculated until someone fixes data (tariff, FX rate,
 * MID in review…). The message is shown to admins as the reason.
 */
class ReportBlocked extends RuntimeException {}
