/**
 * Klassen-Editor im Backend (Klassen-Vorlagen und Wettkampfmeldung).
 *
 * Baut aus data-classes eine Tabelle mit Formularfeldern. Modus „age“
 * (Vorlage): Alter von–bis. Modus „year“ (Meldung): Jahrgänge, umgerechnet
 * mit dem Jahr aus dem Feld #vp-poll-event-date; ändert sich das Jahr,
 * wandern alle Jahrgänge mit. Eine Vorlage lässt sich über
 * window.vpPollClassesLoad(klassen) einsetzen.
 *
 * Texte kommen aus PHP (ClassesAdmin::texts()).
 */
(function () {
    'use strict';

    var T = (window.vpPollClasses && window.vpPollClasses.t) || {};

    function el(tag, attrs, text) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        if (text !== undefined) { e.textContent = text; }
        return e;
    }

    function plain(list) {
        // '-28' wird im Feld zu '28', '+55' bleibt '+55'
        return (list || []).map(function (w) { return w.charAt(0) === '-' ? w.substr(1) : w; }).join(' ');
    }

    function eventYear() {
        var f = document.getElementById('vp-poll-event-date');
        return f && f.value ? parseInt(f.value.substr(0, 4), 10) : 0;
    }

    function setup(box) {
        var field = box.getAttribute('data-field');
        var mode = box.getAttribute('data-mode');
        var classes = JSON.parse(box.getAttribute('data-classes') || '[]');
        var table = el('table', { 'class': 'vp-table vp-class-table' });
        var head = el('tr');
        if (mode === 'year') { head.appendChild(el('th', {}, T.on)); }
        [T.key, mode === 'year' ? T.years : T.age, T.sex, T.near, T.wm, T.ww, ''].forEach(function (h) { head.appendChild(el('th', {}, h)); });
        var thead = el('thead'); thead.appendChild(head); table.appendChild(thead);
        var body = el('tbody'); table.appendChild(body);
        var counter = 0;
        var lastYear = eventYear();

        function input(name, value, attrs) {
            var i = el('input', Object.assign({ type: 'text', name: field + '[' + counter + '][' + name + ']' }, attrs || {}));
            i.value = value === undefined || value === null ? '' : value;
            return i;
        }

        function addRow(c, on) {
            c = c || { key: '', from: '', to: '', sex: 'mw', near: false, m: [], w: [] };
            var tr = el('tr');
            if (mode === 'year') {
                var td0 = el('td');
                var cb = input('on', '1', { type: 'checkbox' });
                cb.checked = on !== false;
                td0.appendChild(cb); tr.appendChild(td0);
            }
            var td1 = el('td'); td1.appendChild(input('key', c.key, { size: 8, maxlength: 20 })); tr.appendChild(td1);

            var td2 = el('td'); td2.style.whiteSpace = 'nowrap';
            var a, b, y = eventYear();
            if (mode === 'year') {
                var alt = c.to >= 99 ? '' : (y && c.to !== '' ? y - c.to : '');
                var jung = y && c.from !== '' ? y - c.from : '';
                a = input('from', alt, { type: 'number', min: 1900, max: 2100, style: 'width:6.5em', placeholder: T.older, title: T.older, 'class': 'vp-year' });
                b = input('to', jung, { type: 'number', min: 1900, max: 2100, style: 'width:6.5em', 'class': 'vp-year' });
            } else {
                a = input('from', c.from, { type: 'number', min: 0, max: 99, style: 'width:4.5em' });
                b = input('to', c.to >= 99 ? '' : c.to, { type: 'number', min: 0, max: 99, style: 'width:4.5em', placeholder: '∞' });
            }
            td2.appendChild(a); td2.appendChild(document.createTextNode(' – ')); td2.appendChild(b);
            tr.appendChild(td2);

            var td3 = el('td');
            var sel = el('select', { name: field + '[' + counter + '][sex]' });
            ['mw', 'm', 'w'].forEach(function (s) { var o = el('option', { value: s }, T[s]); if (c.sex === s) { o.selected = true; } sel.appendChild(o); });
            td3.appendChild(sel); tr.appendChild(td3);

            var td4 = el('td');
            var near = input('near', '1', { type: 'checkbox' });
            near.checked = !!c.near;
            td4.appendChild(near); tr.appendChild(td4);

            var td5 = el('td'); var wm = input('m', plain(c.m), { style: 'width:100%;min-width:14em' }); td5.appendChild(wm); tr.appendChild(td5);
            var td6 = el('td'); var ww = input('w', plain(c.w), { style: 'width:100%;min-width:14em' }); td6.appendChild(ww); tr.appendChild(td6);

            function sync() {
                // nur abblenden, nicht abschalten – sonst gingen die Gewichte beim Speichern verloren
                [wm, ww].forEach(function (f) { f.readOnly = near.checked; f.style.opacity = near.checked ? '0.45' : ''; });
            }
            near.addEventListener('change', sync); sync();

            var td7 = el('td');
            var rm = el('button', { type: 'button', 'class': 'button-link' }, T.remove);
            rm.addEventListener('click', function () { tr.parentNode.removeChild(tr); });
            td7.appendChild(rm); tr.appendChild(td7);

            body.appendChild(tr);
            counter++;
        }

        function load(list) {
            body.textContent = '';
            (list || []).forEach(function (c) { addRow(c, true); });
        }

        load(classes);
        box.appendChild(table);
        var add = el('button', { type: 'button', 'class': 'button' }, T.add);
        add.addEventListener('click', function () { addRow(null, true); });
        box.appendChild(add);

        if (mode === 'year') {
            var hint = el('p', { 'class': 'description' }, T.noYear);
            box.insertBefore(hint, table);
            var dateField = document.getElementById('vp-poll-event-date');
            function showHint() { hint.style.display = eventYear() ? 'none' : ''; }
            if (dateField) {
                dateField.addEventListener('change', function () {
                    var y = eventYear();
                    if (lastYear && y && y !== lastYear) {
                        body.querySelectorAll('input.vp-year').forEach(function (i) {
                            if (i.value !== '') { i.value = parseInt(i.value, 10) + (y - lastYear); }
                        });
                    } else if (!lastYear && y) {
                        load(classes); // erst jetzt lassen sich Jahrgänge ausrechnen
                    }
                    lastYear = y || lastYear;
                    showHint();
                });
            }
            showHint();
            window.vpPollClassesLoad = function (list) {
                if (body.children.length && !window.confirm(T.replace)) { return; }
                classes = list;
                load(list);
            };
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.vp-class-editor').forEach(setup);
    });
})();
