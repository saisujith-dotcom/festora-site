(function () {
    'use strict';

    var base = document.body.getAttribute('data-base') || '';

    // ---- Mobile nav toggle ----
    var toggle = document.querySelector('.nav-toggle');
    var links = document.getElementById('nav-links');
    if (toggle && links) {
        toggle.addEventListener('click', function () {
            var open = links.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && links.classList.contains('open')) {
                links.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    }

    // ---- Confirm dialogs on destructive forms ----
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
        });
    });

    // ---- Client-side form validation (server validates again) ----
    function showError(input, msg) {
        input.classList.add('invalid');
        input.setAttribute('aria-invalid', 'true');
        var err = input.parentNode.querySelector('.field-error[data-for="' + input.id + '"]');
        if (!err) {
            err = document.createElement('div');
            err.className = 'field-error';
            err.id = 'error-' + input.id;
            err.setAttribute('data-for', input.id);
            input.insertAdjacentElement('afterend', err);
        }
        input.setAttribute('aria-describedby', err.id);
        err.textContent = msg;
    }
    function clearError(input) {
        input.classList.remove('invalid');
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
        var err = input.parentNode.querySelector('.field-error[data-for="' + input.id + '"]');
        if (err) err.remove();
    }
    document.querySelectorAll('form[data-validate]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var ok = true;
            form.querySelectorAll('input, select, textarea').forEach(function (inp) {
                if (!inp.id || inp.type === 'hidden') return;
                clearError(inp);
                if (!inp.checkValidity()) {
                    ok = false;
                    showError(inp, inp.validationMessage || 'Please check this field.');
                }
            });
            var p1 = form.querySelector('#password'), p2 = form.querySelector('#password2');
            if (p1 && p2 && p1.value !== p2.value) {
                ok = false;
                showError(p2, 'Passwords do not match.');
            }
            if (p1 && p2 && p1.value && !/^(?=.*[A-Za-z])(?=.*\d).{8,}$/.test(p1.value)) {
                ok = false;
                showError(p1, 'Use at least 8 characters with a letter and a digit.');
            }
            if (!ok) {
                ev.preventDefault();
                var firstBad = form.querySelector('.invalid');
                if (firstBad) firstBad.focus();
            }
        });
        form.addEventListener('input', function (ev) {
            if (ev.target.id) clearError(ev.target);
        });
    });

    // ---- AJAX event search / filter (JSON API) ----
    var grid = document.getElementById('events-grid');
    var form = document.getElementById('filter-form');
    if (grid && form) {
        var count = document.getElementById('result-count');
        var timer = null;

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        }
        function safeUrl(u) { return /^https?:\/\//i.test(u) ? u : ''; }

        function card(ev) {
            var a = el('article', 'card event-card');
            var img = el('div', 'card-img');
            img.setAttribute('data-cat', ev.category);
            if (ev.image_url && safeUrl(ev.image_url)) {
                var bg = el('div', 'card-img-bg');
                bg.style.backgroundImage = 'url("' + encodeURI(safeUrl(ev.image_url)) + '")';
                img.appendChild(bg);
            } else {
                img.classList.add('is-empty');
            }
            img.appendChild(el('span', 'badge', ev.category));
            var body = el('div', 'card-body');
            var h3 = el('h3');
            var link = el('a', '', ev.title);
            link.href = ev.url;
            h3.appendChild(link);
            body.appendChild(h3);
            body.appendChild(el('p', 'meta', ev.date_label + ' · ' + ev.time_label));
            body.appendChild(el('p', 'meta', ev.venue));
            var cap = Number(ev.capacity) || 0;
            var pct = cap > 0 ? Math.min(100, Math.round(Math.max(0, cap - ev.seats_left) / cap * 100)) : 0;
            var bar = el('div', 'seat-bar');
            bar.setAttribute('aria-hidden', 'true');
            var fill = el('span');
            fill.style.width = pct + '%';
            bar.appendChild(fill);
            body.appendChild(bar);
            var full = ev.seats_left === 0;
            body.appendChild(el('p', 'seats' + (full ? ' full' : ''),
                full ? 'Registration full' : ev.seats_left + ' of ' + ev.capacity + ' seats left'));
            var btn = el('a', 'btn btn-sm btn-outline', 'View details');
            btn.href = ev.url;
            body.appendChild(btn);
            a.appendChild(img);
            a.appendChild(body);
            return a;
        }

        function load() {
            var params = new URLSearchParams(new FormData(form));
            grid.setAttribute('aria-busy', 'true');
            fetch(base + '/api/events.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (!r.ok) throw new Error('Request failed');
                    return r.json();
                })
                .then(function (data) {
                    grid.innerHTML = '';
                    grid.removeAttribute('aria-busy');
                    if (!data.events.length) {
                        grid.appendChild(el('p', 'empty', 'No events match your search.'));
                    } else {
                        data.events.forEach(function (ev) { grid.appendChild(card(ev)); });
                    }
                    count.textContent = data.count + (data.count === 1 ? ' event found' : ' events found');
                })
                .catch(function () {
                    grid.innerHTML = '';
                    grid.removeAttribute('aria-busy');
                    var error = el('p', 'alert alert-error', 'Could not load events. Please try again.');
                    error.setAttribute('role', 'alert');
                    grid.appendChild(error);
                });
        }

        form.addEventListener('submit', function (ev) { ev.preventDefault(); load(); });
        form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 250); });
        form.addEventListener('change', load);
        load();
    }
})();
