// docs/guides/developers/examples/themes/load-more.js
const list = document.querySelector('[data-load-list]');
const button = document.querySelector('[data-load-more]');

async function loadMore() {
    const address = button.dataset.next + (button.dataset.next.includes('?') ? '&' : '?') + 'format=json';
    const page = await (await fetch(address)).json();

    for (const item of page.items) {
        const link = document.createElement('a');
        link.href = item.url;
        link.textContent = item.title;
        list.append(link);
    }

    if (page.pagination.next === null) {
        button.remove();
    } else {
        button.dataset.next = page.pagination.next.replace(/[?&]format=json$/, '');
    }
}

button?.addEventListener('click', loadMore);
