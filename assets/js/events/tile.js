/**
 * Terminliste – Aktionen an der Kachel
 *
 * Link zum Teilen und – fürs Orgateam – das Titelbild. Beide hängen am
 * selben Knopfband über dem Kachelkopf.
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

    // --- Ort → Kartenprogramm --------------------------------------
    // Im HTML steht der Google-Maps-Link, der überall funktioniert. Auf
    // iPhone, iPad und Mac öffnet der Apple-Link stattdessen die Karten-App.
    // iPadOS meldet sich als „Macintosh“ – deshalb zählt das mit.
    if (/iPhone|iPad|iPod|Macintosh/.test(navigator.userAgent)) {
        [].forEach.call(root.querySelectorAll('.vp-maplink[data-apple]'), function(a){
            a.href = a.dataset.apple;
        });
    }

    // --- Kompakte Kacheln --------------------------------------------
    // Zugeklappt zeigt eine Kachel Kopf, Titel, Ort, höchstens drei Zeilen
    // Beschreibung und die Knöpfe Zusagen/Absagen. Zahlenzeile, Helfer-
    // aufgaben, Mitbringliste und Fotos kommen erst mit „Details anzeigen“
    // – sonst wird jede Kachel so hoch, dass die Seite klobig wirkt.
    //
    // Nicht zugeklappt werden: vergangene Termine (dort bleiben nur die
    // Fotos, die sollen sichtbar sein), eine Liste mit nur einem Termin
    // (Terminlink, Banner-Ziel) und der Termin, auf den die Adresse zeigt.
    var tiles  = root.querySelectorAll('.vp-events__item');
    var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;

    function clampTile(item) {
        var more = item.querySelector('[data-more]');
        if (!more) { return; }

        var keepOpen = item.classList.contains('is-past') || tiles.length === 1 || item === target;
        var hidden   = !keepOpen && !!item.querySelector('.vp-rsvp__summary, .vp-chips');

        item.classList.toggle('is-compact', hidden);

        var parts = [item.querySelector('.vp-events__text'), item.querySelector('.vp-events__meta')]
            .filter(Boolean);
        var cut = false;

        if (!keepOpen) {
            parts.forEach(function(el){
                el.classList.add('is-clamped');
                if (el.scrollHeight > el.clientHeight + 1) { cut = true; }
            });
        }
        if (!cut) {
            parts.forEach(function(el){ el.classList.remove('is-clamped'); });
        }
        more.hidden = !(cut || hidden);
    }

    function clampAll() {
        [].forEach.call(root.querySelectorAll('.vp-events__item:not(.is-expanded)'), clampTile);
    }

    clampAll();
    // Schriften laden nach – erst dann stimmt die Höhe sicher.
    if (document.fonts && document.fonts.ready) { document.fonts.ready.then(clampAll); }
    var clampTimer = null;
    window.addEventListener('resize', function(){
        clearTimeout(clampTimer);
        clampTimer = setTimeout(clampAll, 150);
    });

    function toggleTile(item) {
        var open = !item.classList.contains('is-expanded');
        var more = item.querySelector('[data-more]');
        item.classList.toggle('is-expanded', open);
        if (more) {
            more.textContent = open ? T.showLess : T.showMore;
            more.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    root.addEventListener('click', function(e){
        var more = e.target.closest('[data-more]');
        if (more) { toggleTile(more.closest('.vp-events__item')); return; }

        // Klick in den gekürzten Text klappt auf – Links darin bleiben Links.
        var text = e.target.closest('.vp-events__text.is-clamped');
        if (text && !e.target.closest('a')) {
            var item = text.closest('.vp-events__item');
            if (!item.classList.contains('is-expanded')) { toggleTile(item); }
        }
    });

    // --- Link zum Teilen ------------------------------------------
    // Wer den Link hat, darf ohne Anmeldung zu- und absagen.
    root.addEventListener('click', function(e){
        var share = e.target.closest('[data-share]');
        if (!share) { return; }

        var url  = share.dataset.url;
        var done = function(){
            var old = share.title;
            share.title = T.linkCopied;
            share.classList.add('is-done');
            setTimeout(function(){ share.title = old; share.classList.remove('is-done'); }, 2000);
        };

        if (navigator.share) {
            navigator.share({ url: url }).catch(function(){});
            return;
        }
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(done).catch(function(){ window.prompt(T.linkCopy, url); });
            return;
        }
        window.prompt(T.linkCopy, url);
    });

    // --- Titelbild (Orgateam) -------------------------------------
    // Das Eingabefeld steht nur im Dokument, wenn jemand es ändern darf.
    var coverInput = document.getElementById('vp-cover-input');
    var coverItem  = null;

    if (coverInput) {
        root.addEventListener('click', function(e){
            var pick = e.target.closest('[data-cover-pick]');
            if (pick) {
                coverItem = pick.closest('.vp-events__item');
                coverInput.click();
                return;
            }

            var drop = e.target.closest('[data-cover-remove]');
            if (!drop) { return; }

            var item = drop.closest('.vp-events__item');
            fetch(API + '/events/' + item.dataset.eventId + '/image', {
                method: 'DELETE',
                headers: H()
            })
            .then(function(r){ if (r.ok) { window.location.reload(); } })
            .catch(function(){});
        });

        coverInput.addEventListener('change', function(){
            if (!coverItem || !coverInput.files.length) { return; }

            var cover = coverItem.querySelector('.vp-tile__cover');
            cover.classList.add('is-busy');

            var fd = new FormData();
            fd.append('image', coverInput.files[0]);

            fetch(API + '/events/' + coverItem.dataset.eventId + '/image', {
                method: 'POST',
                headers: H(),
                body: fd
            })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                cover.classList.remove('is-busy');
                if (!res.ok) { alert(res.data.message || T.error); return; }
                // Neu laden, damit auch der Entfernen-Button erscheint.
                window.location.reload();
            })
            .catch(function(){ cover.classList.remove('is-busy'); })
            .finally(function(){ coverInput.value = ''; });
        });
    }
})();
