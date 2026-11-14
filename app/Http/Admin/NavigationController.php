<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Domain\Menus\Operations\BindNavigationSlot;
use App\Domain\Menus\Operations\CreateNavigation;
use App\Domain\Menus\Operations\DeleteNavigation;
use App\Domain\Menus\Operations\Input\BindNavigationSlotInput;
use App\Domain\Menus\Operations\Input\CreateNavigationInput;
use App\Domain\Menus\Operations\Input\DeleteNavigationInput;
use App\Domain\Menus\Operations\Input\RenameNavigationInput;
use App\Domain\Menus\Operations\Input\SaveNavigationItemsInput;
use App\Domain\Menus\Operations\RenameNavigation;
use App\Domain\Menus\Operations\SaveNavigationItems;
use App\Domain\Menus\Queries\NavigationScreens;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Content › Navigation: the menus, and the editor of one, where its items
 * are arranged and the theme's places that show it are chosen. It reads
 * through NavigationScreens and hands each change to its Operation; it
 * changes nothing itself.
 */
final class NavigationController extends Controller
{
    public function index(NavigationScreens $screens): Response
    {
        return Inertia::render('navigation/index', ['navigations' => $screens->index()]);
    }

    public function store(Request $request, CreateNavigation $createNavigation): RedirectResponse
    {
        $navigation = $createNavigation->execute(new CreateNavigationInput($request->string('name')->toString(), $this->actor($request)));

        return $this->done('navigation.edit', ['id' => $navigation->id], __('Menu created. Add its items, then choose where it shows.'));
    }

    public function edit(string $id, NavigationScreens $screens): Response
    {
        return Inertia::render('navigation/edit', $screens->editor($id) ?? abort(404));
    }

    public function update(Request $request, string $id, RenameNavigation $renameNavigation): RedirectResponse
    {
        $navigation = $renameNavigation->execute(new RenameNavigationInput($id, $request->string('name')->toString(), $this->actor($request)));

        return $this->done('navigation.edit', ['id' => $id], __('Renamed to “:name”.', ['name' => (string) $navigation->name]));
    }

    public function updateItems(Request $request, string $id, SaveNavigationItems $saveNavigationItems): RedirectResponse
    {
        $tree = $request->input('tree', []);

        $navigation = $saveNavigationItems->execute(new SaveNavigationItemsInput($id, is_array($tree) ? $tree : [], $this->actor($request)));

        return $this->done('navigation.edit', ['id' => $id], __('Saved “:name”.', ['name' => (string) $navigation->name]));
    }

    public function updateSlots(Request $request, string $id, BindNavigationSlot $bindNavigationSlot, NavigationScreens $screens): RedirectResponse
    {
        $slot = $request->string('slot')->toString();
        $chosen = $request->string('navigation_id')->trim()->toString();
        $navigationId = $chosen === '' ? null : $chosen;

        $bindNavigationSlot->execute(new BindNavigationSlotInput(
            $request->string('theme')->toString(),
            $slot,
            $navigationId,
            $this->actor($request),
        ));

        $place = $screens->place($slot, $navigationId);

        return $this->done('navigation.edit', ['id' => $id], $place['menu'] === null
            ? __('Saved. :place shows no menu.', ['place' => $place['label']])
            : __('Saved. :place shows “:menu”.', ['place' => $place['label'], 'menu' => $place['menu']]));
    }

    public function destroy(Request $request, string $id, DeleteNavigation $deleteNavigation): RedirectResponse
    {
        $navigation = $deleteNavigation->execute(new DeleteNavigationInput($id, $this->actor($request)));

        return $this->done('navigation.index', [], __('Deleted “:name”.', ['name' => (string) $navigation->name]));
    }

    private function actor(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function done(string $route, array $parameters, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route($route, $parameters);
    }
}
