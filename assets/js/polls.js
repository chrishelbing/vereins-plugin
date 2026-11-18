/**
 * Umfragen – [vp_umfragen]
 *
 * Baut die Umfragen aus /polls auf. Wer noch nicht bekannt ist, nennt beim
 * ersten Abstimmen seinen Namen (Basis: Gast-Erkennung per Cookie). Gehört
 * die E-Mail zu einem Mitgliedskonto, folgt der Zugangscode; danach lädt die
 * Seite neu und die Person ist angemeldet.
 *
 * Alle Texte kommen aus PHP (PollsShortcode::texts()). Inhalte werden nur
 * über textContent gesetzt, nie als HTML.
 */
(function () {
    'use strict';

    var C = window.vpPolls || {};
    var T = C.t || {};
    var person = null;
    var pending = null; // Abstimmung, die auf die Namensangabe wartet

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null) { e.textContent = text; }
        return e;
    }

    function fmt(s, v) { return String(s).replace('%s', v).replace('%d', v); }

    function api(path, method, body) {
        return fetch(C.api + path, {
            method: method || 'GET',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        });
    }

    function dateText(o) {
        if (!o.starts_at) { return o.label; }
        var d = new Date(o.starts_at.replace(' ', 'T'));
        var s = d.toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' })
            + ', ' + o.starts_at.substr(11, 5);
        if (o.ends_at) { s += '–' + o.ends_at.substr(11, 5); }
        return o.label ? s + ' · ' + o.label : s;
    }

    function closeText(p) {
        if (!p.open) { return T.closed; }
        if (!p.closes_at) { return T.open; }
        var d = new Date(p.closes_at.replace(' ', 'T'));
        return fmt(T.openUntil, d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' }) + ' ' + p.closes_at.substr(11, 5));
    }

    // ------------------------------------------------------------------
    // Eine Umfrage
    // ------------------------------------------------------------------

    function renderPoll(p, box) {
        box.textContent = '';
        box.className = 'vp-poll' + (p.open ? '' : ' is-closed');
        box.id = 'umfrage-' + p.id;

        box.appendChild(el('h3', 'vp-poll__title', p.title));
        var meta = closeText(p) + ' · ' + (p.voters === 1 ? T.voter : fmt(T.voters, p.voters));
        box.appendChild(el('p', 'vp-poll__meta', meta));
        if (p.description) { box.appendChild(el('p', 'vp-poll__desc', p.description)); }

        var hinweise = [];
        if (p.kind === 'choice' && p.multiple) { hinweise.push(T.multiple); }
        if (p.anonymous) { hinweise.push(T.anonymous); }
        if (p.login_required) { hinweise.push(T.loginOnly); }
        if (hinweise.length) { box.appendChild(el('p', 'vp-poll__note', hinweise.join(' '))); }

        var mine = p.mine || {};
        var hasVoted = Object.keys(mine).length > 0;

        if (p.open) {
            box.appendChild(p.kind === 'date' ? dateForm(p, mine, box) : choiceForm(p, mine, box));
            if (hasVoted) {
                box.appendChild(el('p', 'vp-poll__ok', T.saved));
            }
            if (person) {
                box.appendChild(el('p', 'vp-poll__who', fmt(T.youAre, person.name)));
            }
        }

        if (p.results) {
            box.appendChild(results(p));
        } else if (hasVoted || !p.open) {
            box.appendChild(el('p', 'vp-poll__note', T.resultsLater));
        }
    }

    function dateForm(p, mine, box) {
        var form = el('form', 'vp-poll__form');
        var werte = {};
        var stufen = p.allow_maybe ? ['yes', 'maybe', 'no'] : ['yes', 'no'];

        p.options.forEach(function (o) {
            werte[o.id] = mine[o.id] || null;
            var row = el('div', 'vp-poll__row');
            row.appendChild(el('span', 'vp-poll__label', dateText(o)));
            var group = el('span', 'vp-poll__choices');
            group.setAttribute('role', 'group');
            group.setAttribute('aria-label', dateText(o));
            stufen.forEach(function (s) {
                var b = el('button', 'vp-poll__pick is-' + s, T[s]);
                b.type = 'button';
                b.setAttribute('aria-pressed', werte[o.id] === s ? 'true' : 'false');
                b.addEventListener('click', function () {
                    werte[o.id] = s;
                    group.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                });
                group.appendChild(b);
            });
            row.appendChild(group);
            form.appendChild(row);
        });

        actions(form, p, box, function () {
            var answers = {};
            Object.keys(werte).forEach(function (k) { if (werte[k]) { answers[k] = werte[k]; } });
            return { answers: answers };
        }, Object.keys(mine).length > 0);
        return form;
    }

    function choiceForm(p, mine, box) {
        var form = el('form', 'vp-poll__form');
        var name = 'vp-poll-' + p.id;
        p.options.forEach(function (o) {
            var label = el('label', 'vp-poll__option');
            var input = document.createElement('input');
            input.type = p.multiple ? 'checkbox' : 'radio';
            input.name = name;
            input.value = o.id;
            input.checked = !!mine[o.id];
            label.appendChild(input);
            label.appendChild(el('span', '', o.label));
            form.appendChild(label);
        });
        actions(form, p, box, function () {
            var choices = [];
            form.querySelectorAll('input[name="' + name + '"]:checked').forEach(function (i) { choices.push(parseInt(i.value, 10)); });
            if (!choices.length) { return null; }
            return { choices: choices };
        }, Object.keys(mine).length > 0);
        return form;
    }

    function actions(form, p, box, collect, hasVoted) {
        var err = el('p', 'vp-poll__error');
        err.hidden = true;
        var bar = el('div', 'vp-poll__actions');
        var save = el('button', 'vp-poll__save', T.save);
        save.type = 'submit';
        bar.appendChild(save);
        if (hasVoted) {
            var undo = el('button', 'vp-poll__undo', T.withdraw);
            undo.type = 'button';
            undo.addEventListener('click', function () {
                api('/polls/' + p.id + '/vote', 'DELETE').then(function (res) {
                    if (res.ok) { renderPoll(res.data, box); } else { showError(err, res.data); }
                });
            });
            bar.appendChild(undo);
        }
        form.appendChild(err);
        form.appendChild(bar);

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            err.hidden = true;
            var body = collect();
            if (!body) { err.textContent = T.chooseOne; err.hidden = false; return; }
            var send = function () {
                save.disabled = true;
                api('/polls/' + p.id + '/vote', 'POST', body).then(function (res) {
                    save.disabled = false;
                    if (res.ok) { renderPoll(res.data, box); return; }
                    if (res.data && res.data.code === 'identify_first') { person = null; askWho(send); return; }
                    showError(err, res.data);
                }).catch(function () { save.disabled = false; err.textContent = T.error; err.hidden = false; });
            };
            if (!person) { askWho(send); } else { send(); }
        });
    }

    function showError(err, data) {
        err.textContent = (data && data.message) || T.error;
        err.hidden = false;
    }

    function results(p) {
        var wrap = el('div', 'vp-poll__results');
        wrap.appendChild(el('h4', '', T.results));
        var max = 0;
        p.options.forEach(function (o) { var r = p.results[o.id]; if (r && r.yes > max) { max = r.yes; } });
        p.options.forEach(function (o) {
            var r = p.results[o.id] || { yes: 0, maybe: 0, names: { yes: [], maybe: [] } };
            var row = el('div', 'vp-poll__result' + (max && r.yes === max ? ' is-top' : ''));
            var head = el('div', 'vp-poll__resulthead');
            head.appendChild(el('span', '', p.kind === 'date' ? dateText(o) : o.label));
            var zahl = r.yes === 1 ? T.voteFor : fmt(T.votesFor, r.yes);
            if (p.kind === 'date' && r.maybe) { zahl += ' · ' + T.maybe + ': ' + r.maybe; }
            head.appendChild(el('strong', '', zahl));
            row.appendChild(head);
            var bar = el('div', 'vp-poll__bar');
            var fill = el('span', '');
            fill.style.width = (max ? Math.round(r.yes / max * 100) : 0) + '%';
            bar.appendChild(fill);
            row.appendChild(bar);
            if (!p.anonymous && r.names) {
                var namen = (r.names.yes || []).slice();
                (r.names.maybe || []).forEach(function (n) { namen.push(n + ' (' + T.maybe + ')'); });
                if (namen.length) { row.appendChild(el('p', 'vp-poll__names', namen.join(', '))); }
            }
            wrap.appendChild(row);
        });
        return wrap;
    }

    // ------------------------------------------------------------------
    // Wer bist du? – Name, E-Mail freiwillig, ggf. Zugangscode
    // ------------------------------------------------------------------

    var dialog = null;

    function askWho(then) {
        pending = then;
        if (!dialog) { dialog = buildDialog(); document.body.appendChild(dialog.root); }
        dialog.reset();
        dialog.root.hidden = false;
        dialog.name.focus();
    }

    function buildDialog() {
        var root = el('div', 'vp-poll-who');
        root.hidden = true;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        var card = el('form', 'vp-poll-who__card');
        card.appendChild(el('h3', '', T.whoTitle));
        var intro = el('p', '', T.whoText);
        card.appendChild(intro);

        var lName = el('label', '', T.name);
        var name = document.createElement('input');
        name.type = 'text'; name.autocomplete = 'name'; name.maxLength = 100;
        lName.appendChild(name);
        var lMail = el('label', '', T.email);
        var mail = document.createElement('input');
        mail.type = 'email'; mail.autocomplete = 'email';
        lMail.appendChild(mail);
        var lCode = el('label', '', T.codeTitle);
        var code = document.createElement('input');
        code.type = 'text'; code.inputMode = 'numeric'; code.maxLength = 6; code.autocomplete = 'one-time-code';
        lCode.appendChild(code);
        var codeText = el('p', 'vp-poll__note', T.codeText);
        var err = el('p', 'vp-poll__error');

        [lName, lMail, codeText, lCode, err].forEach(function (x) { card.appendChild(x); });

        var bar = el('div', 'vp-poll__actions');
        var ok = el('button', 'vp-poll__save', T.continue); ok.type = 'submit';
        var cancel = el('button', 'vp-poll__undo', T.cancel); cancel.type = 'button';
        bar.appendChild(ok); bar.appendChild(cancel);
        card.appendChild(bar);
        root.appendChild(card);

        var codeFor = null;
        function reset() {
            codeFor = null;
            [lName, lMail].forEach(function (x) { x.hidden = false; });
            [lCode, codeText].forEach(function (x) { x.hidden = true; });
            err.hidden = true; code.value = '';
        }
        cancel.addEventListener('click', function () { root.hidden = true; pending = null; });

        card.addEventListener('submit', function (e) {
            e.preventDefault();
            err.hidden = true;
            if (codeFor) {
                var c = code.value.replace(/\s+/g, '');
                if (!/^\d{6}$/.test(c)) { err.textContent = T.codeNeeded; err.hidden = false; return; }
                api('/auth/verify-otp', 'POST', { identifier: codeFor, code: c, device: 'Umfrage' }).then(function (res) {
                    if (!res.ok) { showError(err, res.data); return; }
                    window.location.reload();
                });
                return;
            }
            var n = name.value.trim();
            if (!n) { err.textContent = T.nameNeeded; err.hidden = false; return; }
            ok.disabled = true;
            api('/polls/identify', 'POST', { name: n, email: mail.value.trim(), return_url: C.here }).then(function (res) {
                ok.disabled = false;
                if (!res.ok) { showError(err, res.data); return; }
                if (res.data.code_required) {
                    codeFor = mail.value.trim();
                    [lName, lMail].forEach(function (x) { x.hidden = true; });
                    [lCode, codeText].forEach(function (x) { x.hidden = false; });
                    code.focus();
                    return;
                }
                person = res.data.person;
                root.hidden = true;
                var go = pending; pending = null;
                if (go) { go(); }
            }).catch(function () { ok.disabled = false; err.textContent = T.error; err.hidden = false; });
        });

        return { root: root, name: name, reset: reset };
    }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    function start(container) {
        var id = parseInt(container.getAttribute('data-poll') || '0', 10);
        api('/polls' + (id ? '?id=' + id : '')).then(function (res) {
            container.textContent = '';
            if (!res.ok) { container.appendChild(el('p', 'vp-poll__error', T.error)); return; }
            person = res.data.person;
            if (!res.data.polls.length) { container.appendChild(el('p', 'vp-polls__empty', T.none)); return; }
            res.data.polls.forEach(function (p) {
                var box = el('section', 'vp-poll');
                container.appendChild(box);
                renderPoll(p, box);
            });
            if (location.hash && /^#umfrage-\d+$/.test(location.hash)) {
                var ziel = document.querySelector(location.hash);
                if (ziel) { ziel.scrollIntoView(); }
            }
        }).catch(function () {
            container.textContent = '';
            container.appendChild(el('p', 'vp-poll__error', T.error));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.vp-polls').forEach(start);
    });
})();
