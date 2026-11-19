<?php

/**
 * Makes an export's transaction one moment of the database (I-365): every
 * kind read from the same snapshot, so a comment's item and a reply's
 * parent are always in the export with it.
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Domain\Export;

use Illuminate\Database\ConnectionInterface;

final class ExportSnapshot
{
    /**
     * REPEATABLE READ when the connection's transaction is the outermost,
     * and nothing has run in it yet; returns whether it did. Inside another
     * transaction (a test's) it does nothing, since isolation can't change
     * after a transaction's first query, and the export is then consistent
     * only as far as that transaction's own isolation goes: ExportSite must
     * run as the outermost transaction, as teme:export runs it.
     */
    public static function hold(ConnectionInterface $connection): bool
    {
        if ($connection->transactionLevel() !== 1) {
            return false;
        }

        // The first statement after BEGIN: the next query takes the snapshot.
        $connection->unprepared('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        // PHP copies originals with the session idle; a server's own limit mustn't end the export.
        $connection->unprepared('SET LOCAL idle_in_transaction_session_timeout = 0');

        return true;
    }
}
