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
    var peItem    = null;
    // saved  = eigene Personenliste, chosen = wer bei diesem Termin dabei ist,
    // self   = eigene Antwort ('yes' | 'no' | null)
    var peState   = { saved: [], chosen: {}, self: null };

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
            forget.dataset.forgetId = dataset.companionId;
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
            if (dataset.self)        { b.dataset.self = '1'; }
            actions.appendChild(b);
        });

        li.appendChild(actions);
        return li;
    }

    function renderPeople() {
        peList.innerHTML = '';

        // Eigene Teilnahme zuerst – ohne gesetzte Antwort gilt "nicht dabei".
        peList.appendChild(
            personRow(T.selfComing, peState.self === 'yes', { self: true }, false)
        );

        peState.saved.forEach(function(p){
            peList.appendChild(
                personRow(p.name, !!peState.chosen[p.id], { companionId: p.id }, true)
            );
        });
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
    function savePeople(newNames) {
        if (!peItem) { return Promise.resolve(); }
        peopleError('');

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

                // Die angeklickte Antwort gilt sofort – der Dialog dient
                // nur noch dazu, Begleitpersonen zu ergänzen.
                if (presetResponse && peState.self !== presetResponse) {
                    peState.self = presetResponse;
                    renderPeople();
                    savePeople();
                }
            })
            .catch(function(){ peLoading.textContent = T.loadError; });
    }

    // Antwort je Person umschalten – wird sofort gespeichert.
    peModal.addEventListener('click', function(e){
        var btn = e.target.closest('[data-person-answer]');
        if (!btn) { return; }

        var yes = btn.dataset.personAnswer === 'yes';

        if (btn.dataset.self) {
            peState.self = yes ? 'yes' : 'no';
        } else {
            peState.chosen[btn.dataset.companionId] = yes;
        }

        renderPeople();
        savePeople();
    });

    // Person dauerhaft aus der eigenen Liste entfernen
    peModal.addEventListener('click', function(e){
        var btn = e.target.closest('.vp-person__forget');
        if (!btn) { return; }

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
            savePeople();
        })
        .catch(function(){ peopleError(T.error); });
    });

    function addPerson() {
        var name = peNew.value.trim();
        if (!name) { return; }
        peNew.value = '';
        // Neue Personen sind direkt dabei – sonst hätte man sie nicht
        // eingetragen.
        savePeople([ name ]).then(function(){
            var added = peState.saved.filter(function(p){ return p.name === name; })[0];
            if (added && !peState.chosen[added.id]) {
                peState.chosen[added.id] = true;
                renderPeople();
                savePeople();
            }
        });
    }

    peModal.querySelector('[data-people-add]').addEventListener('click', addPerson);
    peNew.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); addPerson(); }
    });

    // Über die Modulgrenze hinweg gebraucht:
    VP.people = { open: openPeople, updateBadge: updatePeopleBadge };
})();
