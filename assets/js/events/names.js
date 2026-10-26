/**
 * Terminliste – Overlay mit den Namen
 *
 * Zeigt auf Klick, wer zugesagt, abgesagt oder eine Aufgabe übernommen hat.
 * Die Namen werden erst hier nachgeladen, damit die Kachelliste schlank bleibt.
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
    var CFG  = VP.cfg;

    var modal    = document.getElementById('vp-rsvp-modal');
    var mTitle   = modal.querySelector('.vp-modal__title');
    var mLoading = modal.querySelector('.vp-modal__loading');
    var mContent = modal.querySelector('.vp-modal__content');

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    function fillNames(ul, list, withTask) {
        ul.innerHTML = '';
        if (!list.length) {
            var empty = document.createElement('li');
            empty.className = 'vp-modal__empty';
            empty.textContent = T.none;
            ul.appendChild(empty);
            return;
        }
        list.forEach(function(u){
            var li = document.createElement('li');
            li.textContent = u.name;

            // Begleitperson: gehört zu einem Mitglied, kommt aber
            // unabhängig von dessen eigener Antwort.
            if (u.via) {
                var via = document.createElement('span');
                via.className = 'vp-modal__task';
                via.textContent = T.viaMember.replace('%s', u.via);
                li.appendChild(via);
            }

            if (withTask && u.help_task) {
                var task = document.createElement('span');
                task.className = 'vp-modal__task';
                task.textContent = ' – ' + u.help_task;
                li.appendChild(task);
            }

            // Über einen Terminlink zugesagt, Kontakt noch offen.
            if (u.unconfirmed) {
                var mark = document.createElement('span');
                mark.className = 'vp-modal__pending';
                mark.title = T.unconfirmed;
                mark.innerHTML = CFG.iconAlert;
                li.appendChild(mark);
            }

            ul.appendChild(li);
        });
    }

    modal.addEventListener('click', function(e){
        if (e.target.hasAttribute('data-close')) { closeModal(); }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && !modal.hidden) { closeModal(); }
    });

    root.addEventListener('click', function(e){
        var toggle = e.target.closest('.vp-rsvp__toggle');
        if (!toggle) { return; }

        var item = toggle.closest('.vp-events__item');
        mTitle.textContent = item.querySelector('.vp-events__title').textContent.trim();
        mLoading.textContent = T.loading;
        mLoading.hidden  = false;
        mContent.hidden  = true;
        modal.hidden     = false;
        document.body.style.overflow = 'hidden';

        var url = API + '/events/' + item.dataset.eventId + '/attendance'
                + '?instance_date=' + encodeURIComponent(item.dataset.instanceDate);

        fetch(url, { headers: H() })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, data: d }; }); })
            .then(function(res){
                if (!res.ok) { mLoading.textContent = T.loadError; return; }
                var d = res.data;
                // "Zugesagt" listet Personen, nicht Mitglieder – eine
                // Begleitperson kommt auch dann, wenn das Mitglied absagt.
                var attending = d.attending || [];

                modal.querySelector('[data-yes-label]').textContent  = T.yesLabel.replace('%d', attending.length);
                modal.querySelector('[data-no-label]').textContent   = T.noLabel.replace('%d', d.no_count);
                modal.querySelector('[data-help-label]').textContent = T.helpLabel.replace('%d', d.helper_count);
                fillNames(modal.querySelector('[data-yes-names]'), attending);
                fillNames(modal.querySelector('[data-help-names]'), d.helpers, true);
                fillNames(modal.querySelector('[data-no-names]'), d.no);
                mLoading.hidden = true;
                mContent.hidden = false;
            })
            .catch(function(){ mLoading.textContent = T.loadError; });
    });
})();
