<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Infrastructure\Transport;

use RuntimeException;

/** Odoo injoignable — le job retente avec backoff (mode dégradé). */
final class OdooUnavailable extends RuntimeException {}
