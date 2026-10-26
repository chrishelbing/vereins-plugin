/**
 * Terminliste – Fotogalerie
 *
 * Hochladen, ansehen, löschen. Ohne Zustimmung zur Nutzung bleibt die
 * Auswahl gesperrt.
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

    var pModal    = document.getElementById('vp-photos-modal');
    var pLoading  = pModal.querySelector('.vp-modal__loading');
    var pContent  = pModal.querySelector('.vp-photos__content');
    var pGrid     = pModal.querySelector('[data-photos-grid]');
    var pQuota    = pModal.querySelector('[data-photos-quota]');
    var pError    = pModal.querySelector('[data-photos-error]');
    var pInput    = pModal.querySelector('#vp-photos-input');
    var pConsent  = pModal.querySelector('[data-photos-consent]');
    var pDropHint = pModal.querySelector('[data-photos-drophint]');
    var pDrop     = pModal.querySelector('[data-photos-drop]');
    var pZip      = pModal.querySelector('[data-photos-zip]');
    var pProgress = pModal.querySelector('[data-photos-progress]');
    var pBar      = pModal.querySelector('[data-photos-bar]');
    var pProgText = pModal.querySelector('[data-photos-progresstext]');
    var pClosed   = pModal.querySelector('[data-photos-closed]');
    var pItem     = null;   // Termin, dessen Galerie offen ist

    function closePhotos() {
        pModal.hidden = true;
        pItem = null;
        document.body.style.overflow = '';
    }

    pModal.addEventListener('click', function(e){
        if (e.target.hasAttribute('data-close')) { closePhotos(); }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !pModal.hidden) { closePhotos(); }
    });

    function photoError(msg) {
        pError.textContent = msg || '';
        pError.hidden = !msg;
    }

    /**
     * Ohne Zustimmung bleibt die Auswahl gesperrt – Drag & Drop lässt
     * sich technisch nicht per disabled-Attribut verhindern, deshalb
     * prüft uploadFiles() die Zustimmung zusätzlich vor dem Versand.
     */
    function updateConsentGate() {
        var ok = pConsent.checked;
        pInput.disabled = !ok;
        pDrop.classList.toggle('is-disabled', !ok);
        pDropHint.textContent = ok ? T.dropReady : T.dropLocked;
    }
    pConsent.addEventListener('change', function(){
        updateConsentGate();
        photoError('');
    });

    function human(bytes) {
        if (bytes < 1024) { return bytes + ' B'; }
        if (bytes < 1048576) { return Math.round(bytes / 1024) + ' KB'; }
        return (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }

    function renderPhotos(d) {
        var photos = d.photos || [];
        pGrid.innerHTML = '';

        if (!photos.length) {
            var empty = document.createElement('p');
            empty.className = 'vp-modal__empty';
            empty.textContent = T.noPhotos;
            pGrid.appendChild(empty);
        } else {
            photos.forEach(function(p){
                var fig = document.createElement('figure');
                fig.className = 'vp-photo';

                var link = document.createElement('a');
                link.href = p.full;
                link.target = '_blank';
                link.rel = 'noopener';

                var img = document.createElement('img');
                img.src = p.thumb;
                img.alt = '';
                img.loading = 'lazy';
                link.appendChild(img);
                fig.appendChild(link);

                var cap = document.createElement('figcaption');
                cap.textContent = p.uploader;
                fig.appendChild(cap);

                if (p.own || d.can_manage) {
                    var del = document.createElement('button');
                    del.type = 'button';
                    del.className = 'vp-photo__del';
                    del.title = T.remove;
                    del.textContent = '×';
                    del.dataset.photoId = p.id;
                    fig.appendChild(del);
                }

                pGrid.appendChild(fig);
            });
        }

        // Upload-Zeitraum: nach Ablauf gibt es nichts mehr hochzuladen,
        // dafür steht die vollständige Sammlung zum Download bereit.
        var up = d.upload || { open: true, unlimited: true };
        pDrop.hidden   = !up.open;
        pQuota.hidden  = !up.open;
        pClosed.hidden = up.open;

        if (!up.open) {
            pClosed.textContent = T.uploadClosed.replace('%s', up.deadline_text || '');
        } else if (!up.unlimited) {
            pClosed.hidden = false;
            pClosed.classList.add('is-hint');
            pClosed.textContent = up.days_left <= 1
                ? T.uploadLastDay
                : T.uploadOpen.replace('%1$d', up.days_left).replace('%2$s', up.deadline_text || '');
        } else {
            pClosed.classList.remove('is-hint');
        }

        // Kontingent
        var q = d.quota || {};
        pQuota.textContent = q.quota
            ? T.quota.replace('%1$s', human(q.used)).replace('%2$s', human(q.quota))
            : T.quotaFree.replace('%s', human(q.used || 0));
        pQuota.classList.toggle('is-full', q.quota > 0 && q.percent >= 90);

        pZip.hidden = photos.length === 0;

        // Zähler am Termin nachziehen
        if (pItem) {
            var stat = pItem.querySelector('.vp-photos__count');
            if (stat) {
                stat.textContent = photos.length
                    ? (photos.length === 1 ? T.photoOne : T.photoMany.replace('%d', photos.length))
                    : T.noneYet;
            }
        }
    }

    function loadPhotos() {
        if (!pItem) { return; }
        var url = API + '/events/' + pItem.dataset.eventId + '/photos'
                + '?instance_date=' + encodeURIComponent(pItem.dataset.instanceDate);

        fetch(url, { headers: H() })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) { pLoading.textContent = T.loadError; return; }
                renderPhotos(res.data);
                pLoading.hidden = true;
                pContent.hidden = false;
            })
            .catch(function(){ pLoading.textContent = T.loadError; });
    }

    root.addEventListener('click', function(e){
        var trigger = e.target.closest('.vp-photos__open');
        if (!trigger) { return; }

        pItem = trigger.closest('.vp-events__item');
        pZip.href = trigger.dataset.zip;

        pModal.querySelector('.vp-modal__title').textContent =
            pItem.querySelector('.vp-events__title').textContent.trim();
        pLoading.textContent = T.loading;
        pLoading.hidden = false;
        pContent.hidden = true;
        photoError('');
        pConsent.checked = false;
        updateConsentGate();
        pModal.hidden = false;
        document.body.style.overflow = 'hidden';
        loadPhotos();
    });

    // Foto löschen
    pModal.addEventListener('click', function(e){
        var del = e.target.closest('.vp-photo__del');
        if (!del) { return; }

        del.disabled = true;
        photoError('');

        fetch(API + '/photos/' + del.dataset.photoId, {
            method: 'DELETE',
            headers: H()
        })
        .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
        .then(function(res){
            if (!res.ok) { photoError(res.data.message || T.error); del.disabled = false; return; }
            renderPhotos(res.data);
        })
        .catch(function(){ photoError(T.error); del.disabled = false; });
    });

    /**
     * Upload: die Dateien werden einzeln geschickt, damit eine zu große
     * Datei nicht den ganzen Stapel scheitern lässt und der Fortschritt
     * mitläuft.
     */
    function uploadFiles(files) {
        if (!pItem || !files.length) { return; }

        if (!pConsent.checked) {
            photoError(T.consentNeeded);
            return;
        }

        var list  = Array.prototype.slice.call(files);
        var total = list.length;
        var done  = 0;
        var fails = [];

        photoError('');
        pProgress.hidden = false;
        pBar.style.width = '0%';
        pProgText.textContent = T.uploading.replace('%1$d', 0).replace('%2$d', total);

        function next() {
            if (!list.length) {
                pProgress.hidden = true;
                if (fails.length) { photoError(fails.join(' · ')); }
                loadPhotos();
                return;
            }

            var file = list.shift();
            var fd   = new FormData();
            fd.append('instance_date', pItem.dataset.instanceDate);
            fd.append('consent', pConsent.checked ? '1' : '0');
            fd.append('photo', file);

            fetch(API + '/events/' + pItem.dataset.eventId + '/photos', {
                method: 'POST',
                headers: H(),
                body: fd
            })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) {
                    fails.push(res.data.message || file.name);
                } else if (res.data.errors && res.data.errors.length) {
                    res.data.errors.forEach(function(err){ fails.push(err.message); });
                }
            })
            .catch(function(){ fails.push(file.name); })
            .finally(function(){
                done++;
                pBar.style.width = Math.round(done / total * 100) + '%';
                pProgText.textContent = T.uploading.replace('%1$d', done).replace('%2$d', total);
                next();
            });
        }

        next();
    }

    pInput.addEventListener('change', function(){
        uploadFiles(pInput.files);
        pInput.value = '';
    });

    // Drag & Drop am Rechner
    ['dragenter', 'dragover'].forEach(function(ev){
        pDrop.addEventListener(ev, function(e){
            e.preventDefault();
            pDrop.classList.add('is-over');
        });
    });
    ['dragleave', 'drop'].forEach(function(ev){
        pDrop.addEventListener(ev, function(e){
            e.preventDefault();
            pDrop.classList.remove('is-over');
        });
    });
    pDrop.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files) { uploadFiles(e.dataTransfer.files); }
    });
})();
