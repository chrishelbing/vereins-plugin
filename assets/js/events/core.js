/**
 * Terminliste – gemeinsamer Kern
 *
 * Legt window.vpEvents an: die Liste selbst, die Werte aus PHP und die
 * Kopfzeilen für alle Anfragen. Erst dadurch finden die übrigen Dateien in
 * assets/js/events/ zusammen; ohne dieses Objekt tun sie nichts.
 *
 * Zwei Module tragen sich hier zusätzlich ein, weil andere sie brauchen:
 * identity (die Rückfrage nach dem Namen), rsvp (die Zähler an der Kachel)
 * und people (der Dialog "Wer kommt mit?"). Sie hängen wechselseitig
 * voneinander ab, deshalb wird erst beim Klick nachgeschlagen und nicht
 * beim Laden – die Reihenfolge der Dateien spielt so keine Rolle.
 */
(function(){
    var CFG  = window.VP_EVENTS;
    var root = document.getElementById('vp-events');

    // Ohne die Werte aus PHP ginge jede Anfrage ins Leere. Dann lieber gar
    // nichts tun, als Knöpfe anzubieten, die stumm scheitern.
    if (!root || !CFG || !CFG.api) { return; }

    window.vpEvents = {
        root: root,
        cfg:  CFG,
        api:  CFG.api,
        key:  CFG.key,
        // Übersetzte Textbausteine, siehe EventsShortcode::script_texts().
        t:    CFG.t || {},

        /** Kopfzeilen für alle Anfragen – im öffentlichen Modus mit Link-Schlüssel. */
        headers: function(json) {
            var h = { 'X-WP-Nonce': CFG.nonce };
            if (json)     { h['Content-Type'] = 'application/json'; }
            if (CFG.key)  { h['X-VP-Key'] = CFG.key; }
            return h;
        },

        // Von den Teilmodulen nachgetragen, siehe Kopfkommentar.
        identity: {},
        rsvp:     {},
        people:   {}
    };
})();
