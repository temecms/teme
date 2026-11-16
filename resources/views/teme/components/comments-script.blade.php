{{--
    Core's comments script (slice 1.8b′): the personal parts of one browser, on a page that is stored and shared (I-276). What a reader chose to save, who is signed in, their own comments that wait, the one form that moves under the comment answered, and a post that doesn't leave the page. A browser without JavaScript gets exactly what 1.8b built. It adds Edit to each comment for someone who looks after the item's comments, by the `moderate` the session answers, in the place the theme marks `data-teme-actions` or, without it, beside Reply (1.8c″). After a comment is posted at once it hides the thread's empty line and recounts its heading, by `data-teme-empty` and `data-teme-count`; a comment that waits changes neither (1.8c″'s D8).

    It is inline, as 1.7c's session script is: the comment form pushes it once onto the shell's teme-core-scripts stack (1.8b′'s D1), after the session script, so a page with no form never carries it. It is the same for every reader and names no item, so the page stays shared and a content security policy can allow it by its hash. With the session script it stays within 5 KB compressed, which a test holds (D2): no minifier, no comments of its own, and these explanations live here, where they never reach the page.

    It reads the session's answer through Symbol.for('teme.reader'), so a page asks who is reading once (D3). It finds the data-teme-* hooks of 1.8b's Task 9 and core's form's own ids, never a theme's classes. It keeps a random key in local storage, whether or not the box is ticked, and the reader's name, email address and website only while it is (D5). It puts in the page only HTML core's server drew, parsed inert in a template, and sets everything else as text (D18).
--}}
@php
    $temeWords = [
        'heading' => __('Leave a comment'),
        'comment' => __('Comment'),
        'reply' => __('Your reply'),
        'postComment' => __('Post comment'),
        'postReply' => __('Post reply'),
        'postingComment' => __('Posting your comment…'),
        'postingReply' => __('Posting your reply…'),
        'deepest' => __('Replies stop nesting here, so yours will appear at the end of this conversation.'),
        'unsent' => __('Your comment wasn’t sent. Check your connection, then try again.'),
        'edit' => __('Edit'),
        'editNamed' => __(':name’s comment'),
        'editUnnamed' => __('comment'),
        'countOne' => __('1 comment'),
        'countMany' => __(':count comments'),
    ];
    $temeOwnComments = route('teme.comments.pending', [], false);
@endphp
<x-teme::inline-script name="comments">
(() => {
    'use strict';
    const words = @json($temeWords);
    const ownComments = @json($temeOwnComments);
    const reader = document[Symbol.for('teme.reader')] || Promise.resolve(null);
    const store = (() => {
        try {
            localStorage.setItem('teme-probe', '1');
            localStorage.removeItem('teme-probe');
            return localStorage;
        } catch {
            return null;
        }
    })();
    const read = (name) => {
        try {
            return store ? store.getItem(name) : null;
        } catch {
            return null;
        }
    };
    const write = (name, value) => {
        try {
            if (store && value === null) store.removeItem(name);
            else if (store) store.setItem(name, value);
        } catch {}
    };
    const field = (form, name) => form.elements.namedItem(name);
    const key = (make) => {
        let value = read('teme-commenter-key');
        if (!value && make && store) {
            const bytes = crypto.getRandomValues(new Uint8Array(24));
            write('teme-commenter-key', btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_'));
            value = read('teme-commenter-key');
        }
        return /^[A-Za-z0-9_-]{22,128}$/.test(value || '') ? value : '';
    };
    const keep = (form) => {
        const box = field(form, 'remember');
        const guest = form.querySelector('[data-teme-guest]');
        if (!store || !box || (guest && guest.hidden)) return;
        write('teme-commenter', box.checked
            ? JSON.stringify({ name: field(form, 'name')?.value ?? '', email: field(form, 'email')?.value ?? '', url: field(form, 'url')?.value ?? '' })
            : null);
    };
    const submit = (event) => {
        const form = event.currentTarget;
        event.preventDefault();
        reader.then(() => {
            field(form, 'token').value = key(true);
            keep(form);
            if (document.querySelector('[data-teme-thread]')) post(form);
            else HTMLFormElement.prototype.submit.call(form);
        });
    };
    const prepare = (respond) => {
        const form = respond.querySelector('[data-teme-form]');
        if (!form) return null;
        const box = form.querySelector('[data-teme-remember]');
        if (store && box) {
            box.hidden = false;
            let saved = null;
            try {
                saved = JSON.parse(read('teme-commenter') || 'null');
            } catch {}
            if (saved && typeof saved === 'object') {
                for (const name of ['name', 'email', 'url']) {
                    const input = field(form, name);
                    if (input && !input.value && typeof saved[name] === 'string') input.value = saved[name];
                }
                field(form, 'remember').checked = true;
            }
        }
        reader.then((answer) => {
            if (!answer || answer.signedIn !== true) return;
            if (typeof answer.token === 'string') field(form, '_token').value = answer.token;
            const guest = form.querySelector('[data-teme-guest]');
            const as = form.querySelector('[data-teme-as]');
            if (typeof answer.name === 'string' && guest && as) {
                guest.hidden = true;
                as.querySelector('strong').textContent = answer.name;
                as.hidden = false;
            }
        });
        respond.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && replying) {
                event.preventDefault();
                home(true);
            }
        });
        const text = field(form, 'text');
        if (text) {
            text.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    form.querySelector('button[type="submit"]').click();
                }
            });
        }
        form.addEventListener('submit', submit);
        return form;
    };
    const find = (id) => document.querySelector(`[data-teme-comment="${CSS.escape(id)}"]`);
    const fragment = (html) => {
        const template = document.createElement('template');
        template.innerHTML = html;
        return template.content;
    };
    const place = (comment, atEnd) => {
        const thread = document.querySelector('[data-teme-thread]');
        if (!thread || !comment || typeof comment.id !== 'string' || typeof comment.html !== 'string' || find(comment.id)) return;
        const parts = fragment(comment.html);
        const own = parts.querySelector(`[data-teme-comment="${CSS.escape(comment.id)}"]`);
        if (!own) return;
        if (typeof comment.parentId !== 'string') {
            if (atEnd) {
                thread.append(own);
                thread.hidden = false;
            }
            return;
        }
        const parent = find(comment.parentId);
        if (!parent) return;
        const list = parent.querySelector(':scope > [data-teme-replies]');
        if (list) {
            list.append(own);
            return;
        }
        const given = parts.querySelector(`[data-teme-comment="${CSS.escape(comment.parentId)}"] > [data-teme-replies]`);
        if (given) parent.append(given);
    };
    const mine = (form) => {
        const value = key(false);
        if (!value || !document.querySelector('[data-teme-thread]')) return;
        const path = field(form, 'path').value;
        const atEnd = new URL(path, location.href).pathname === location.pathname;
        fetch(ownComments + '?path=' + encodeURIComponent(path), { credentials: 'omit', headers: { Accept: 'application/json', 'X-Teme-Commenter': value } })
            .then((response) => (response.ok ? response.json() : null))
            .then((answer) => {
                for (const comment of answer && Array.isArray(answer.comments) ? answer.comments : []) place(comment, atEnd);
                edits();
            })
            .catch(() => {});
    };
    const post = async (form) => {
        const button = form.querySelector('button[type="submit"]');
        const status = form.querySelector('[data-teme-status]');
        if (button.getAttribute('aria-disabled') === 'true') return;
        button.setAttribute('aria-disabled', 'true');
        status.textContent = field(form, 'parent').value !== '' ? words.postingReply : words.postingComment;
        let response;
        try {
            response = await fetch(form.action, { method: 'POST', body: new URLSearchParams(new FormData(form)), credentials: 'same-origin', redirect: 'manual', headers: { Accept: 'application/json' } });
        } catch {
            button.removeAttribute('aria-disabled');
            status.textContent = words.unsent;
            return;
        }
        const answer = (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json().catch(() => null) : null;
        button.removeAttribute('aria-disabled');
        if (!answer || typeof answer.outcome !== 'string') {
            status.textContent = '';
            HTMLFormElement.prototype.submit.call(form);
            return;
        }
        if (typeof answer.form === 'string') {
            redraw(form, answer.form);
            return;
        }
        if (answer.outcome === 'posted' || answer.outcome === 'waiting') {
            place(answer.comment, true);
            if (answer.outcome === 'posted') counted();
            edits();
            posted(form);
        }
        say(typeof answer.message === 'string' ? answer.message : '');
    };
    const redraw = (form, html) => {
        const old = form.closest('[data-teme-respond]');
        const fresh = fragment(html).querySelector('[data-teme-respond]');
        if (!old || !fresh) {
            HTMLFormElement.prototype.submit.call(form);
            return;
        }
        const ticked = field(form, 'remember')?.checked === true;
        const heading = old.querySelector('#respond-h')?.textContent ?? words.heading;
        const noteWas = old.querySelector('[data-teme-replying]');
        const noteText = noteWas ? noteWas.textContent : '';
        const noteHidden = noteWas ? noteWas.hidden : true;
        old.replaceWith(fresh);
        const next = prepare(fresh);
        if (next && ticked && field(next, 'remember')) field(next, 'remember').checked = true;
        if (replying && next) {
            const parent = field(next, 'parent')?.value ?? '';
            if (parent !== '' && parent === replying.link.dataset.temeReply) {
                label(fresh, heading, parent);
                const note = fresh.querySelector('[data-teme-replying]');
                if (note) {
                    note.textContent = noteText;
                    note.hidden = noteHidden;
                }
            } else {
                home(false);
            }
        }
        const summary = fresh.querySelector('#respond-errors');
        if (summary) summary.focus();
    };
    const posted = (form) => {
        field(form, 'text').value = '';
        if (replying) {
            const link = replying.link;
            home(false);
            if (link.isConnected) link.focus();
            return;
        }
        form.querySelector('button[type="submit"]').focus();
    };
    const say = (message) => {
        const status = document.querySelector('[data-teme-respond] [data-teme-status]');
        if (!status) return;
        status.textContent = '';
        setTimeout(() => { status.textContent = message; }, 150);
    };
    let replying = null;
    const label = (respond, heading, parentId) => {
        const title = respond.querySelector('#respond-h');
        const text = respond.querySelector('label[for="c-text"]');
        const button = respond.querySelector('button[type="submit"]');
        const cancel = respond.querySelector('[data-teme-cancel]');
        if (title) title.textContent = heading;
        if (text) text.textContent = parentId ? words.reply : words.comment;
        if (button) button.textContent = parentId ? words.postReply : words.postComment;
        if (cancel) cancel.hidden = !parentId;
        field(respond.querySelector('[data-teme-form]'), 'parent').value = parentId || '';
    };
    const home = (focusBack) => {
        if (!replying) return;
        const respond = document.querySelector('[data-teme-respond]');
        const { link, placeholder } = replying;
        replying = null;
        if (respond) {
            placeholder.replaceWith(respond);
            label(respond, words.heading, '');
            const note = respond.querySelector('[data-teme-replying]');
            if (note) note.hidden = true;
        }
        if (focusBack && link.isConnected) link.focus();
    };
    const reply = (link) => {
        const respond = document.querySelector('[data-teme-respond]');
        const holder = link.closest('[data-teme-comment]');
        if (!respond || !holder || !respond.querySelector('[data-teme-cancel]')) return false;
        if (replying) home(false);
        const placeholder = document.createElement('div');
        placeholder.hidden = true;
        respond.before(placeholder);
        const list = holder.querySelector(':scope > [data-teme-replies]');
        if (list) list.before(respond);
        else holder.append(respond);
        label(respond, link.textContent.trim(), link.dataset.temeReply);
        const thread = document.querySelector('[data-teme-thread]');
        const depth = Number(thread ? thread.dataset.temeDepth : 0);
        let level = 0;
        for (let node = holder; node; node = node.parentElement ? node.parentElement.closest('[data-teme-comment]') : null) level += 1;
        const note = respond.querySelector('[data-teme-replying]');
        if (note) {
            note.textContent = words.deepest;
            note.hidden = level < depth;
        }
        replying = { link, placeholder };
        field(respond.querySelector('[data-teme-form]'), 'text').focus();
        return true;
    };
    document.addEventListener('click', (event) => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        const link = target.closest('[data-teme-reply]');
        if (link && link.closest('[data-teme-thread]')) {
            if (reply(link)) event.preventDefault();
            return;
        }
        if (target.closest('[data-teme-cancel]')) home(true);
    });
    const edits = () => reader.then((answer) => {
        const screen = answer && typeof answer.moderate === 'string' && /^\/(?!\/)/.test(answer.moderate) ? answer.moderate : '';
        if (!screen) return;
        for (const item of document.querySelectorAll('[data-teme-comment]')) {
            const own = (part) => part.closest('[data-teme-comment]') === item;
            const owned = (hook) => [...item.querySelectorAll(hook)].filter(own);
            const [actions] = owned('[data-teme-actions]');
            const [reply] = owned('[data-teme-reply]');
            if (owned('[data-teme-edit]').length || !(actions || reply)) continue;
            const name = actions ? actions.dataset.temeActions : '';
            const note = document.createElement('span');
            note.className = 'teme-sr-only';
            note.textContent = ' ' + (name ? words.editNamed.replace(':name', () => name) : words.editUnnamed);
            const link = document.createElement('a');
            link.className = 'teme-comment-edit';
            link.setAttribute('data-teme-edit', '');
            link.href = screen + '?comment=' + encodeURIComponent(item.dataset.temeComment);
            link.append(words.edit, note);
            if (actions) {
                actions.append(link);
                actions.hidden = false;
                continue;
            }
            const line = document.createElement('p');
            const replies = item.querySelector(':scope > [data-teme-replies]');
            line.append(link);
            if (replies) replies.before(line);
            else item.append(line);
        }
    });
    const counted = () => {
        const empty = document.querySelector('[data-teme-empty]');
        const count = document.querySelector('[data-teme-count]');
        if (empty) empty.hidden = true;
        if (!count) return;
        const total = (Number(count.dataset.temeCount) || 0) + 1;
        count.dataset.temeCount = String(total);
        count.textContent = total === 1 ? words.countOne : words.countMany.replace(':count', () => total.toLocaleString(document.documentElement.lang || 'en-US'));
    };
    const respond = document.querySelector('[data-teme-respond]');
    const form = respond ? prepare(respond) : null;
    if (form) mine(form);
    edits();
})();
</x-teme::inline-script>
