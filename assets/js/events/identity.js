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
            if (e.target.hasAttribute('data-close')) {
                identModal.hidden = true;
                pendingAction = null;
                document.body.style.overflow = '';
            }
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
    VP.identity = { needsIdentity: needsIdentity };
})();
