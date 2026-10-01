<?php

declare(strict_types=1);

namespace Glimpse\Database;

use RuntimeException;

/** Operator-facing migration failure. Messages never contain credentials or secrets. */
final class MigrationException extends RuntimeException {}
