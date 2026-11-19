<?php

/**
 * Says a card belongs in a theme's regions only (I-3127): DocumentValidator
 * refuses it in any other document, and the editor offers it only while
 * editing a region. A CardType implements it beside CardType.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Cards;

interface RegionCard {}
