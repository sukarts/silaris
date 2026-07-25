<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Domain\Contract;

use RuntimeException;

/** Compagnie injoignable / circuit ouvert — le job replanifie sans compter d'échec métier. */
final class CarrierUnavailable extends RuntimeException {}
