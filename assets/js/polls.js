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
        updateHead(p);
        if (p.kind === 'entry') { renderEntry(p, box); return; }
        if (p.kind === 'text') { renderTexts(p, box); return; }
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
        } else if (p.results_rule === 'orga' || p.results_rule === 'nobody') {
            box.appendChild(el('p', 'vp-poll__note', T.resultsOrga));
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

    function actions(form, p, box, collect, hasVoted, emptyText, saveText, undoText) {
        var err = el('p', 'vp-poll__error');
        err.hidden = true;
        var bar = el('div', 'vp-poll__actions');
        var save = el('button', 'vp-poll__save', saveText || T.save);
        save.type = 'submit';
        bar.appendChild(save);
        if (hasVoted) {
            var undo = el('button', 'vp-poll__undo', undoText || T.withdraw);
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
            if (!body) { err.textContent = emptyText || T.chooseOne; err.hidden = false; return; }
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
    // Ideensammlung
    // ------------------------------------------------------------------

    function renderTexts(p, box) {
        updateHead(p);
        box.textContent = '';
        box.className = 'vp-poll vp-poll--text' + (p.open ? '' : ' is-closed');
        box.id = 'umfrage-' + p.id;

        box.appendChild(el('h3', 'vp-poll__title', p.title));
        box.appendChild(el('p', 'vp-poll__meta', closeText(p) + ' · ' + (p.voters === 1 ? T.ideaPerson : fmt(T.ideaPersons, p.voters))));
        if (p.description) { box.appendChild(el('p', 'vp-poll__desc', p.description)); }

        var hinweise = [p.max_texts === 1 ? T.ideaOne : fmt(T.ideaMax, p.max_texts)];
        if (p.anonymous) { hinweise.push(T.anonymous); }
        if (p.login_required) { hinweise.push(T.loginOnly); }
        box.appendChild(el('p', 'vp-poll__note', hinweise.join(' ')));

        var mine = p.mine || [];
        if (p.open) {
            box.appendChild(textForm(p, mine, box));
        }

        if (p.results) {
            var wrap = el('div', 'vp-poll__results');
            wrap.appendChild(el('h4', '', fmt(T.ideasAll, p.results.length)));
            if (!p.results.length) { wrap.appendChild(el('p', 'vp-poll__note', T.ideasNone)); }
            var ul = el('ul', 'vp-ideas');
            p.results.forEach(function (r) {
                var li = el('li', 'vp-ideas__item' + (r.mine ? ' is-mine' : ''));
                li.appendChild(el('span', 'vp-ideas__text', r.text));
                if (r.name) { li.appendChild(el('span', 'vp-ideas__name', r.name)); }
                ul.appendChild(li);
            });
            wrap.appendChild(ul);
            box.appendChild(wrap);
        } else if (p.results_rule === 'orga' || p.results_rule === 'nobody') {
            box.appendChild(el('p', 'vp-poll__note', T.ideasOrga));
        } else if (mine.length || !p.open) {
            box.appendChild(el('p', 'vp-poll__note', T.ideasLater));
        }
    }

    function textForm(p, mine, box) {
        var form = el('form', 'vp-poll__form vp-ideas__form');
        var felder = [];
        var anzahl = Math.max(1, Math.min(p.max_texts, mine.length + 1));

        function feld(wert) {
            var l = el('label', 'vp-entry__field');
            l.appendChild(document.createTextNode(fmt(T.ideaNo, felder.length + 1)));
            var t = document.createElement('textarea');
            t.rows = 2; t.maxLength = p.text_max; t.value = wert || '';
            l.appendChild(t);
            felder.push(t);
            liste.appendChild(l);
            mehr.hidden = felder.length >= p.max_texts;
        }

        var liste = el('div', 'vp-entry__rows');
        form.appendChild(liste);
        var mehr = el('button', 'vp-entry__add', T.ideaAdd);
        mehr.type = 'button';
        mehr.addEventListener('click', function () { if (felder.length < p.max_texts) { feld(''); felder[felder.length - 1].focus(); } });
        for (var i = 0; i < anzahl; i++) { feld(mine[i] || ''); }
        form.appendChild(mehr);

        actions(form, p, box, function () {
            var texts = felder.map(function (t) { return t.value.trim(); }).filter(function (t) { return t !== ''; });
            if (!texts.length) { return null; }
            return { texts: texts };
        }, mine.length > 0, T.ideaWrite, T.ideaSave, T.ideaWithdraw);
        if (mine.length) { form.appendChild(el('p', 'vp-poll__ok', T.ideaSaved)); }
        if (person) { form.appendChild(el('p', 'vp-poll__who', fmt(T.ideaYouAre, person.name))); }
        return form;
    }

    // ------------------------------------------------------------------
    // Wettkampfmeldung
    // ------------------------------------------------------------------

    function dayText(d) {
        return new Date(d.substr(0, 10) + 'T12:00:00').toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function weightText(w) {
        return w ? w.replace('.', ',') + ' kg' : T.near;
    }

    function eligible(p, year, sex) {
        return p.classes.filter(function (c) {
            return year >= c.from && year <= c.to && c.sex.indexOf(sex) !== -1;
        });
    }

    function renderEntry(p, box) {
        updateHead(p);
        box.textContent = '';
        box.className = 'vp-poll vp-poll--entry' + (p.open ? '' : ' is-closed');
        box.id = 'umfrage-' + p.id;

        box.appendChild(el('h3', 'vp-poll__title', p.title));
        var meta = [];
        if (p.event_date) { meta.push(fmt(T.eventOn, dayText(p.event_date))); }
        if (!p.open) { meta.push(T.entryClosed); }
        else if (p.closes_at) { meta.push(fmt(T.entryUntil, dayText(p.closes_at) + ' ' + p.closes_at.substr(11, 5))); }
        meta.push(p.starters === 1 ? T.starter : fmt(T.starters, p.starters));
        box.appendChild(el('p', 'vp-poll__meta', meta.join(' · ')));
        if (p.description) { box.appendChild(el('p', 'vp-poll__desc', p.description)); }

        if (p.classes.length) {
            var liste = el('ul', 'vp-entry__classes');
            p.classes.forEach(function (c) {
                var li = el('li', '');
                li.appendChild(el('strong', '', c.key));
                var zusatz = ' ' + fmt(T.yearsOf, c.years);
                if (c.sex !== 'mw') { zusatz += ' · ' + (c.sex === 'm' ? T.onlyM : T.onlyW); }
                if (c.near) { zusatz += ' · ' + T.near; }
                li.appendChild(document.createTextNode(zusatz));
                liste.appendChild(li);
            });
            box.appendChild(liste);
        }

        var hinweise = [T.entryPrivacy];
        if (p.login_required) { hinweise.push(T.loginOnly); }
        box.appendChild(el('p', 'vp-poll__note', hinweise.join(' ')));

        if (!p.open) {
            if (p.entries.length) {
                box.appendChild(el('h4', '', T.yourEntry));
                var ul = el('ul', 'vp-entry__mine');
                p.entries.forEach(function (e) {
                    ul.appendChild(el('li', '', e.first_name + ' ' + e.last_name + ' (' + e.birth_year + ') – ' + e.class_key + ' ' + e.sex + ', ' + weightText(e.weight)));
                });
                box.appendChild(ul);
            }
            return;
        }
        if (!p.classes.length) {
            box.appendChild(el('p', 'vp-poll__note', T.noClasses));
            return;
        }
        box.appendChild(entryForm(p, box));
    }

    function entryForm(p, box) {
        var form = el('form', 'vp-poll__form vp-entry');
        var rows = el('div', 'vp-entry__rows');
        var jahre = [];
        var min = 9999, max = 0;
        p.classes.forEach(function (c) { min = Math.min(min, c.from); max = Math.max(max, c.to); });
        min = Math.max(min, max - 80);
        for (var y = max; y >= min; y--) { jahre.push(y); }

        function sel(cls, label, options, value) {
            var l = el('label', 'vp-entry__field ' + cls, label);
            var s = document.createElement('select');
            options.forEach(function (o) {
                var opt = el('option', '', o[1]);
                opt.value = o[0];
                if (String(o[0]) === String(value)) { opt.selected = true; }
                s.appendChild(opt);
            });
            l.appendChild(s);
            return { label: l, input: s };
        }

        function text(cls, label, value, max) {
            var l = el('label', 'vp-entry__field ' + cls, label);
            var i = document.createElement('input');
            i.type = 'text'; i.maxLength = max; i.value = value || '';
            l.appendChild(i);
            return { label: l, input: i };
        }

        function addRow(e) {
            e = e || {};
            var row = el('fieldset', 'vp-entry__row');
            var legend = el('legend', '', '');
            row.appendChild(legend);
            var vor = text('is-first', T.firstName, e.first_name, 60);
            var nach = text('is-last', T.lastName, e.last_name, 60);
            var jg = sel('is-year', T.birthYear, [['', '–']].concat(jahre.map(function (y) { return [y, String(y)]; })), e.birth_year || '');
            var sex = sel('is-sex', T.sex, [['', '–'], ['w', T.female], ['m', T.male]], e.sex || '');
            var klasse = sel('is-class', T.ageClass, [], '');
            var gewicht = sel('is-weight', T.weightClass, [], '');
            var naehe = el('p', 'vp-entry__near', T.nearText);
            var notiz = text('is-note', T.note, e.note, 255);
            var weg = el('button', 'vp-entry__remove', T.removeStarter);
            weg.type = 'button';
            weg.addEventListener('click', function () {
                rows.removeChild(row);
                if (!rows.children.length) { addRow(); }
                nummern();
            });

            function update(keepKey, keepWeight) {
                var y = parseInt(jg.input.value, 10);
                var s = sex.input.value;
                var passend = y && s ? eligible(p, y, s) : [];
                var alt = keepKey !== undefined ? keepKey : klasse.input.value;
                klasse.input.textContent = '';
                if (!passend.length) {
                    var o = el('option', '', y && s ? T.noClass : T.chooseYearSex); o.value = '';
                    klasse.input.appendChild(o);
                } else {
                    if (passend.length > 1) { var o0 = el('option', '', T.pick); o0.value = ''; klasse.input.appendChild(o0); }
                    passend.forEach(function (c) {
                        var o2 = el('option', '', c.key); o2.value = c.key;
                        if (c.key === alt || passend.length === 1) { o2.selected = true; }
                        klasse.input.appendChild(o2);
                    });
                }
                klasse.input.disabled = passend.length < 2;
                var c = passend.filter(function (x) { return x.key === klasse.input.value; })[0];
                var w = keepWeight !== undefined ? keepWeight : gewicht.input.value;
                gewicht.input.textContent = '';
                naehe.hidden = !(c && c.near);
                gewicht.label.hidden = !!(c && c.near);
                var liste = c && !c.near ? c[s] || [] : [];
                var o3 = el('option', '', c ? T.pick : '–'); o3.value = ''; gewicht.input.appendChild(o3);
                liste.forEach(function (g) {
                    var o4 = el('option', '', weightText(g)); o4.value = g;
                    if (g === w) { o4.selected = true; }
                    gewicht.input.appendChild(o4);
                });
                gewicht.input.disabled = !liste.length;
            }
            jg.input.addEventListener('change', function () { update(); });
            sex.input.addEventListener('change', function () { update(); });
            klasse.input.addEventListener('change', function () { update(klasse.input.value); });

            [vor, nach, jg, sex, klasse, gewicht].forEach(function (f) { row.appendChild(f.label); });
            row.appendChild(naehe);
            row.appendChild(notiz.label);
            row.appendChild(weg);
            rows.appendChild(row);
            update(e.class_key || '', e.weight || '');
            row._get = function () {
                return {
                    first_name: vor.input.value.trim(), last_name: nach.input.value.trim(),
                    birth_year: parseInt(jg.input.value, 10) || 0, sex: sex.input.value,
                    class_key: klasse.input.value, weight: gewicht.input.value, note: notiz.input.value.trim()
                };
            };
            row._empty = function () { return !vor.input.value.trim() && !nach.input.value.trim(); };
            row._fill = function (name) {
                var i = name.lastIndexOf(' ');
                vor.input.value = i > 0 ? name.substr(0, i) : name;
                nach.input.value = i > 0 ? name.substr(i + 1) : '';
                jg.input.focus();
            };
            nummern();
            return row;
        }

        function nummern() {
            Array.prototype.forEach.call(rows.children, function (r, i) {
                r.querySelector('legend').textContent = fmt(T.starterNo, i + 1);
            });
        }

        (p.entries.length ? p.entries : [null]).forEach(function (e) { addRow(e); });

        // Gemerkte Namen: antippen trägt ein, × nimmt den Namen aus der Liste
        // (dieselbe Liste wie die Begleitpersonen bei Terminen).
        var chips = el('div', 'vp-entry__saved');
        function renderChips(namen) {
            chips.textContent = '';
            chips.hidden = !namen.length;
            if (!namen.length) { return; }
            chips.appendChild(el('span', 'vp-entry__savedlabel', T.savedNames));
            namen.forEach(function (n) {
                var chip = el('span', 'vp-entry__chip');
                var b = el('button', 'vp-entry__chipname', n);
                b.type = 'button';
                b.addEventListener('click', function () {
                    var leer = Array.prototype.filter.call(rows.children, function (r) { return r._empty(); })[0];
                    if (!leer) {
                        if (rows.children.length >= p.max_rows) { return; }
                        leer = addRow();
                    }
                    leer._fill(n);
                });
                var x = el('button', 'vp-entry__chipforget', '×');
                x.type = 'button';
                x.title = fmt(T.forgetName, n);
                x.setAttribute('aria-label', fmt(T.forgetName, n));
                x.addEventListener('click', function () {
                    if (!window.confirm(fmt(T.forgetNameAsk, n))) { return; }
                    api('/polls/saved-names', 'DELETE', { name: n }).then(function (res) {
                        if (res.ok) { p.saved_names = res.data.saved_names || []; renderChips(p.saved_names); }
                    });
                });
                chip.appendChild(b);
                chip.appendChild(x);
                chips.appendChild(chip);
            });
        }
        renderChips(p.saved_names || []);
        form.appendChild(chips);

        form.appendChild(rows);
        var mehr = el('button', 'vp-entry__add', T.addStarter);
        mehr.type = 'button';
        mehr.addEventListener('click', function () {
            if (rows.children.length < p.max_rows) { addRow(); }
        });
        form.appendChild(mehr);

        var err = el('p', 'vp-poll__error'); err.hidden = true;
        var bar = el('div', 'vp-poll__actions');
        var save = el('button', 'vp-poll__save', p.entries.length ? T.entryUpdate : T.entrySave);
        save.type = 'submit';
        bar.appendChild(save);
        if (p.entries.length) {
            var undo = el('button', 'vp-poll__undo', T.entryWithdraw);
            undo.type = 'button';
            undo.addEventListener('click', function () {
                if (!window.confirm(T.entryWithdrawAsk)) { return; }
                api('/polls/' + p.id + '/entries', 'DELETE').then(function (res) {
                    if (res.ok) { renderEntry(res.data, box); } else { showError(err, res.data); }
                });
            });
            bar.appendChild(undo);
        }
        form.appendChild(err);
        form.appendChild(bar);
        if (p.entries.length) {
            form.appendChild(el('p', 'vp-poll__ok', fmt(p.entries.length === 1 ? T.entrySavedOne : T.entrySaved, p.entries.length)));
        }
        if (person) { form.appendChild(el('p', 'vp-poll__who', fmt(T.youReport, person.name))); }

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            err.hidden = true;
            var starter = [];
            Array.prototype.forEach.call(rows.children, function (r) { if (!r._empty()) { starter.push(r._get()); } });
            if (!starter.length) { err.textContent = T.noStarter; err.hidden = false; return; }
            var send = function () {
                save.disabled = true;
                api('/polls/' + p.id + '/entries', 'POST', { starters: starter }).then(function (res) {
                    save.disabled = false;
                    if (res.ok) { renderEntry(res.data, box); box.scrollIntoView({ block: 'start' }); return; }
                    var code = res.data && res.data.code;
                    if (code === 'identify_first') { person = null; askWho(send, true); return; }
                    if (code === 'contact_required') { askWho(send, true); return; }
                    showError(err, res.data);
                }).catch(function () { save.disabled = false; err.textContent = T.error; err.hidden = false; });
            };
            if (!person || (person.guest && !person.contact)) { askWho(send, true); } else { send(); }
        });
        return form;
    }

    // ------------------------------------------------------------------
    // Wer bist du? – Name, E-Mail freiwillig, ggf. Zugangscode
    // ------------------------------------------------------------------

    var dialog = null;

    function askWho(then, needContact) {
        pending = then;
        if (!dialog) { dialog = buildDialog(); document.body.appendChild(dialog.root); }
        dialog.reset(!!needContact);
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
        var lPhone = el('label', '', T.phone);
        var phone = document.createElement('input');
        phone.type = 'tel'; phone.autocomplete = 'tel'; phone.maxLength = 30;
        lPhone.appendChild(phone);
        var lCode = el('label', '', T.codeTitle);
        var code = document.createElement('input');
        code.type = 'text'; code.inputMode = 'numeric'; code.maxLength = 6; code.autocomplete = 'one-time-code';
        lCode.appendChild(code);
        var codeText = el('p', 'vp-poll__note', T.codeText);
        var err = el('p', 'vp-poll__error');

        [lName, lMail, lPhone, codeText, lCode, err].forEach(function (x) { card.appendChild(x); });

        var bar = el('div', 'vp-poll__actions');
        var ok = el('button', 'vp-poll__save', T.continue); ok.type = 'submit';
        var cancel = el('button', 'vp-poll__undo', T.cancel); cancel.type = 'button';
        bar.appendChild(ok); bar.appendChild(cancel);
        card.appendChild(bar);
        root.appendChild(card);

        var codeFor = null;
        var contact = false;
        function reset(needContact) {
            codeFor = null;
            contact = needContact;
            intro.textContent = needContact ? T.whoTextContact : T.whoText;
            lMail.firstChild.textContent = needContact ? T.emailPlain : T.email;
            [lName, lMail].forEach(function (x) { x.hidden = false; });
            lPhone.hidden = !needContact;
            [lCode, codeText].forEach(function (x) { x.hidden = true; });
            err.hidden = true; code.value = '';
            if (person && person.guest && person.name) { name.value = person.name; }
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
            if (contact && !mail.value.trim() && !phone.value.trim()) { err.textContent = T.contactNeeded; err.hidden = false; return; }
            ok.disabled = true;
            api('/polls/identify', 'POST', { name: n, email: mail.value.trim(), phone: contact ? phone.value.trim() : '', return_url: C.here }).then(function (res) {
                ok.disabled = false;
                if (!res.ok) { showError(err, res.data); return; }
                if (res.data.code_required) {
                    codeFor = mail.value.trim();
                    [lName, lMail, lPhone].forEach(function (x) { x.hidden = true; });
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

    // ------------------------------------------------------------------
    // Übersicht: jede Umfrage eine zuklappbare Karte. Kopfzeile mit Titel,
    // Art, Stand und „mitgemacht“; aufgeklappt ist höchstens eine, damit die
    // Liste auch auf dem Handy überschaubar bleibt.
    // ------------------------------------------------------------------

    var cards = {};

    function participated(p) {
        if (p.kind === 'entry') { return p.entries && p.entries.length ? fmt(p.entries.length === 1 ? T.mineStarter : T.mineStarters, p.entries.length) : ''; }
        if (p.kind === 'text') { return p.mine && p.mine.length ? T.mineDone : ''; }
        return p.mine && Object.keys(p.mine).length ? T.mineDone : '';
    }

    function kindText(k) {
        return { date: T.kindDate, choice: T.kindChoice, text: T.kindText, entry: T.kindEntry }[k] || '';
    }

    function updateHead(p) {
        var c = cards[p.id];
        if (!c) { return; }
        c.title.textContent = p.title;
        var teile = [kindText(p.kind), p.kind === 'entry' && p.event_date ? fmt(T.eventOn, dayText(p.event_date)) : closeText(p)];
        c.meta.textContent = teile.join(' · ');
        var mit = participated(p);
        c.badge.textContent = mit;
        c.badge.hidden = !mit;
        c.card.classList.toggle('is-closed', !p.open);
    }

    function setOpen(id, auf, scroll) {
        Object.keys(cards).forEach(function (k) {
            var c = cards[k];
            var dies = String(k) === String(id) && auf;
            c.head.setAttribute('aria-expanded', dies ? 'true' : 'false');
            c.body.hidden = !dies;
            c.card.classList.toggle('is-open', dies);
        });
        if (auf && cards[id]) {
            if (window.history && history.replaceState) { history.replaceState(null, '', '#umfrage-' + id); }
            if (scroll) { cards[id].card.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
        } else if (window.history && history.replaceState && /^#umfrage-/.test(location.hash)) {
            history.replaceState(null, '', location.pathname + location.search);
        }
    }

    function card(p, list) {
        var c = {};
        c.card = el('article', 'vp-pollcard');
        c.head = el('button', 'vp-pollcard__head');
        c.head.type = 'button';
        c.head.setAttribute('aria-expanded', 'false');
        c.head.setAttribute('aria-controls', 'umfrage-' + p.id);
        c.title = el('span', 'vp-pollcard__title');
        c.meta = el('span', 'vp-pollcard__meta');
        c.badge = el('span', 'vp-pollcard__badge');
        var text = el('span', 'vp-pollcard__text');
        text.appendChild(c.title);
        text.appendChild(c.meta);
        c.head.appendChild(text);
        c.head.appendChild(c.badge);
        c.head.appendChild(el('span', 'vp-pollcard__chevron'));
        c.body = el('section', 'vp-poll');
        c.body.hidden = true;
        c.head.addEventListener('click', function () {
            setOpen(p.id, c.head.getAttribute('aria-expanded') !== 'true', false);
        });
        c.card.appendChild(c.head);
        c.card.appendChild(c.body);
        list.appendChild(c.card);
        cards[p.id] = c;
        renderPoll(p, c.body);
    }

    function start(container) {
        var id = parseInt(container.getAttribute('data-poll') || '0', 10);
        api('/polls' + (id ? '?id=' + id : '')).then(function (res) {
            container.textContent = '';
            if (!res.ok) { container.appendChild(el('p', 'vp-poll__error', T.error)); return; }
            person = res.data.person;
            var polls = res.data.polls;
            if (!polls.length) { container.appendChild(el('p', 'vp-polls__empty', T.none)); return; }

            // Eine einzelne Umfrage (Shortcode mit id) ohne Klappliste.
            if (id || polls.length === 1) {
                var box = el('section', 'vp-poll');
                container.appendChild(box);
                renderPoll(polls[0], box);
                return;
            }

            var offen = polls.filter(function (p) { return p.open; });
            var zu = polls.filter(function (p) { return !p.open; });
            [[T.groupOpen, offen], [T.groupClosed, zu]].forEach(function (g) {
                if (!g[1].length) { return; }
                container.appendChild(el('h3', 'vp-polls__group', g[0]));
                var list = el('div', 'vp-polls__list');
                container.appendChild(list);
                g[1].forEach(function (p) { card(p, list); });
            });

            var m = /^#umfrage-(\d+)$/.exec(location.hash || '');
            if (m && cards[m[1]]) { setOpen(m[1], true, true); }
            else if (offen.length === 1) { setOpen(offen[0].id, true, false); }
        }).catch(function () {
            container.textContent = '';
            container.appendChild(el('p', 'vp-poll__error', T.error));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.vp-polls').forEach(start);
    });
})();
