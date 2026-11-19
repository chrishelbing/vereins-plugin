/**
 * Terminliste – Begleitpersonen
 *
 * Wer kommt mit? Einmal eingetragene Personen stehen beim nächsten Termin
 * gleich wieder zur Auswahl.
 *
 * Teil von assets/js/events/, geteilt wird über window.vpEvents (core.js).
 */
(function(){
    var VP = window.vpEvents;
    if (!VP) { return; }

    var API  = VP.api;
    var T    = VP.t;
    var H    = VP.headers;

    var peModal   = document.getElementById('vp-people-modal');
    var peLoading = peModal.querySelector('.vp-modal__loading');
    var peContent = peModal.querySelector('.vp-people__content');
    var peList    = peModal.querySelector('[data-people-list]');
    var peNew     = peModal.querySelector('[data-people-new]');
    var peError   = peModal.querySelector('[data-people-error]');
    var peTotal   = peModal.querySelector('[data-people-total]');
    var peItem    = null;
    // saved  = eigene Personenliste, chosen = wer bei diesem Termin dabei ist,
    // self   = eigene Antwort ('yes' | 'no' | null)
    // pending = neu getippte Namen, die erst mit „Bestätigen“ angelegt werden
    var peState   = { saved: [], chosen: {}, self: null, pending: [] };

    function closePeople() {
        peModal.hidden = true;
        peItem = null;
        document.body.style.overflow = '';
    }

    peModal.addEventListener('click', function(e){
        if (e.target.hasAttribute('data-close')) { closePeople(); }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !peModal.hidden) { closePeople(); }
    });

    function peopleError(msg) {
        peError.textContent = msg || '';
        peError.hidden = !msg;
    }

    /**
     * Baut je Person eine Zeile mit denselben Pill-Buttons wie am
     * Termin – oben man selbst, darunter die eigenen Personen.
     */
    function personRow(name, isComing, dataset, removable) {
        var li = document.createElement('li');
        li.className = 'vp-bring__item vp-person';

        var head = document.createElement('div');
        head.className = 'vp-bring__itemhead';

        var label = document.createElement('strong');
        label.textContent = name;
        head.appendChild(label);

        if (removable) {
            var forget = document.createElement('button');
            forget.type = 'button';
            forget.className = 'vp-person__forget';
            forget.title = T.forget;
            forget.textContent = '×';
            if (dataset.pendingIndex !== undefined) { forget.dataset.pendingIndex = dataset.pendingIndex; }
            else                                    { forget.dataset.forgetId = dataset.companionId; }
            head.appendChild(forget);
        }

        li.appendChild(head);

        var actions = document.createElement('div');
        actions.className = 'vp-bring__actions';

        [ ['yes', T.comingYes], ['no', T.comingNo] ].forEach(function(pair){
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'vp-rsvp__btn vp-rsvp__btn--' + pair[0]
                        + ((isComing === (pair[0] === 'yes')) ? ' is-active' : '');
            b.textContent = pair[1];
            b.dataset.personAnswer = pair[0];
            if (dataset.companionId) { b.dataset.companionId = dataset.companionId; }
            if (dataset.pendingIndex !== undefined) { b.dataset.pendingIndex = dataset.pendingIndex; }
            if (dataset.self)        { b.dataset.self = '1'; }
            actions.appendChild(b);
        });

        li.appendChild(actions);
        return li;
    }

    function renderPeople() {
        peList.innerHTML = '';

        // Eigene Teilnahme zuerst – ohne gesetzte Antwort gilt "nicht dabei".
        // Gäste sehen, unter welchem Namen sie erkannt wurden, und können ihn
        // ändern bzw. auf einem geteilten Gerät neu ansetzen.
        var cfg    = VP.cfg;
        var selbst = personRow(
            cfg.guestName ? T.selfComing + ' – ' + cfg.guestName : T.selfComing,
            peState.self === 'yes', { self: true }, false
        );
        if (cfg.guestName) {
            var links = document.createElement('div');
            links.className = 'vp-person__guest';
            if (cfg.guestRenamable) {
                var umbenennen = document.createElement('button');
                umbenennen.type = 'button';
                umbenennen.className = 'vp-person__link';
                umbenennen.textContent = T.guestRename;
                umbenennen.addEventListener('click', function(){
                    var item = peItem;
                    VP.identity.change(item.dataset.eventId, item.dataset.instanceDate, renderPeople);
                });
                links.appendChild(umbenennen);
            }
            var nichtIch = document.createElement('button');
            nichtIch.type = 'button';
            nichtIch.className = 'vp-person__link';
            nichtIch.textContent = T.guestNotYou;
            nichtIch.addEventListener('click', function(){ VP.identity.forget(); });
            links.appendChild(nichtIch);
            selbst.querySelector('.vp-bring__itemhead').appendChild(links);
        }
        peList.appendChild(selbst);

        peState.saved.forEach(function(p){
            peList.appendChild(
                personRow(p.name, !!peState.chosen[p.id], { companionId: p.id }, true)
            );
        });

        // Neu getippt, noch nicht gespeichert – kommen mit, bis man sie
        // wieder herausnimmt.
        peState.pending.forEach(function(name, i){
            peList.appendChild(personRow(name, true, { pendingIndex: i }, true));
        });

        renderTotal();
    }

    /**
     * Wie viele kommen insgesamt? Jede Begleitperson zählt einzeln, man
     * selbst nur bei eigener Zusage.
     */
    function renderTotal() {
        var guests = peState.saved.filter(function(p){ return peState.chosen[p.id]; }).length
                   + peState.pending.length;
        var self   = peState.self === 'yes' ? 1 : 0;
        var total  = guests + self;

        peTotal.textContent = (self && guests)
            ? T.peopleTotalOf.replace('%1$d', total).replace('%2$d', guests)
            : T.peopleTotal.replace('%d', total);
    }

    function updatePeopleBadge(item, count) {
        var badge = item.querySelector('.vp-rsvp__guests');
        if (!badge) { return; }
        badge.textContent = '+' + count;
        badge.hidden = count < 1;
    }

    function applyPeopleData(d) {
        peState.saved  = d.saved_companions || [];
        peState.chosen = {};
        (d.own_companions || []).forEach(function(c){ peState.chosen[c.companion_id] = true; });
        peState.self   = d.own_response;
        peState.pending = [];
        renderPeople();

        // Termin-Zeile mitziehen
        if (peItem) {
            peItem.querySelectorAll('.vp-rsvp > .vp-rsvp__buttons .vp-rsvp__btn').forEach(function(b){
                b.classList.toggle('is-active', b.dataset.response === d.own_response);
            });
            VP.rsvp.updateCounts(peItem, d);
            updatePeopleBadge(peItem, (d.own_companions || []).length);
        }
    }

    /**
     * Speichert eigene Antwort und Begleitpersonen zusammen – der
     * Endpunkt erwartet immer den kompletten Stand.
     */
    // Die zuletzt gestartete Speicherung – „Bestätigen“ wartet auf sie.
    var peSaving = Promise.resolve();

    function savePeople(newNames) {
        if (!peItem) { return Promise.resolve(); }
        peopleError('');
        peSaving = doSavePeople(newNames);
        return peSaving;
    }

    function doSavePeople(newNames) {
        var ids = Object.keys(peState.chosen)
            .filter(function(k){ return peState.chosen[k]; })
            .map(Number);

        return fetch(API + '/events/' + peItem.dataset.eventId + '/attendance', {
            method: 'POST',
            headers: H(true),
            body: JSON.stringify({
                instance_date:   peItem.dataset.instanceDate,
                response:        peState.self === 'yes' ? 'yes' : 'no',
                companion_ids:   ids,
                companion_names: newNames || []
            })
        })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res){
            if (!res.ok) { peopleError(res.data.message || T.error); return; }
            applyPeopleData(res.data);
        })
        .catch(function(){ peopleError(T.error); });
    }

    function openPeople(item, presetResponse) {
        peItem = item;
        peLoading.textContent = T.loading;
        peLoading.hidden = false;
        peContent.hidden = true;
        peopleError('');
        peNew.value = '';
        peModal.hidden = false;
        document.body.style.overflow = 'hidden';

        var url = API + '/events/' + item.dataset.eventId + '/attendance'
                + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

        fetch(url, { headers: H() })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) { peLoading.textContent = T.loadError; return; }
                applyPeopleData(res.data);
                peLoading.hidden = true;
                peContent.hidden = false;

                // Die angeklickte Antwort ist vorgewählt, gilt aber erst mit
                // „Bestätigen“. Wer den Dialog schließt, hat nichts geändert.
                if (presetResponse && peState.self !== presetResponse) {
                    peState.self = presetResponse;
                    renderPeople();
                }
            })
            .catch(function(){ peLoading.textContent = T.loadError; });
    }

    // „Bestätigen“: Alles ist schon gespeichert. Ein noch nicht hinzugefügter
    // Name im Eingabefeld wird mitgenommen, dann schließt der Dialog – außer
    // beim Speichern ging etwas schief, dann bleibt er mit der Meldung offen.
    var peDone = peModal.querySelector('[data-people-done]');
    peDone.addEventListener('click', function(){
        peDone.disabled = true;
        addPerson();
        savePeople(peState.pending.slice()).then(function(){
            peDone.disabled = false;
            if (peError.hidden) { closePeople(); }
        });
    });

    // Antwort je Person umschalten – nur vorgemerkt bis „Bestätigen“.
    peModal.addEventListener('click', function(e){
        var btn = e.target.closest('[data-person-answer]');
        if (!btn) { return; }

        var yes = btn.dataset.personAnswer === 'yes';

        // Nur vormerken – gespeichert wird mit „Bestätigen“.
        if (btn.dataset.self) {
            peState.self = yes ? 'yes' : 'no';
        } else if (btn.dataset.pendingIndex !== undefined) {
            if (!yes) { peState.pending.splice(Number(btn.dataset.pendingIndex), 1); }
        } else {
            peState.chosen[btn.dataset.companionId] = yes;
        }

        renderPeople();
    });

    // Person dauerhaft aus der eigenen Liste entfernen
    peModal.addEventListener('click', function(e){
        var btn = e.target.closest('.vp-person__forget');
        if (!btn) { return; }

        // Noch nicht gespeichert: einfach aus der Vormerkung nehmen.
        if (btn.dataset.pendingIndex !== undefined) {
            peState.pending.splice(Number(btn.dataset.pendingIndex), 1);
            renderPeople();
            return;
        }

        var id = btn.dataset.forgetId;
        fetch(API + '/companions/' + id, {
            method: 'DELETE',
            headers: H()
        })
        .then(function(r){ return r.json(); })
        .then(function(d){
            peState.saved = d.saved || [];
            delete peState.chosen[id];
            renderPeople();
        })
        .catch(function(){ peopleError(T.error); });
    });

    /**
     * Neue Personen sind direkt dabei – sonst hätte man sie nicht
     * eingetragen. „Mia, Tom“ zerlegt der Server in zwei Personen
     * (CompanionModel::split_names()); jede bekommt eine eigene Zeile.
     */
    function norm(t) { return String(t).toLowerCase().replace(/\s+/g, ' ').trim(); }

    function addPerson() {
        var raw = peNew.value.trim();
        if (!raw) { return; }
        peNew.value = '';
        // Vormerken; „Mia, Tom“ wie der Server in zwei Personen teilen.
        // Den eigenen Namen nicht – man ist über „Ich“ dabei (der Server
        // weist ihn ebenso ab, CompanionModel::remember()).
        var eigen = norm(VP.cfg.guestName || VP.cfg.selfName || '');
        var selbst = false;
        raw.split(',').forEach(function(n){
            n = n.trim();
            if (!n) { return; }
            if (eigen && norm(n) === eigen) { selbst = true; return; }
            peState.pending.push(n);
        });
        renderPeople();
        if (selbst) { peopleError(T.selfNotGuest); }
        peNew.focus();
    }

    peModal.querySelector('[data-people-add]').addEventListener('click', addPerson);
    peNew.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); addPerson(); }
    });

    // Über die Modulgrenze hinweg gebraucht:
    VP.people = { open: openPeople, updateBadge: updatePeopleBadge };
})();
