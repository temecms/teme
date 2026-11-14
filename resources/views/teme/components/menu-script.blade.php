{{--
    Core's menu script, once a page, wherever a heading is drawn: the menu's items view includes it, and a theme that draws its own menus may include it too. As the page loads it folds each heading's list (aria-expanded false, hidden); a heading's button opens and folds it, and a button already answers Enter and Space; Escape folds the open heading that has the focus, or the open list that holds it, and puts the focus on that heading's button. Each heading opens on its own, and nothing folds on a click elsewhere: docs/architecture/themes.md, Navigation.

    It finds headings by data-teme-heading and their lists by aria-controls, never a theme's classes; core's layer hides a folded list whatever a theme's display says. With no script, or a script that fails, every list stays open as drawn; so it does in the signed-in preview, which allows only nonced scripts. A theme opts out by drawing its menus from $navigation without including this view. It is the same for every reader and names nothing, so a stored page stays shared and a content security policy can allow it by its hash. It stays within 1 KB compressed, which a test holds: no comments of its own, and these explanations stay here, where they never reach the page.
--}}
@pushOnce('teme-core-scripts', 'teme-menu-script')
<x-teme::inline-script name="menus">
(() => {
    const show = (button, open) => {
        const list = document.getElementById(button.getAttribute('aria-controls') || '');
        if (list === null) {
            return;
        }
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        list.hidden = !open;
    };
    document.querySelectorAll('[data-teme-heading]').forEach((button) => {
        show(button, false);
        button.addEventListener('click', () => show(button, button.getAttribute('aria-expanded') !== 'true'));
    });
    document.addEventListener('keydown', (event) => {
        const at = event.target;
        if (event.key !== 'Escape' || !(at instanceof Element)) {
            return;
        }
        const own = at.closest('[data-teme-heading][aria-expanded="true"]');
        const list = own === null ? at.closest('[data-teme-submenu]:not([hidden])') : null;
        const button = own !== null ? own : list === null ? null : document.querySelector('[aria-controls="' + list.id + '"]');
        if (button instanceof HTMLElement) {
            show(button, false);
            button.focus();
        }
    });
})();
</x-teme::inline-script>
@endPushOnce
