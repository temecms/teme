<?php

declare(strict_types=1);

namespace App\Domain\Plugins\Seo;

use RuntimeException;

/**
 * A plugin answered one of the search sockets with something core can't
 * use, such as a result for a check it doesn't declare: that part is
 * dropped, and this is recorded as its fault.
 */
final class SocketAnswerRefused extends RuntimeException {}
