/**
 * Terminliste – Termin bearbeiten
 *
 * Der Stift an der Kachel öffnet den Dialog aus overlay_edit(). Die Werte
 * kommen frisch über GET /events/{id}; im Markup steht nichts davon, damit
 * die Liste nicht jede Kachel doppelt ausliefert.
 *
 * Gehört der Termin zu einer Serie, fragt der Dialog wie ein Kalender-
 * programm nach: nur dieser Termin oder die ganze Serie. Beides geht an
 * verschiedene Stellen der Schnittstelle –
 *
 *   ganze Serie     PUT  /events/{id}                (Stammsatz)
 *   nur dieser      POST /events/{id}/exceptions     (Ausnahme)
 *
 * und das gilt auch fürs Löschen: Die Serie verschwindet mit DELETE, der
 * einzelne Termin wird als 'cancelled' abgelegt und fällt damit aus.
 *
 * Wer was darf, entscheidet der Server. Der Stift steht nur an Kacheln, an
 * denen PHP das Recht bereits geprüft hat; ein Fehlschlag kommt trotzdem
 * als Meldung zurück statt als stiller Abbruch.
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

    var modal = document.getElementById('vp-edit-modal');
    if (!modal) { return; }

    var loading = modal.querySelector('[data-edit-loading]');
    var form    = modal.querySelector('[data-edit-form]');
    var scope   = modal.querySelector('[data-edit-scope]');
    var fields  = modal.querySelector('[data-edit-fields]');
    var msg     = modal.querySelector('[data-edit-msg]');
    var saveBtn = modal.querySelector('[data-edit-save]');
    var delBtn  = modal.querySelector('[data-edit-delete]');

    var recurCb  = form.querySelector('[data-vp-recurring]');
    var recurBox = form.querySelector('[data-vp-recurrence]');
    var freqSel  = form.querySelector('[data-vp-freq]');
    var wdBox    = form.querySelector('[data-vp-weekdays]');
    var typeSel  = form.querySelector('[name="event_type_id"]');

    // Der Termin, der gerade im Dialog liegt.
    var aktuell = { id: 0, instanceDate: '', istSerie: false };

    function feld(name) { return form.querySelector('[name="' + name + '"]'); }

    /**
     * Tags gibt der Termintyp vor: Sichtbar ist nur die Auswahl des
     * gewählten Typs, die übrigen sind deaktiviert. Bei „nur dieser
     * Termin“ gar keine – Tags gelten für die ganze Serie.
     */
    function zeigeTags(aus) {
        [].forEach.call(form.querySelectorAll('[data-vp-tagset]'), function(set){
            var an = !aus && set.dataset.vpTagset === String(typeSel.value);
            set.hidden = !an;
            [].forEach.call(set.querySelectorAll('input'), function(i){ i.disabled = !an; });
        });
    }

    function gewaehlteTags() {
        return [].map.call(
            form.querySelectorAll('[data-vp-tagset]:not([hidden]) input:checked'),
            function(cb){ return cb.value; }
        );
    }

    /** 'YYYY-MM-DD HH:MM:SS' → 'YYYY-MM-DDTHH:MM' für datetime-local. */
    function fuerFeld(wert) {
        if (!wert) { return ''; }
        return wert.replace(' ', 'T').slice(0, 16);
    }

    /** Umgekehrt: was das Feld liefert, ergänzt um die Sekunden. */
    function fuerServer(wert) {
        if (!wert) { return undefined; }
        return wert.replace('T', ' ') + ':00';
    }

    function melde(text, artFehler) {
        msg.textContent = text || '';
        msg.className   = 'vp-form__msg' + (text ? (artFehler ? ' vp-form__msg--err' : ' vp-form__msg--ok') : '');
    }

    function schliesse() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    /**
     * Bei "nur dieser Termin" sind Termintyp und Wiederholung nicht
     * änderbar: Eine Ausnahme kennt nur Titel, Text, Ort und Zeiten. Statt
     * die Felder stumm zu ignorieren, werden sie gesperrt – sichtbar, aber
     * unmissverständlich.
     */
    function umfangAnwenden() {
        var nurDieser = aktuell.istSerie && form.querySelector('[name="scope"]:checked').value === 'instance';

        typeSel.disabled = nurDieser;
        zeigeTags(nurDieser);
        recurCb.closest('.vp-form__group').hidden = nurDieser;
        if (nurDieser) { recurBox.style.display = 'none'; }
        else           { recurBox.style.display = recurCb.checked ? 'block' : 'none'; }

        delBtn.textContent = nurDieser ? T.editCancel : T.editDelete;
    }

    function fuelle(d) {
        feld('event_type_id').value = d.event_type_id || '';
        feld('title').value         = d.title || '';
        feld('location').value      = d.location || '';
        feld('description').value   = d.description || '';
        feld('start_at').value      = fuerFeld(d.start_at);
        feld('end_at').value        = fuerFeld(d.end_at);
        feld('is_full_day').checked = !!Number(d.is_full_day);

        // In der Datenbank steht eine JSON-Liste.
        var tags = d.tags || [];
        if (typeof tags === 'string') {
            try { tags = JSON.parse(tags) || []; } catch (e) { tags = []; }
        }
        [].forEach.call(form.querySelectorAll('[name="tags[]"]'), function(cb){
            cb.checked = cb.closest('[data-vp-tagset]').dataset.vpTagset === String(d.event_type_id)
                && tags.indexOf(cb.value) !== -1;
        });

        var r = d.recurrence || null;
        recurCb.checked = !!r;

        if (r) {
            freqSel.value = r.frequency || 'WEEKLY';
            feld('recurrence[interval]').value = r.interval_n || r.interval || 1;
            feld('recurrence[until]').value    = r.until || '';
            feld('recurrence[count]').value    = r.count || '';

            // In der Datenbank steht ein JSON-Feld; je nach Weg kommt es als
            // Zeichenkette oder schon zerlegt an.
            var tage = r.weekdays || [];
            if (typeof tage === 'string') {
                try { tage = JSON.parse(tage); } catch (e) { tage = []; }
            }
            [].forEach.call(form.querySelectorAll('[name="recurrence[weekdays][]"]'), function(cb){
                cb.checked = tage.indexOf(cb.value) !== -1;
            });
        }

        wdBox.style.display = freqSel.value === 'WEEKLY' ? 'block' : 'none';

        aktuell.istSerie = !!r;
        scope.hidden = !r;
        if (r) { form.querySelector('[name="scope"][value="instance"]').checked = true; }
        umfangAnwenden();
    }

    /** Was aus den Feldern an den Server geht. */
    function daten() {
        var body = {
            title:       feld('title').value,
            location:    feld('location').value || undefined,
            description: feld('description').value || undefined,
            start_at:    fuerServer(feld('start_at').value),
            end_at:      fuerServer(feld('end_at').value),
        };

        if (!typeSel.disabled) {
            body.event_type_id = parseInt(typeSel.value, 10);
            body.is_full_day   = feld('is_full_day').checked;
            // Immer mitschicken, auch leer – so lassen sich Tags entfernen.
            body.tags          = gewaehlteTags();
        }

        if (!typeSel.disabled && recurCb.checked) {
            var tage = [].filter.call(
                form.querySelectorAll('[name="recurrence[weekdays][]"]'),
                function(cb){ return cb.checked; }
            ).map(function(cb){ return cb.value; });

            body.recurrence = {
                frequency: freqSel.value,
                interval:  parseInt(feld('recurrence[interval]').value, 10) || 1,
                weekdays:  tage.length ? tage : undefined,
                until:     feld('recurrence[until]').value || undefined,
                count:     feld('recurrence[count]').value ? parseInt(feld('recurrence[count]').value, 10) : undefined,
            };
        }

        return body;
    }

    /** Antwort auswerten; bei Erfolg neu laden, damit die Kachel stimmt. */
    function sende(url, methode, body, knopf, beschriftung) {
        knopf.disabled = true;
        melde('');

        fetch(url, {
            method:  methode,
            headers: H(true),
            body:    body ? JSON.stringify(body) : undefined
        })
        .then(function(r){
            if (r.status === 204) { return { ok: true, data: {} }; }
            return r.json().then(function(d){ return { ok: r.ok, data: d }; });
        })
        .then(function(res){
            if (!res.ok) {
                knopf.disabled = false;
                knopf.textContent = beschriftung;
                melde(res.data.message || T.error, true);
                return;
            }
            melde(T.editSaved, false);
            window.location.reload();
        })
        .catch(function(){
            knopf.disabled = false;
            knopf.textContent = beschriftung;
            melde(T.error, true);
        });
    }

    // --- Öffnen ---------------------------------------------------------
    root.addEventListener('click', function(e){
        var stift = e.target.closest('[data-edit]');
        if (!stift) { return; }

        var item = stift.closest('.vp-events__item');
        aktuell.id           = item.dataset.eventId;
        aktuell.instanceDate = item.dataset.instanceDate;

        loading.textContent = T.loading;
        loading.hidden = false;
        form.hidden    = true;
        melde('');
        modal.hidden   = false;
        document.body.style.overflow = 'hidden';

        fetch(API + '/events/' + aktuell.id, { headers: H() })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) { loading.textContent = T.editLoadError; return; }
                fuelle(res.data);
                loading.hidden = true;
                form.hidden    = false;
            })
            .catch(function(){ loading.textContent = T.editLoadError; });
    });

    // --- Schließen ------------------------------------------------------
    modal.addEventListener('click', function(e){
        if (e.target.hasAttribute('data-close')) { schliesse(); }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !modal.hidden) { schliesse(); }
    });

    // --- Umschalter im Formular ----------------------------------------
    recurCb.addEventListener('change', function(){
        recurBox.style.display = recurCb.checked ? 'block' : 'none';
    });
    freqSel.addEventListener('change', function(){
        wdBox.style.display = freqSel.value === 'WEEKLY' ? 'block' : 'none';
    });
    scope.addEventListener('change', umfangAnwenden);
    typeSel.addEventListener('change', function(){ zeigeTags(false); });

    // --- Speichern ------------------------------------------------------
    form.addEventListener('submit', function(e){
        e.preventDefault();

        var nurDieser = aktuell.istSerie && form.querySelector('[name="scope"]:checked').value === 'instance';
        var body      = daten();

        saveBtn.textContent = T.saving;

        if (nurDieser) {
            body.exception_type = 'modified';
            body.original_date  = aktuell.instanceDate;
            sende(API + '/events/' + aktuell.id + '/exceptions', 'POST', body, saveBtn, T.editSave);
            return;
        }

        sende(API + '/events/' + aktuell.id, 'PUT', body, saveBtn, T.editSave);
    });

    // --- Löschen bzw. einzelnen Termin absagen --------------------------
    delBtn.addEventListener('click', function(){
        var nurDieser = aktuell.istSerie && form.querySelector('[name="scope"]:checked').value === 'instance';
        var frage     = nurDieser ? T.editCancelAsk : (aktuell.istSerie ? T.editSeriesAsk : T.editDeleteAsk);

        if (!window.confirm(frage)) { return; }

        if (nurDieser) {
            sende(API + '/events/' + aktuell.id + '/exceptions', 'POST', {
                exception_type: 'cancelled',
                original_date:  aktuell.instanceDate
            }, delBtn, T.editCancel);
            return;
        }

        sende(API + '/events/' + aktuell.id, 'DELETE', null, delBtn, T.editDelete);
    });
})();
