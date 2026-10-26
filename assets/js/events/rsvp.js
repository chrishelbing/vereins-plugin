/**
 * Terminliste – Zusage / Absage / Helfen
 *
 * Alles läuft über denselben Endpunkt: die Antwort wird immer zusammen mit
 * dem Helfer-Status gespeichert.
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

    function updateCounts(item, d) {
        // Zusagen zählt Mitglieder, die Personenzahl schließt
        // Begleitpersonen mit ein.
        var yesText = T.yes.replace('%d', d.yes_count);
        if (d.people_count > d.yes_count) {
            yesText += ' · ' + T.people.replace('%d', d.people_count);
        }
        item.querySelector('.vp-rsvp__count--yes').textContent = yesText;
        item.querySelector('.vp-rsvp__count--no').textContent  = T.no.replace('%d', d.no_count);

        var help = item.querySelector('.vp-rsvp__count--help');
        if (help) { help.textContent = T.helpCount.replace('%d', d.helper_count); }
    }

    function sendRsvp(item, value) {
        var status = item.querySelector('.vp-rsvp__status');

        item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){ b.disabled = true; });
        status.textContent = T.saving;

        return fetch(API + '/events/' + item.dataset.eventId + '/attendance', {
            method: 'POST',
            headers: H(true),
            body: JSON.stringify({
                instance_date: item.dataset.instanceDate,
                response: value
            })
        })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res){
            if (!res.ok) {
                status.textContent = res.data.message || T.error;
                return;
            }
            status.textContent = '';
            var d = res.data;

            item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){
                b.classList.toggle('is-active', b.dataset.response === d.own_response);
            });
            updateCounts(item, d);
        })
        .catch(function(){ status.textContent = T.error; })
        .finally(function(){
            item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){ b.disabled = false; });
        });
    }

    root.addEventListener('click', function(e){
        var btn = e.target.closest('.vp-rsvp__btn');
        if (!btn) { return; }

        var item = btn.closest('.vp-events__item');

        if (VP.identity.needsIdentity(function(){ btn.click(); })) { return; }

        // Erneuter Klick auf die aktive Antwort zieht alles zurück.
        if (btn.classList.contains('is-active')) {
            sendRsvp(item, 'none');
            VP.people.updateBadge(item, 0);
            return;
        }

        // Sonst öffnet sich der Dialog "Wer kommt?" – die angeklickte
        // Antwort gilt sofort, Begleitpersonen lassen sich dort ergänzen.
        VP.people.open(item, btn.dataset.response);
    });

    /** Helferzahl am Termin nachziehen, nachdem Aufgaben übernommen wurden. */
    function refreshCounts(item) {
        var url = API + '/events/' + item.dataset.eventId + '/attendance'
                + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

        fetch(url, { headers: H() })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(d){
                if (!d) { return; }
                updateCounts(item, d);
                item.querySelectorAll('.vp-rsvp__btn').forEach(function(b){
                    b.classList.toggle('is-active', b.dataset.response === d.own_response);
                });
            })
            .catch(function(){});
    }

    // Über die Modulgrenze hinweg gebraucht:
    VP.rsvp = { updateCounts: updateCounts, refreshCounts: refreshCounts };
})();
