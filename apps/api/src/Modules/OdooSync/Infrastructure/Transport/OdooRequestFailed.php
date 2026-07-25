<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Infrastructure\Transport;

use RuntimeException;

/** Erreur métier Odoo (validation, droit…) — dead letter immédiat, résolution manuelle. */
final class OdooRequestFailed extends RuntimeException {}
