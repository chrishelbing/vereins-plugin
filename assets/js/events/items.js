/**
 * Terminliste – Mitbringliste & Helferaufgaben
 *
 * Ein Overlay für beide Arten; 'currentKind' steuert Beschriftung und
 * Zielressource.
 *
 * Teil von assets/js/events/, geteilt wird über window.vpEvents (core.js).
 */
(function(){
    var VP = window.vpEvents;
    if (!VP) { return; }

    var root = VP.root;
    var API  = VP.api;
    var T    = VP.t;
    var H    = VP.headers;

    var bring        = document.getElementById('vp-bring-modal');
    var bTitle       = bring.querySelector('.vp-modal__title');
    var bLoading     = bring.querySelector('.vp-modal__loading');
    var bContent     = bring.querySelector('.vp-bring__content');
    var bList        = bring.querySelector('[data-bring-list]');
    var bAddBox      = bring.querySelector('[data-bring-addbox]');
    var bRequestBox  = bring.querySelector('[data-bring-request]');
    var bRequestHead = bring.querySelector('[data-request-head]');
    var bRequestLabel= bring.querySelector('[data-request-label]');
    var bError       = bring.querySelector('[data-bring-error]');
    var currentItem  = null;   // <li> des Termins, für den das Overlay offen ist
    var currentKind  = 'bring';
    // Eigene Begleitpersonen – damit sich Helferaufgaben auch für
    // Familienmitglieder übernehmen lassen.
    var helperPeople = [];

    function closeBring() {
        bring.hidden = true;
        if (currentItem && currentKind === 'help') { VP.rsvp.refreshCounts(currentItem); }
        currentItem  = null;
        document.body.style.overflow = '';
    }

    bring.addEventListener('click', function(e){
        if (e.target.hasAttribute('data-close')) { closeBring(); }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !bring.hidden) { closeBring(); }
    });

    function showError(message) {
        bError.textContent = message;
        bError.hidden = !message;
    }

    /** Baut die Liste neu auf und aktualisiert den Zähler im Termin. */
    function renderItems(data) {
        var items  = data.items || [];
        var isHelp = currentKind === 'help';

        // Eigene Einträge gibt es nur bei der Mitbringliste –
        // Helferaufgaben legt ausschließlich das Orgateam fest.
        bAddBox.hidden     = isHelp;
        bRequestBox.hidden = !data.can_manage;
        bRequestHead.textContent = isHelp ? T.headHelp : T.headBring;
        bRequestLabel.placeholder = isHelp ? T.phHelp : T.phBring;

        bList.innerHTML = '';

        if (!items.length) {
            var empty = document.createElement('li');
            empty.className = 'vp-modal__empty';
            empty.textContent = isHelp ? T.noTasks : T.noItems;
            bList.appendChild(empty);
        }

        var openTotal = 0;

        items.forEach(function(item){
            openTotal += item.open;

            var li = document.createElement('li');
            li.className = 'vp-bring__item';

            var head = document.createElement('div');
            head.className = 'vp-bring__itemhead';

            var label = document.createElement('strong');
            label.textContent = item.label;
            head.appendChild(label);

            var meta = document.createElement('span');
            meta.className = 'vp-bring__meta';
            if (item.is_request) {
                meta.textContent = item.open > 0
                    ? T.openOf.replace('%1$d', item.open).replace('%2$d', item.quantity_needed)
                    : T.covered;
                meta.classList.add(item.open > 0 ? 'is-open' : 'is-covered');
            }
            head.appendChild(meta);
            li.appendChild(head);

            if (item.claims.length) {
                var who = document.createElement('div');
                who.className = 'vp-bring__who';
                who.textContent = item.claims.map(function(c){
                    var label = c.name;
                    if (c.via) { label += T.viaMember.replace('%s', c.via); }
                    if (c.quantity > 1) { label += ' (' + c.quantity + ')'; }
                    return label;
                }).join(', ');
                li.appendChild(who);
            }

            var actions = document.createElement('div');
            actions.className = 'vp-bring__actions';

            // Wer aus der Familie hat diesen Posten übernommen?
            var mine = item.claims.filter(function(c){ return c.own; });

            mine.forEach(function(c){
                var label = c.companion_id
                    ? T.release + ' – ' + c.name
                    : T.release;
                var btn = makeBtn(label, 'release', item.id);
                btn.dataset.companionId = c.companion_id || 0;
                actions.appendChild(btn);
            });

            // Übernehmen anbieten, solange noch Bedarf offen ist –
            // für sich selbst und für noch nicht eingeteilte Personen.
            if (item.is_request && item.open > 0) {
                var takenIds = mine.map(function(c){ return String(c.companion_id || 0); });

                if (takenIds.indexOf('0') === -1) {
                    var self = makeBtn(T.take, 'claim', item.id, 'is-primary');
                    self.dataset.companionId = 0;
                    actions.appendChild(self);
                }

                (helperPeople || []).forEach(function(p){
                    if (takenIds.indexOf(String(p.id)) !== -1) { return; }
                    var b = makeBtn(T.takeFor.replace('%s', p.name), 'claim', item.id);
                    b.dataset.companionId = p.id;
                    actions.appendChild(b);
                });
            }

            if (data.can_manage || (!item.is_request && item.own_claim)) {
                actions.appendChild(makeBtn(T.remove, 'delete', item.id, 'is-danger'));
            }

            if (actions.children.length) { li.appendChild(actions); }
            bList.appendChild(li);
        });

        // Zähler am passenden Button des Termins aktualisieren
        if (currentItem) {
            var trigger = currentItem.querySelector('.vp-bring__open[data-kind="' + currentKind + '"]');
            if (trigger) {
                trigger.querySelector('.vp-bring__stat').textContent = openTotal > 0
                    ? T.open.replace('%d', openTotal)
                    : (items.length ? T.allTaken : T.listEmpty);
            }
        }
    }

    function makeBtn(label, action, itemId, extra) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'vp-bring__btn' + (extra ? ' ' + extra : '');
        b.textContent = label;
        b.dataset.action = action;
        b.dataset.itemId = itemId;
        return b;
    }

    function loadItems() {
        if (!currentItem) { return Promise.resolve(); }
        var url = API + '/events/' + currentItem.dataset.eventId + '/items'
                + '?instance_date=' + encodeURIComponent(currentItem.dataset.instanceDate)
                + '&kind=' + encodeURIComponent(currentKind);

        return fetch(url, { headers: H() })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) { bLoading.textContent = T.loadError; return; }
                renderItems(res.data);
                bLoading.hidden = true;
                bContent.hidden = false;
            })
            .catch(function(){ bLoading.textContent = T.loadError; });
    }

    root.addEventListener('click', function(e){
        var trigger = e.target.closest('.vp-bring__open');
        if (!trigger) { return; }

        if (VP.identity.needsIdentity(function(){ trigger.click(); })) { return; }

        currentItem = trigger.closest('.vp-events__item');
        currentKind = trigger.dataset.kind === 'help' ? 'help' : 'bring';

        bTitle.textContent   = currentKind === 'help' ? T.titleHelp : T.titleBring;
        bLoading.textContent = T.loading;
        bLoading.hidden = false;
        bContent.hidden = true;
        showError('');
        bring.hidden = false;
        document.body.style.overflow = 'hidden';

        // Bei Helferaufgaben zusätzlich die eigenen Personen laden,
        // damit man auch für sie übernehmen kann.
        if (currentKind === 'help') {
            fetch(API + '/companions', { headers: H() })
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(d){ helperPeople = (d && d.saved) || []; })
                .catch(function(){ helperPeople = []; })
                .finally(loadItems);
        } else {
            helperPeople = [];
            loadItems();
        }
    });

    // Übernehmen / Zurückziehen / Löschen
    bring.addEventListener('click', function(e){
        var btn = e.target.closest('.vp-bring__btn');
        if (!btn) { return; }

        var id        = btn.dataset.itemId;
        var companion = parseInt(btn.dataset.companionId || 0, 10);
        var cfg = {
            claim:   { url: API + '/items/' + id + '/claim', method: 'POST' },
            release: { url: API + '/items/' + id + '/claim', method: 'DELETE' },
            'delete':{ url: API + '/items/' + id,            method: 'DELETE' }
        }[btn.dataset.action];

        if (!cfg) { return; }
        showError('');
        btn.disabled = true;

        var url = cfg.url;
        if (cfg.method === 'DELETE' && btn.dataset.action === 'release') {
            url += '?companion_id=' + companion;
        }

        fetch(url, {
            method: cfg.method,
            headers: H(true),
            body: cfg.method === 'POST'
                ? JSON.stringify({ quantity: 1, companion_id: companion })
                : undefined
        })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res){
            if (!res.ok) { showError(res.data.message || T.error); btn.disabled = false; return; }
            renderItems(res.data);
        })
        .catch(function(){ showError(T.error); btn.disabled = false; });
    });

    // Eigenen Eintrag bzw. Bedarf hinzufügen
    function addItem(labelEl, qtyEl, isRequest) {
        var label = labelEl.value.trim();
        if (!label || !currentItem) { return; }
        showError('');

        fetch(API + '/events/' + currentItem.dataset.eventId + '/items', {
            method: 'POST',
            headers: H(true),
            body: JSON.stringify({
                instance_date:   currentItem.dataset.instanceDate,
                kind:            currentKind,
                label:           label,
                is_request:      !!isRequest,
                quantity_needed: qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1
            })
        })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res){
            if (!res.ok) { showError(res.data.message || T.error); return; }
            labelEl.value = '';
            if (qtyEl) { qtyEl.value = 1; }
            renderItems(res.data);
        })
        .catch(function(){ showError(T.error); });
    }

    bring.querySelector('[data-bring-add]').addEventListener('click', function(){
        addItem(bring.querySelector('[data-bring-label]'), null, false);
    });
    bring.querySelector('[data-request-add]').addEventListener('click', function(){
        addItem(bring.querySelector('[data-request-label]'), bring.querySelector('[data-request-qty]'), true);
    });
    bring.addEventListener('keydown', function(e){
        if (e.key !== 'Enter') { return; }
        if (e.target.hasAttribute('data-bring-label'))   { e.preventDefault(); bring.querySelector('[data-bring-add]').click(); }
        if (e.target.hasAttribute('data-request-label')) { e.preventDefault(); bring.querySelector('[data-request-add]').click(); }
    });
})();
