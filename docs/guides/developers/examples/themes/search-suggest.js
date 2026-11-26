// docs/guides/developers/examples/themes/search-suggest.js
const input = document.querySelector('[data-suggest-input]');
const list = document.querySelector('[data-suggest-list]');
const language = input.dataset.language;
let timer;

input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const query = input.value.trim();
        const url = '/_teme/suggest?q=' + encodeURIComponent(query) + (language ? '&lang=' + encodeURIComponent(language) : '');
        const answer = await (await fetch(url)).json();

        list.replaceChildren(...answer.items.map((item) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.textContent = item.title;
            return link;
        }));
    }, 200);
});
