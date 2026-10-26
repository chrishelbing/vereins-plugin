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
