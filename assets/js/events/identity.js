/**
 * Terminliste – Kennung ohne Login
 *
 * Aktionen laufen normal los; fehlt die Kennung, wird die Absicht gemerkt,
 * der Dialog gezeigt und danach fortgesetzt.
 *
 * Teil von assets/js/events/, geteilt wird über window.vpEvents (core.js).
 */
(function(){
    var VP = window.vpEvents;
    if (!VP) { return; }

    var API  = VP.api;
    var T    = VP.t;
    var H    = VP.headers;
    var CFG  = VP.cfg;
    var KEY  = VP.key;
    // Wird wahr, sobald der Gast seinen Namen hinterlassen hat.
    var KNOWN = VP.cfg.known;

    var identModal = document.getElementById('vp-ident-modal');
    var pendingAction = null;
    // Gesetzt, solange der Dialog zum Umbenennen offen ist: {id, date, done}.
    var renameFor = null;

    /**
     * Den eigenen Namen ändern (nur Gäste). Derselbe Dialog wie beim ersten
     * Mal, vorbelegt und ohne E-Mail-Feld; gespeichert über /guest/name.
     */
    function change(eventId, instanceDate, done) {
        if (!identModal) { return; }
        renameFor = { id: eventId, date: instanceDate, done: done };
        document.getElementById('vp-ident-name').value = CFG.guestName || '';
        identModal.querySelector('[data-ident-email-field]').hidden = true;
        identModal.querySelector('[data-ident-error]').hidden = true;
        identModal.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    /** „Nicht du?“: Kennung auf diesem Gerät vergessen, Seite neu laden. */
    function forget() {
        fetch(API + '/guest/forget', { method: 'POST', headers: H(true) })
            .then(function(){ window.location.reload(); })
            .catch(function(){ window.location.reload(); });
    }

    function closeIdent() {
        identModal.hidden = true;
        pendingAction = null;
        renameFor = null;
        identModal.querySelector('[data-ident-email-field]').hidden = false;
        document.body.style.overflow = '';
    }

    function needsIdentity(run) {
        if (KNOWN || !identModal) { return false; }
        pendingAction = run;
        identModal.hidden = false;
        document.body.style.overflow = 'hidden';
        // Am Rechner gleich ins Namensfeld. Auf dem Handy nicht: Dort
        // schöbe die aufspringende Tastatur das Formular weg, bevor man es
        // überhaupt gelesen hat.
        var n = document.getElementById('vp-ident-name');
        if (n && window.matchMedia && window.matchMedia('(pointer: fine)').matches) { n.focus(); }
        return true;
    }

    if (identModal) {
        var identError = identModal.querySelector('[data-ident-error]');

        identModal.addEventListener('click', function(e){
            if (e.target.hasAttribute('data-close')) { closeIdent(); }
        });

        identModal.querySelector('[data-ident-save]').addEventListener('click', function(){
            var name  = document.getElementById('vp-ident-name').value.trim();
            var email = document.getElementById('vp-ident-email').value.trim();

            identError.hidden = true;

            // Nur der Name ist nötig. Kontaktdaten sind freiwillig –
            // sie dienen der Benachrichtigung, nicht dem Zugang.
            if (!name) {
                identError.textContent = T.nameNeeded;
                identError.hidden = false;
                document.getElementById('vp-ident-name').focus();
                return;
            }

            if (renameFor) {
                var r = renameFor;
                fetch(API + '/guest/name', {
                    method: 'POST',
                    headers: H(true),
                    body: JSON.stringify({ id: r.id, instance_date: r.date, vp_key: KEY, name: name })
                })
                .then(function(res){ return res.json().then(function(d){ return { ok: res.ok, data: d }; }); })
                .then(function(res){
                    if (!res.ok) {
                        identError.textContent = res.data.message || T.error;
                        identError.hidden = false;
                        return;
                    }
                    CFG.guestName = res.data.name || name;
                    closeIdent();
                    if (r.done) { r.done(); }
                })
                .catch(function(){
                    identError.textContent = T.error;
                    identError.hidden = false;
                });
                return;
            }

            fetch(API + '/guest/identify', {
                method: 'POST',
                headers: H(true),
                body: JSON.stringify({
                    id:            CFG.identify.id,
                    instance_date: CFG.identify.instanceDate,
                    vp_key:        KEY,
                    name:          name,
                    email:         email,
                    return_url:    window.location.href.split('#')[0]
                })
            })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) {
                    identError.textContent = res.data.message || T.error;
                    identError.hidden = false;
                    return;
                }

                KNOWN = true;
                CFG.guestName = res.data.name || name;
                CFG.guestRenamable = !!res.data.guest;
                identModal.hidden = true;
                document.body.style.overflow = '';

                var run = pendingAction;
                pendingAction = null;
                if (run) { run(); }
            })
            .catch(function(){
                identError.textContent = T.error;
                identError.hidden = false;
            });
        });
    }

    // Über die Modulgrenze hinweg gebraucht:
    VP.identity = { needsIdentity: needsIdentity, change: change, forget: forget };
})();
