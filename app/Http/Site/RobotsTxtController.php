<?php

declare(strict_types=1);

namespace App\Http\Site;

use App\Domain\RobotsTxt\RobotsTxt;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * /robots.txt (I-300): the text Teme writes, as plain text in UTF-8, which
 * the page cache stores like a page.
 */
final class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        return response(RobotsTxt::text(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
