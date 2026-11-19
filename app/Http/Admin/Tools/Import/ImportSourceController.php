<?php

/**
 * Connecting a WordPress site, reading its report again, and forgetting its application password (I-1708).
 *
 * @package Teme
 * @since   0.7.0-alpha.2
 * @license MIT
 */

declare(strict_types=1);

namespace App\Http\Admin\Tools\Import;

use App\Domain\Import\Operations\ConnectImportSource;
use App\Domain\Import\Operations\ForgetImportSource;
use App\Domain\Import\Operations\Input\ConnectImportSourceInput;
use App\Domain\Import\Operations\Input\ForgetImportSourceInput;
use App\Domain\Import\Operations\Input\SetImportSourceHostsInput;
use App\Domain\Import\Operations\SetImportSourceHosts;
use App\Domain\Import\Planning\ImportAnalysis;
use App\Domain\Import\Queries\ImportScreen;
use App\Domain\Support\Security\ElevationRequiredException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class ImportSourceController extends Controller
{
    /**
     * The session key the hosts are kept under across the password's confirmation, followed by the site's ID.
     *
     * @since 1.0.0
     */
    public const string REMEMBERED_HOSTS = 'import.other-hosts.';

    public function connect(Request $request, ConnectImportSource $connect, ImportScreen $screen): RedirectResponse
    {
        try {
            $result = $connect->execute(new ConnectImportSourceInput(
                $request->string('address')->toString(),
                $request->string('user_name')->toString(),
                $request->string('application_password')->toString(),
                self::actor($request),
            ));
        } catch (ValidationException $refused) {
            // Back with the errors and none of the input: a session is stored as it
            // is, and the form on the page keeps what was typed (I-1708).
            return back()->withErrors($refused->errors());
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connected to '.($screen->site($result['sourceId'])['address'] ?? '').'.']);

        return redirect()->route('tools.importer.show', ['source' => $result['sourceId']]);
    }

    public function analyze(string $source, ImportScreen $screen, ImportAnalysis $analysis): RedirectResponse
    {
        abort_if($screen->site($source) === null, 404);

        $analysis->forget($source);

        return self::told('Teme will read the site report again.');
    }

    public function forget(Request $request, string $source, ImportScreen $screen, ForgetImportSource $forget): RedirectResponse
    {
        $site = $screen->site($source);
        abort_if($site === null, 404);

        try {
            $forget->execute(new ForgetImportSourceInput($source, self::actor($request)));
        } catch (ValidationException $refused) {
            return self::told((string) $refused->validator->errors()->first(), 'error');
        }

        return self::told("Teme has forgotten the application password for {$site['address']}. Revoke it in WordPress too: Users › Profile › Application Passwords.");
    }

    /**
     * @since 1.0.0
     */
    public function hosts(Request $request, string $source, ImportScreen $screen, SetImportSourceHosts $set): RedirectResponse
    {
        abort_if($screen->site($source) === null, 404);

        $hosts = $request->input('hosts', []);
        $hosts = is_array($hosts) ? array_values(array_filter($hosts, is_string(...))) : [];

        try {
            $set->execute(new SetImportSourceHostsInput($source, $hosts, self::actor($request)));
        } catch (ElevationRequiredException $ask) {
            // Kept across the password's confirmation, so nothing is chosen twice.
            $request->session()->put(self::REMEMBERED_HOSTS.$source, $hosts);

            throw $ask;
        } catch (ValidationException $refused) {
            return back()->withErrors($refused->errors());
        }

        $request->session()->forget(self::REMEMBERED_HOSTS.$source);

        return self::told('Saved the other addresses of this site.');
    }

    /**
     * Who is asking, as an Operation's input carries them.
     */
    private static function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * Back to the page the person was on, with a toast.
     */
    private static function told(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return redirect()->back(fallback: route('tools.importer.index'));
    }
}
