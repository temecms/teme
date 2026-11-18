<?php

/**
 * What a model can do (I-887). A model declares its own; a task names what it
 * needs, and only models with all of it are offered for that task. Only the
 * first three have tasks yet; the rest are declared so a later task can pick
 * its own model and provider.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Ai;

enum AiCapability: string
{
    case Text = 'text';
    case ImagesIn = 'images-in';
    case StructuredOutput = 'structured-output';
    case Embeddings = 'embeddings';
    case Rerank = 'rerank';
    case ImagesOut = 'images-out';
    case Decide = 'decide';
}
