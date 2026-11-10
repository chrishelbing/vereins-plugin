# Vereins-Plugin – Funktionsumfang

> **Diese Datei ist bei jeder Änderung mitzuführen.**
> Sie ist die einzige Stelle, an der der Funktionsumfang zusammenhängend
> beschrieben ist. Wer eine Funktion ergänzt, ändert oder entfernt, pflegt
> sie hier nach – sonst veraltet sie innerhalb weniger Sitzungen und ist
> schlimmer als keine Dokumentation, weil man ihr dann noch glaubt.
>
> Stand: Version 1.14.6

---

## Wozu das Plugin da ist

Ein Verein organisiert Termine: Sommerfest, Zeltlager, Jahreshauptversammlung.
Daran hängen immer dieselben Fragen – wer kommt, wer bringt etwas mit, wer
hilft, wo sind hinterher die Fotos. Das Plugin bildet diesen Kreislauf auf
der Vereins-Website ab.

Zwei Grundentscheidungen prägen alles Weitere:

**Die Website ist der Hauptweg.** Mitglieder erledigen alles im Frontend über
Shortcodes. Das wp-admin-Backend ist für die Verwaltung durch Administratoren
gedacht, nicht für die Mitglieder.

**Die Hürde bleibt niedrig.** Wer über einen geteilten Terminlink kommt, kann
zusagen, ohne sich anzumelden oder zu registrieren. Erst danach wird nach
einem Namen gefragt.

---

## Auslieferungszustand

Ein leeres Plugin hilft niemandem: Bevor der erste Termin entstehen kann,
müsste jemand Termintypen erfinden und Mitbring- und Helferlisten von Hand
tippen. Deshalb bringt das Plugin einen Ausgangspunkt mit.

### Startdaten bei der Aktivierung

Bei der **ersten** Aktivierung legt `Core\Seeder` an:

- **Vier Termintypen** – Training und Übung, Spiel und Wettkampf, Fest,
  Arbeitseinsatz. Jeder mit Farbe und passend gesetzten Schaltern: Training
  hat weder Mitbringliste noch Helferaufgaben, der Arbeitseinsatz keine
  Fotos.
- **Sechs Vorlagen** – drei für die Mitbringliste (Fest, Arbeitseinsatz,
  Fahrt), drei für Helferaufgaben (Fest, Arbeitseinsatz, Fahrt). Die Mengen
  sind plausible Vorschläge für einen mittleren Verein, keine Vorschrift.
- Die **Verknüpfung** beider: Wo es passt, ist die Vorlage als Standard am
  Termintyp hinterlegt und wird beim Anlegen eines Termins vorbelegt.

**Warum so wenig.** Ausgewählt ist nach dem Alltag eines
Breitensportvereins: das wöchentliche Training als häufigster Termin, der
Spieltag, das Fest als der eine Anlass im Jahr, an dem Mitbringliste,
Helferliste und Fotos zusammenkommen, und der Arbeitseinsatz als der
wiederkehrende Grund, warum es Helferlisten überhaupt gibt. Eine längere
Vorschlagsliste sieht großzügig aus, kostet aber jeden Verein zuerst
Aufräumarbeit; Fehlendes ist schneller getippt als Überflüssiges
durchgesehen.

**„Spiel und Wettkampf“ hat bewusst keine Vorlage.** Helferaufgaben sind am
Typ eingeschaltet, Fotos auch – aber welche Aufgaben ein Spieltag mit sich
bringt, unterscheidet sich von Sportart zu Sportart zu stark, um es zu
raten. Die erste Liste erstellt der Verein selbst und speichert sie aus dem
Termin heraus als Vorlage.

Für die **Fahrt** (Trainingslager, Auswärtsfahrt) liegen beide Listen bereit,
aber bewusst ohne eigenen Termintyp – jeder Verein nennt sie anders. Wer sie
braucht, legt den Typ an und hinterlegt die Vorlagen dort. Dasselbe gilt für
Versammlung, Sitzung, Marktstand und Ressourcen wie das Vereinsheim: alles
weiter möglich, nur nicht mehr vorgegeben.

Zwei Sicherungen verhindern, dass die Startdaten ungefragt wiederkehren:
ein Marker in den Optionen (`vp_seed_done`) und ein Blick in die Tabelle –
wo schon Termintypen stehen, wird nichts angelegt. Wer die Vorschläge
löscht oder umbenennt, bekommt sie beim nächsten Update nicht zurück.

**Der Marker überlebt das Deaktivieren.** Optionen bleiben beim Deaktivieren
eines Plugins stehen; Deaktivieren und erneutes Aktivieren legt die
Startdaten deshalb *nicht* erneut an. Das ist Absicht – sonst stünde alles
Weggeräumte nach jeder Wartung wieder da.

**Auf Anforderung geht es trotzdem.** Unter *Verein → Einstellungen* steht
der Knopf „Fehlende Startdaten anlegen“, darüber die Zahl der fehlenden
Termintypen und Vorlagen. Angelegt wird nur, was dem Namen nach fehlt: Ein
Verein, der „Fest“ umgebaut oder mit einer eigenen Vorlage verknüpft hat,
behält seine Fassung. Doppelte entstehen dabei nicht.

**Terminserien werden nicht mitgeliefert.** Ein Termin braucht ein Datum,
und ein geratenes stünde falsch in der Liste. Serien legt der Verein im
Terminformular an.

---

## Personen: die vier Stufen

Das Verständnis dieser Stufen ist Voraussetzung für alles andere.

| Stufe | Wo gespeichert | Kann | Beispiel |
|---|---|---|---|
| **Gast** | `vp_people`, Herkunft `link` | Zusagen, Helferaufgaben, Mitbringliste – über den Terminlink | Nachbarin sagt zum Sommerfest zu |
| **Wartende Registrierung** | `vp_people`, Herkunft `registration` | nichts, bis freigegeben | jemand füllt `[vp_register]` aus |
| **Mitglied** | `wp_users` + `vp_members` | Mitgliederbereich, alle Termine, Fotos | aufgenommenes Vereinsmitglied |
| **Orgateam** | wie Mitglied, Rolle `vp_orgateam` | zusätzlich: Bedarf vorgeben und Posten bearbeiten, alle Termine bearbeiten, Titelbilder, Flyer | wer Termine betreut |

**Selbstregistrierung wartet standardmäßig.** Wer sich über `[vp_register]`
meldet, landet als wartende Registrierung in der Mitgliederverwaltung. Unter
*Verein → Einstellungen* lässt sich die Freischaltung abschalten
(`require_approval`); dann ist der Zugang sofort aktiv.

**Kein WordPress-Profil für Unbestätigte.** Gäste und wartende Registrierungen
liegen ausschließlich in `vp_people`. Ein Profil in der WordPress-Nutzer­
verwaltung entsteht erst bei der Aufnahme durch einen Administrator.

**Aufbewahrungsfrist für offene Kontakte.** Ein Gast, der einmal über einen
Terminlink zugesagt hat und nie wiederkommt, bliebe sonst unbegrenzt mit
Name, E-Mail und Telefonnummer in `vp_people` stehen. Ein täglicher Cron
(`Members\PeopleCleanup`) entfernt Einträge ohne Zusage/Aktivität seit
mindestens `people_retention_days` Tagen (Standard 180, einstellbar unter
*Verein → Einstellungen → Datenschutz*, 0 = nie automatisch löschen).
Maßgeblich ist die letzte Zusage, nicht das Anlegedatum – wer jedes Jahr
wiederkommt, verliert seinen Eintrag nicht nur, weil er ihn schon lange hat.
Aufgenommene Mitglieder sind davon nie betroffen.

**Personen-IDs** tragen das Vorzeichen als Unterscheidung: positiv = `wp_users`,
negativ = `vp_people`. Bei der Aufnahme werden alle Einträge – Zusagen,
übernommene Aufgaben, Begleitpersonen – von der negativen auf die neue
positive ID umgehängt. Es geht nichts verloren.

**Administratoren** sind eine eigene Achse: Nur sie kommen ins Backend und
dürfen Termine freigeben. Ein Administrator gilt immer auch als Orgateam.

**Mitglied bearbeiten.** In der Mitgliederverwaltung öffnet „Bearbeiten“
einen Dialog für Name, E-Mail und Telefon – etwa um einen Tippfehler zu
korrigieren (`MemberService::update_profile()`). Der Name wird an beiden
Stellen geändert, im Mitgliedseintrag und im WordPress-Profil, damit die
Person in der Liste und an Zusagen, Aufgaben und Fotos gleich heißt. Die
E-Mail ist zugleich die Anmeldeadresse; eine Adresse, die schon zu einem
anderen Zugang gehört, wird abgelehnt. Bei Administratoren und beim eigenen
Konto ist die E-Mail hier gesperrt – sie wird im WordPress-Profil geändert
(dieselbe Grenze wie bei Rollen und Löschung). Der Anmeldename
(`user_login`) bleibt immer unverändert. Offene Personen (Gäste, wartende
Registrierungen) lassen sich nicht bearbeiten, nur aufnehmen oder ablehnen.

**Recht auf Löschung.** Ein aufgenommenes Mitglied lässt sich in der
Mitgliederverwaltung vollständig löschen (`MemberService::erase_member()`):
Zusagen, übernommene Aufgaben, Begleitpersonen und Fotos werden immer
entfernt. Das WordPress-Konto fällt nur mit weg, wenn es ausschließlich
Vereinsrollen trägt und keine eigenen Beiträge oder Kommentare hat – sonst
bliebe von einem Redakteur, der auch im Verein war, kein Zugang zur Website
übrig. In diesem Fall bleibt das Konto bestehen, verliert aber die
Vereinsrollen. Für Gäste und wartende Registrierungen gibt es das bereits
länger über „Ablehnen“ (`reject_person()`).

**Anbindung an die WordPress-Bordmittel.** Unter *Werkzeuge → Persönliche
Daten exportieren* bzw. *… löschen* lässt sich zu einer E-Mail-Adresse
sowohl ein Mitglied als auch ein offener Kontakt (Gast/Registrierung)
finden (`Privacy\PersonalData`). Der Export listet Stammdaten, Zusagen,
Übernahmen aus Mitbringliste/Helferaufgaben, Begleitpersonen und Fotos.
Die Löschung nutzt dieselben Wege wie oben beschrieben – `erase_member()`
bzw. `reject_person()` – und räumt zusätzlich offene Einmalcode-Anfragen
für diese Adresse ab.

---

## Termine

### Termintypen (Backend)

Frei definierbar, mit Farbe. Je Typ wird festgelegt, ob Termine dieser Art
eine **Mitbringliste**, **Helferaufgaben** und **Fotos** haben. Optional eine
Standardvorlage je Bereich, die beim Anlegen vorbelegt wird.

Ein Termintyp lässt sich als **Ressource** kennzeichnen (Bus, Vereinsheim).
Das ist bisher nur eine Kennzeichnung in der Typenübersicht – eine
Belegungsprüfung gibt es nicht (siehe „Bewusste Grenzen“).

### Termine anlegen und freigeben

Anlegen darf **jedes Mitglied** über `[vp_create_event]`, Administratoren
zusätzlich im Backend. **Freigeben dürfen nur Administratoren, nur im
Backend.** Bis dahin ist ein Termin nicht sichtbar. In der Übersicht steht,
wer ihn angelegt und wer ihn freigegeben hat.

### Infotermine ohne Zusage

Am Termintyp steht der Schalter „Mitglieder können zu Terminen dieses Typs
zu- und absagen“ (`allows_rsvp`, Standard an). Ohne ihn sind die Termine
reine Information: Die Kachel zeigt weder Zu-/Absage-Knöpfe noch Zähler,
und `POST /events/{id}/attendance` lehnt mit `no_rsvp` (409) ab – die
Oberfläche allein wäre keine Sperre.

Helferaufgaben und Mitbringliste setzen Zusagen voraus. Im Typ-Formular
werden sie deshalb mit ausgegraut und als 0 gespeichert; die Kachel prüft
es zusätzlich, falls an einem Typ noch alte Werte stehen. Fotos bleiben
unabhängig davon möglich.

Banner und Flyer passen ihre Worte an: Der Bannerknopf heißt „Zum Termin“
statt „Jetzt zusagen“, der Flyer „Scannen für alle Infos“. In der
Backend-Übersicht steht unter Rückmeldungen „nur Info“.

Fehlt das Feld – alte Daten, eine Zeile ohne Typ-Join –, gilt wie vor dem
Schalter: Zusagen sind möglich (`EventTypeModel::rsvp_allowed()`).

### Öffentliche Termine

Am Termin (Backend) steht „Öffentlich – auch ohne Anmeldung in der
Terminliste sichtbar“ (`vp_events.is_public`, Standard aus). Besucher ohne
Anmeldung sehen in `[vp_events]` dann statt „Bitte melde dich an …“ die
kommenden öffentlichen Termine – gleicher Zeitraum und dieselbe Obergrenze
wie bei Mitgliedern (`days`, `limit`), aber nur kommende.

Die Kacheln zeigen Datum, Ort, Beschreibung und Tags, aber keine Namen,
Zähler, Helferaufgaben, Mitbringliste oder Fotos. Statt der Zusage-Knöpfe
steht „Zu- oder absagen“: ein Link auf den signierten Terminlink, über den
Gäste wie gewohnt ohne Konto zusagen. So bleibt die Gast-Kennung je Termin
gebunden – eine Liste mit mehreren Gast-Zusagen auf einer Seite bräuchte
einen eigenen Schlüssel je Kachel.

Den Schalter gibt es nur im Backend: Was öffentlich auf der Website steht,
entscheiden Administratoren. Nicht freigegebene Termine und Entwürfe
bleiben unsichtbar, auch mit gesetztem Schalter (`in_range()` liefert nur
freigegebene).

### Beschreibung mit Links

Die Beschreibung steht auf der Kachel unter Datum und Ort. Links darin
werden erkannt (`Support\RichText`), ohne Editor:

| Eingabe | Ergebnis |
|---|---|
| `https://…` oder `www.…` | Link, angezeigt als gekürzte Adresse |
| `[Ausschreibung](https://…)` | Link mit dem Text „Ausschreibung“ |

Links öffnen in einem neuen Tab. Alles Übrige wird maskiert – HTML aus dem
Feld landet nie ungefiltert auf der Seite; Zeilenumbrüche bleiben. Im
Banner (selbst ein Link) erscheint nur der Text, auf dem Flyer der Text und
nackte Adressen zum Abtippen.

Wie es geht, zeigt ein **?** neben der Feldbeschriftung „Beschreibung“ –
im Backend, im Anlegen-Formular und im Bearbeiten-Dialog. Es öffnet beim
Überfahren, Antippen oder per Tastatur einen kleinen Tooltip mit Beispielen
(`EventForm::format_hint()`, für alle drei Formulare dieselbe Stelle).

**Kompakte Kacheln.** Zugeklappt zeigt eine Kachel nur Kopf, Termintyp,
Titel, Tags, Zeit und Ort (höchstens zwei Zeilen), die Beschreibung
(höchstens drei Zeilen, unten ausgeblendet) und die Knöpfe Zusagen/Absagen
samt „+2“ für Begleitpersonen. Die Zahlenzeile (Zusagen, Absagen, Helfer)
sowie Helferaufgaben, Mitbringliste und Fotos erscheinen erst nach
**„Details anzeigen“**; „Weniger anzeigen“ klappt wieder zu. Ein Klick in
den gekürzten Text klappt ebenfalls auf, Links darin bleiben Links.

Offen bleiben: vergangene Termine (dort zählen nur noch die Fotos), eine
Liste mit nur einem Termin (Terminlink) und der Termin, auf den die Adresse
mit `#vp-termin-…` zeigt (Banner, Flyer). Eine aufgeklappte Kachel zieht
die Nachbarin in derselben Reihe nicht mit in die Höhe. Ohne JavaScript
bleibt alles offen; gemessen und gesteuert wird in `tile.js`.

### Ort → Kartenprogramm

Die Ortsangabe auf der Kachel ist ein Link, der die Routenplanung mit dem
Ort als Ziel öffnet (`Support\MapLink`). Im HTML steht der Google-Maps-
Link – auf Android öffnet er die Google-Maps-App, sonst den Browser. Auf
iPhone, iPad und Mac tauscht `tile.js` ihn gegen einen Apple-Karten-Link,
der die Karten-App öffnet. Je vollständiger die Ortsangabe am Termin
(Name, Straße, Ort), desto sicherer trifft das Kartenprogramm das Ziel.

Nicht im Banner: Der ist als Ganzes schon ein Link zum Termin.

### Tags

Der Termintyp gibt Tags vor (Feld „Tags“, durch Komma getrennt), etwa
Altersklassen: U9, U11, U13, Veteranen. Am Termin werden daraus die
zutreffenden angehakt; das Backend-Formular zeigt jeweils die Auswahl des
gewählten Typs. Auf der Kachel stehen sie als kleine Pillen unter dem
Titel, in der Backend-Übersicht unter dem Titel.

- Gespeichert wird als JSON-Liste in `vp_event_types.tags` und
  `vp_events.tags`; Umgang damit in `Support\Tags`.
- Am Termin bleibt nur, was der Typ kennt, in der Reihenfolge des Typs
  (`Tags::restrict()`) – beim Speichern im Backend wie über die
  Schnittstelle (`tags` in `POST`/`PUT /events`). Wer ein Tag am Typ
  streicht, verliert es am Termin beim nächsten Speichern.
- Ein `PUT` ohne `tags` lässt die Tags unverändert.
- Bei Serien gelten die Tags für alle Termine der Serie.
- Auswählbar überall, wo Termine angelegt oder geändert werden: im
  Backend, in `[vp_create_event]` und im Bearbeiten-Dialog an der Kachel
  (alle drei zeigen nur die Tags des gewählten Typs). Im Dialog nicht bei
  „nur dieser Termin“ – Tags gelten für die ganze Serie.
- Die Terminliste lässt sich nicht nach Tags filtern.

### Entwurf und live

Jeder Termin, den ein Administrator verwaltet, ist entweder **Entwurf** oder
**live**. Ein Entwurf steht nur im Backend: nicht in `[vp_events]`, nicht im
Banner, nicht über einen geteilten Link, keine Fotos, kein Flyer. Live heißt
sichtbar wie bisher.

Umgeschaltet wird in beide Richtungen und jederzeit – in der Übersicht mit
„Live schalten“ bzw. „Auf Entwurf“, im Formular mit dem Häkchen „Live – auf
der Website sichtbar“. Neue Termine im Backend sind vorbelegt live, wie vor
der Einführung des Schalters. Die Übersicht hat dazu den Reiter „Entwürfe“.

Technisch ist der Entwurf ein vierter Wert von `approval_status` (`draft`).
Damit greift er überall, wo bisher schon nur `approved` durchkam – es gibt
keine zweite Sichtbarkeitsregel, die irgendwo vergessen werden könnte.
`approved` heißt in der Oberfläche jetzt „Live“.

Der Schalter gilt nur zwischen Entwurf und live
(`EventModel::set_live()`). Ein vorgeschlagener Termin geht weiter über
Genehmigen/Ablehnen, ein abgelehnter bleibt abgelehnt; das Speichern im
Formular rührt deren Status nicht an. Umschalten trägt sich nicht als
Prüfung ein („Geprüft von“ bleibt, wie es war).

### Termine bearbeiten (Frontend)

An jeder Kachel der Terminliste steht für Berechtigte ein **Stift**. Er
öffnet denselben Feldersatz, mit dem Termine angelegt werden – die Felder
liegen in `Frontend\EventForm` und werden von beiden Seiten benutzt, damit
sie nicht auseinanderlaufen.

**Wer darf:** wer den Termin angelegt hat, dazu Orgateam und
Administratoren. Nachzulesen in `Capabilities::can_edit_event()`; geprüft
wird serverseitig in `PUT /events/{id}`, `DELETE /events/{id}` und
`POST /events/{id}/exceptions`. Der Stift erscheint nur dort, wo dasselbe
Recht schon beim Rendern zutrifft.

Ohne diese Regel dürfte jedes freigeschaltete Mitglied jeden Termin
umschreiben – die Freigabe durch einen Administrator ließe sich damit im
Nachhinein aushebeln. Wer ändert, wird dabei **nicht** zum Urheber: Sonst
verlöre der ursprüngliche Anleger sein Bearbeitungsrecht an den, der
zuletzt gespeichert hat.

Eine Änderung schickt den Termin **nicht** erneut in die Freigabe. Wer
Änderungen wieder bestätigen lassen will, braucht dafür eine eigene Regel.

**Bei Serien** fragt der Dialog wie ein Kalenderprogramm nach, bevor er
speichert:

| Auswahl | Wirkung | Weg |
|---|---|---|
| Nur dieser Termin | die übrigen bleiben unverändert | Ausnahme `modified` |
| Die ganze Serie | gilt für alle Termine der Serie | `PUT` auf den Stammsatz |

Bei „nur dieser Termin“ sind Termintyp und Wiederholung gesperrt – eine
Ausnahme kennt nur Titel, Beschreibung, Ort und Zeiten. Dasselbe gilt fürs
Entfernen: Die Serie verschwindet mit `DELETE`, der einzelne Termin wird
als `cancelled` abgelegt und fällt damit aus.

Über einen öffentlichen Terminlink erscheint kein Stift – dort steht nicht
fest, wer klickt.

### Serien und Ausnahmen

Termine können sich täglich, wöchentlich (mit Wochentagsauswahl), monatlich
oder jährlich wiederholen, begrenzt durch Enddatum oder Anzahl. Einzelne
Instanzen lassen sich abweichend ändern oder absagen.

Eine Termininstanz ist immer das Paar aus **Termin-ID und Instanzdatum** –
aufgelöst über `Events\InstanceLocator`.

### Mehrtägige Termine

**Ganztägig** heißt: nur Tage, keine Uhrzeiten. Mit dem Häkchen werden
Start und Ende in allen drei Formularen zu reinen Datumsfeldern (die
Uhrzeit wird gemerkt, falls das Häkchen wieder rausgeht). Gespeichert wird
vom ersten Tag 00:00 bis zum letzten Tag 23:59 (`DateRange::full_day_bounds()`,
im Backend und in der Schnittstelle). Vorher landete ein Sa–So-Termin mit
Ende „So 00:00“ als eintägiger Termin am Samstag – der Kopf der Kachel
zeigt jetzt beide Tage („14.–15.“).

Ein Camp von Freitag 16 Uhr bis Sonntag 17 Uhr wird als solches dargestellt:
„Mehrtägig · 3 Tage“ statt „Ganztägig“, auf der Kachel `11.–13. Sep`, dazu
die ausführliche Angabe mit Wochentagen. Endet ein Termin punktgenau um
Mitternacht, zählt der Vortag als letzter Tag – sonst wäre jede lange Feier
zweitägig.

### Banner auf der Website

Ein Termin lässt sich auf einer beliebigen Seite – gedacht ist die
Startseite – als Banner hervorheben. Eingeschaltet wird das **am Termin**,
im Terminformular des Backends: „Als Banner auf der Website zeigen“ und
„Sichtbar ab … Tagen vor Beginn“ (vorgeschlagen 14, 0 = sofort). Gespeichert
in `vp_events.banner_days_before`; NULL heißt „kein Banner“. In der
Terminübersicht trägt ein solcher Termin das Kennzeichen „Banner“.

Wo der Banner erscheint, bestimmt der Shortcode `[vp_banner]`. Die Seite
muss also nur einmal vorbereitet werden; danach entscheidet allein der
Termin, was dort steht. **Steht nichts an, gibt der Shortcode nichts aus** –
keine leere Fläche, kein „Keine Termine“.

- **Zeitfenster**: ab Tagesbeginn, so viele Tage vor dem Start, bis zum
  Terminende. Danach verschwindet der Banner von selbst; niemand muss nach
  dem Sommerfest daran denken, die Startseite aufzuräumen. Gerechnet wird
  auf dem Datum, nicht auf der Uhrzeit – „14 Tage vorher“ beginnt morgens.
- **Mehrere Banner**: der nächste Termin zuerst; `limit` (Standard 1,
  höchstens 5) legt fest, wie viele untereinander stehen.
- **Serien** belegen höchstens einen Platz, und zwar mit der nächsten
  Instanz. Fällt die aus, rückt die folgende nach, sobald ihr eigenes
  Fenster offen ist. Ein wöchentliches Training mit Banner füllt also nicht
  die Startseite.
- **Nur freigegebene Termine.** Ein von einem Mitglied vorgeschlagener
  Termin erscheint auch mit gesetztem Schalter erst nach der Freigabe,
  ein Entwurf erst, wenn er live geschaltet ist. Den
  Schalter selbst gibt es nur im Backend, also nur für Administratoren.
- **Inhalt**: Titelbild (sonst die Farbe des Termintyps), Datumsblock,
  „Läuft gerade“/„Heute“/„Morgen“/„In 5 Tagen“, Termintyp, Titel, Zeitraum,
  Ort, gekürzte Beschreibung und ein Knopf (Beschriftung über `button`).

**Der Banner ist öffentlich.** Anders als `[vp_events]` sieht ihn jeder
Besucher, auch ohne Anmeldung – er soll gerade die erreichen, die noch nicht
dabei sind. Wer den Schalter setzt, macht Titel, Zeit, Ort und
Kurzbeschreibung damit bewusst öffentlich. Namen, Zahlen und Listen stehen
nicht darauf.

**Der Klick führt über den signierten Terminlink zur Zusage** – derselbe Weg
wie beim QR-Code des Flyers. Gäste können sofort zusagen, Helferaufgaben und
die Mitbringliste übernehmen. Angemeldete Mitglieder sehen auf der
Terminseite ohnehin die ganze Liste; der Link trägt deshalb zusätzlich eine
Sprungmarke (`#vp-termin-<id>-<datum>`), die zur richtigen Kachel führt.
Gibt es keine Seite mit `[vp_events]`, bleibt der Banner ein reiner Hinweis
ohne Link und Knopf.

Die Suche nach dieser Seite liegt jetzt an einer Stelle
(`PublicLink::events_page()`), die Flyer und Banner teilen. Der Fund wird
einen Tag gemerkt und bei jedem Aufruf gegengeprüft, damit die Startseite
nicht bei jedem Besuch alle Seiteninhalte durchsucht; ein Fehlschlag wird
nicht gemerkt.

Die Auswahl (`Events\BannerSelector`) ist ohne Datenbank prüfbar und in
`tools/bannertest.php` abgesichert. Das Aussehen (`assets/css/banner.css`,
nur geladen, wenn ein Banner erscheint) deckt kein Test ab.

**Zwischenspeicher beachten.** Ein Seiten-Cache liefert die Startseite so
aus, wie sie beim Erzeugen war – Erscheinen, Verschwinden und die Angabe
„In 5 Tagen“ folgen dann erst, wenn der Cache erneuert wird.

---

## Zu- und Absagen

Mitglieder sagen je Termininstanz zu oder ab. Sichtbar sind **Zahlen** direkt
und **Namen** hinter einem Klick – bei fünfzig Zusagen wäre eine offene Liste
unbrauchbar.

### Begleitpersonen

Weil aus einer Familie mehrere kommen, lässt sich die Zusage um Begleit­
personen erweitern. Namen werden je Mitglied als **persönliche Vorschlagsliste**
gespeichert, damit man sie nicht jedes Mal tippt.

**Mehrere auf einmal.** Im Dialog „Wer kommt mit?“ steht jede Person als
eigene Zeile mit „Dabei“/„Nicht dabei“. Im Feld „Weitere Personen
mitbringen“ ist ein Name eine Person; mehrere lassen sich auf einmal
eintragen, getrennt durch Komma, Semikolon, „und“, „&“ oder „+“ – „Mia,
Tom“ sind zwei Personen, nicht eine dieses Namens
(`CompanionModel::split_names()`, auf dem Server, damit es auch für eine
App gilt). Über dem Feld steht die Summe („Zusammen dabei: 3 (du und 2
Begleitperson(en))“). Am Termin zeigt das Abzeichen „+2“ die Zahl der
eigenen Begleitpersonen.

**Eigene Antwort und Begleitpersonen sind unabhängig.** Man kann selbst
absagen und die Kinder trotzdem anmelden. In der Auswertung erscheinen sie
deshalb bei den Zusagen, mit dem Vermerk, zu wem sie gehören.

---

### Auf dem Handy

Alle Eingabefelder des Plugins – in den Dialogen, in der Terminliste, im
Termin-, Anmelde- und Registrierungsformular – haben auf Touch-Geräten und
schmalen Bildschirmen 16 px Schrift. Darunter zoomt Safari auf iPhone und
iPad beim Antippen heran und zoomt nicht von selbst zurück; das Formular
stand dann vergrößert und abgeschnitten da. Dialoge nutzen auf dem Handy
die volle Breite und höchstens die Bildschirmhöhe. Das Namensfeld im Dialog
„Wer bist du?“ bekommt den Cursor nur am Rechner – auf dem Handy würde die
Tastatur sonst das Formular verdecken, bevor man es gelesen hat.

## Mitbringliste und Helferaufgaben

Technisch dasselbe, per `kind` getrennt:

- **Mitbringliste** – das Orgateam gibt Bedarf vor („3× Kuchen“), Mitglieder
  übernehmen. Zusätzlich kann jeder einen freien Eintrag machen („Ich bringe
  Salat mit“).

**Mehr als ein Stück (nur Mitbringliste).** Wer beim Bedarf auf
„Übernehmen“ klickt, wird nach der Anzahl gefragt – aber nur, wenn noch mehr
als ein Stück offen ist; sonst wird direkt übernommen. Von 30 Brötchen trägt
man so 15 ein, nicht fünfzehnmal eins. Wer schon übernommen hat, ändert die
Zahl über „Anzahl ändern“ – die eigene Menge wird ersetzt, nicht addiert.
Mehr als offen ist geht nicht; die Meldung nennt dann die Höchstzahl
(`ItemModel::available_for()`). Begleitpersonen werden genauso gefragt.

**Helferaufgaben: eine Person, ein Platz.** Beim Übernehmen gibt es keine
Anzahl; `ItemModel::claim()` setzt sie bei `kind = help` immer auf 1, auch
wenn eine App mehr schickt. Wie viele Helfer eine Aufgabe braucht
(„Aufbau: 4“), legt weiterhin das Orgateam fest.
- **Helferaufgaben** – legt nur das Orgateam an („Auf- und Abbau“, „Catering“,
  „Einlass“). Mitglieder übernehmen sie.

Auch **Begleitpersonen können Aufgaben übernehmen** – eine Familie teilt sich
mehrere Posten.

Die Helferzahl wird aus den übernommenen Aufgaben abgeleitet, nicht aus einem
Häkchen.

**Stift und Papierkorb.** Hinter dem Text jedes Postens stehen kleine
Symbole statt eigener Knöpfe: der Papierkorb für das Orgateam und für den
eigenen freien Eintrag (mit Rückfrage, weil Übernahmen daran mit
verschwinden), der Stift nur für das Orgateam. Die Knopfreihe darunter
bricht um, statt aus dem Fenster zu laufen. Gegen Theme-Stile (Eingabefelder
mit voller Breite, Knöpfe in Großbuchstaben) sind Felder und Knöpfe der
Liste über die ID des Overlays abgesichert.

**Nachträglich ändern (Orgateam).** Ein Klick auf den Stift: Der Text wird an Ort und Stelle zum Eingabefeld,
beim Bedarf steht die Menge daneben (bei Helferaufgaben: wie viele Helfer
gebraucht werden). Enter oder ein Klick
daneben speichert, Escape bricht ab. Korrigierbar ist die Bezeichnung – etwa ein Tippfehler –
und beim Bedarf zusätzlich die Menge. Übernahmen bleiben erhalten, weil sie
an der ID hängen, nicht am Text. Die Menge sinkt nie unter die bereits
übernommenen Stücke, sonst stünde „0 offen“ neben drei Zusagen. Bei freien
Einträgen („Ich bringe Salat mit“) bleibt die Menge 1. Gilt für
Mitbringliste und Helferaufgaben gleichermaßen (`PATCH /items/{id}`,
`ItemModel::update()`); Mitglieder ohne Orgateam-Rolle bekommen 403.

### Vorlagen

Wiederverwendbare Listen je Art, im Backend gepflegt und **aus einem
bestehenden Termin erzeugbar**. Je Termintyp eine Standardvorlage, beim
Anlegen überschreibbar. Sechs Vorlagen sind bereits vorhanden – siehe
„Auslieferungszustand“.

Eine Vorlage wird **kopiert, nicht verknüpft**: Der Termin bekommt eigene
Posten, spätere Änderungen an der Vorlage erreichen ihn nicht von selbst.
Wechselt ein Termin auf einen Typ ohne Mitbringliste oder Helfer, werden
die Posten nur ausgeblendet; beim Zurückwechseln sind sie samt Übernahmen
wieder da.

**Vorlage nachträglich anwenden**: Beim Bearbeiten im Backend steht unter
„Vorlage anwenden“ je Art eine Auswahl, vorbelegt mit „nicht anwenden“.
Wer eine Vorlage (oder den Standard des Typs) wählt, bekommt beim
Speichern nur die **fehlenden Posten** dazu – gleiche Bezeichnung zählt als
vorhanden. Vorhandene Posten, Übernahmen und nicht mehr in der Vorlage
stehende Posten bleiben unangetastet; Aufräumen geschieht im Frontend.
Angewendet wird auf das Datum des Terminbeginns, bei Serien also nur auf
die erste Instanz (`TemplateModel::resolve_choice()`).

---

## Fotos

Mitglieder laden Fotos je Termininstanz hoch – **Mehrfachauswahl**, auf dem
Handy direkt aus der Fotos-App, am Rechner per Drag & Drop. Beim Hochladen
werden Bilder verkleinert und von Standortdaten befreit.

Begrenzt wird über Einstellungen: Speicher je Mitglied und Termin, maximale
Dateigröße, längste Kante.

**Upload-Zeitraum**: standardmäßig 14 Tage nach Terminende, global einstellbar,
am einzelnen Termin übersteuerbar. Das Feld dafür erscheint im Terminformular
**nur, wenn der gewählte Termintyp Fotos erlaubt** – und verschwindet sofort,
wenn ein anderer Typ gewählt wird. Vorher stand dort bei jedem Training eine
Frist mit dem Hinweis, sie greife ohnehin nicht; wer eine Einstellung
angeboten bekommt, darf erwarten, dass sie etwas bewirkt. Der eingetragene
Wert wird beim Ausblenden nicht verworfen, damit ein versehentlicher
Typwechsel die Eingabe nicht verschluckt. Nach Ablauf werden die Teilnehmer
benachrichtigt, dass die Sammlung vollständig ist und als ZIP heruntergeladen
werden kann. Ein täglicher Cron-Job verschickt Vorwarnung und Abschlussinfo
und räumt abgelaufene Fotos auf.

Vergangene Termine bleiben in `[vp_events]` sichtbar, **solange ihre Fotos
abrufbar sind** – danach verschwinden sie von selbst.

Fotos sind **Mitgliedern vorbehalten**: Gäste über einen Terminlink kommen
nicht heran, weil dort Personen abgebildet sind und das Kontingent an einer
Identität hängt.

**Auflösung**: Fotos werden nach dem Upload auf eine konfigurierbare längste
Kante herunterskaliert (`photo_max_edge`, Standard 2000 px) und mit Qualität
82 % neu komprimiert – für Website und soziale Medien reichlich, da beide
i. d. R. deutlich kleiner ausspielen.

**Zustimmung zur Verwendung ist Pflicht.** Vor dem ersten Foto einer Sitzung
muss eine Checkbox bestätigt werden: „Achtung, hiermit bestätigst du, dass
das Foto auf der Website und in sozialen Medien verwendet werden kann.“ Ohne
Häkchen bleibt die Auswahl gesperrt (auch Drag & Drop). Die Zustimmung wird
zusätzlich serverseitig geprüft und je Foto mit Zeitstempel in `consent_at`
festgehalten – das Frontend allein wäre kein Nachweis.

**Quelle im Attachment.** Beim Speichern in der Mediathek wird der
Anzeigename der hochladenden Person als Beschreibung (`post_content`) am
Attachment hinterlegt, nicht im Dateinamen – sonst stünde der Klarname in der
öffentlichen Bild-URL, sobald das Foto auf der Website eingebunden ist.
Redakteure finden die Quelle beim Bearbeiten des Mediums.

---

## Teilnahme ohne Anmeldung

Das Orgateam kopiert an der Kachel einen **signierten Terminlink**. Wer ihn
öffnet, sieht genau diese eine Termininstanz und kann sofort handeln. Erst
beim ersten Speichern erscheint die Frage nach dem Namen; die begonnene
Aktion wird gemerkt und danach ausgeführt.

- Nur der **Name** ist nötig. Eine E-Mail ist freiwillig und dient der
  Benachrichtigung sowie der Wiedererkennung.
- Die Zusage gilt **sofort**; eine Bestätigungsmail folgt nach.
- Ein Cookie erkennt beim nächsten Mal wieder.
- **Namen anderer bleiben verborgen** – auch in der Schnittstelle. Fremde
  Übernahmen erscheinen nur als „vergeben“.
- Offen sind Zusage, Helferaufgaben und Mitbringliste. **Nicht** Fotos.

Unbestätigte Kontakte sind in Frontend-Listen, Backend-Listen und CSV-Exporten
gekennzeichnet.

---

## Flyer und QR-Codes

Zu jeder Termininstanz erzeugt das Orgateam einen Aushang. Drei Formate:

| Format | wofür |
|---|---|
| **A4 hoch** | Schaukasten, Gemeindehaus – Druck oder PDF über den Browser |
| **1080 × 1080** | WhatsApp-Gruppen und Status, als PNG herunterladbar |
| **nur QR-Code** | für eigene Gestaltung |

Auf dem Flyer: Titelbild, Datumsblock, Termintyp in seiner Farbe, Zeitraum,
Ort, Kurzbeschreibung, QR-Code mit Aufforderung, Absenderzeile mit Logo.
Helferbedarf und Mitbringliste sind **abwählbar und standardmäßig aus** – ein
gedruckter Aushang veraltet, sobald jemand zusagt.

Der **QR-Encoder ist selbst gebaut** (`Support\QrCode`), ohne externe
Abhängigkeit, Ausgabe als SVG. Findet das Plugin keine Seite mit
`[vp_events]`, warnt die Flyer-Seite – der Code zeigte sonst ins Leere.

Absenderangaben (Vereinsname, Ansprechperson, Fußzeile, Logo) stehen in den
Einstellungen.

---

## Anmeldung und Rollen

### Zwei gleichwertige Wege

Passwort über `[vp_login]` und **Einmalcode** per E-Mail. Für die App gibt es
zusätzlich Bearer-Token (`vp_sessions`).

Einen Code gibt es nur für Adressen mit Mitgliedskonto: Für jede andere
antwortet `request-otp` mit `unknown_account` (404) und dem Hinweis, die
Adresse aus dem Vereinskonto zu nehmen. Vorher kam der Code an, beim
Bestätigen fand sich kein Konto, und die Meldung lautete „Ungültiger
Code“ – obwohl er stimmte. `verify_otp()` sucht deshalb auch zuerst das
Konto und prüft dann den Code, damit kein richtiger Code verbraucht wird.
Es gilt immer nur der zuletzt angeforderte Code; der Knopf „Code senden“
ist gesperrt, bis die Antwort da ist.

Einmalcode-Anfragen (samt IP-Adresse) werden täglich um 3:15 Uhr per Cron
aufgeräumt (`Auth\OtpCleanup`), sobald sie abgelaufen sind – nicht erst bei
der nächsten Anmeldung.

**Ein Token gilt 30 Tage ab der letzten Benutzung, nicht ab der Ausstellung.**
Jede erfolgreiche Auflösung verlängert die Frist. Eine feste Frist ab
Ausstellung hätte auch den hinausgeworfen, der die App täglich öffnet – aus
seiner Sicht meldet sie sich eines Morgens grundlos ab. So trifft die Frist
nur, wer einen Monat lang nicht hineingesehen hat; dort ist eine neue
Anmeldung zumutbar. Widerrufene und bereits abgelaufene Token werden dabei
nicht wiederbelebt.

**Das WordPress-Cookie ist beim Einmalcode abwählbar.** `verify-otp` setzt
zusätzlich zum Token eine Website-Session – das braucht das Frontend, die App
nicht. Mit `with_cookie=false` bleibt es aus.

**SMS und WhatsApp gibt es nicht.** Das Plugin verschickt Einmalcodes nur
per E-Mail. Für beide Wege stehen lediglich Anschlussstellen bereit, an die
ein Entwickler einen eigenen Versand hängen könnte. Ohne angehängten Versand
(`vp_send_sms_otp`, `vp_send_whatsapp_otp`) antwortet `request-otp` mit
`channel_unavailable` statt mit einem allgemeinen Versandfehler – sonst
wartete jemand auf eine Nachricht, die nie losgeschickt wurde. Geprüft wird
das vor Rate-Limit und Codeerzeugung, damit eine aussichtslose Anfrage keinen
Versuch verbraucht.

### Rollen

`vp_vereinsmitglied` („Verein: Mitglied“) und `vp_orgateam` („Verein: Orgateam“).
Beschriftungen tragen das Präfix „Verein:“, damit in der Nutzerverwaltung
erkennbar ist, woher sie stammen.

**Vereinsrollen werden zusätzlich vergeben, nie ersetzend.** Ein Redakteur,
der ins Orgateam kommt, behält Rolle, Rechte und Backend. Das Plugin nimmt
nie etwas weg, was die Website schon vergeben hatte.

**Ausgesperrt aus wp-admin wird nur, wer ausschließlich Vereinsrollen trägt.**

**Status und Vereinsrolle laufen synchron:** Ohne Vereinsrolle ist niemand
aktives Mitglied; Deaktivieren nimmt die Vereinsrolle mit, lässt Rollen der
Website aber stehen.

In der Mitgliederverwaltung sind **alle** Rollen einer Person sichtbar und
änderbar, getrennt nach „Verein“ und „Website“. Angeboten wird nur, was
WordPress selbst zur Vergabe freigibt; das eigene Konto und Administratoren
bleiben gesperrt.

---

## Was wo zu finden ist

### Shortcodes

| Shortcode | Zweck |
|---|---|
| `[vp_events]` | Terminliste als Kacheln, mit allem daran; zeigt bei gültigem Schlüssel auch ohne Anmeldung einen einzelnen Termin |
| `[vp_create_event]` | Termin vorschlagen (bearbeitet wird über den Stift an der Kachel in `[vp_events]`) |
| `[vp_banner]` | Hervorgehobene Termine als Banner, z. B. auf der Startseite – öffentlich, verschwinden nach Terminende von selbst |
| `[vp_login]` | Anmeldung mit Passwort oder Einmalcode |
| `[vp_register]` | Selbstregistrierung |

Eine stets aktuelle Übersicht steht im Backend unter *Verein → Einstellungen*.

### Backend

*Verein* → Termine (inkl. Freigabe, Teilnehmerlisten, CSV-Export, Flyer),
Termintypen, Vorlagen, Mitglieder (inkl. offener Personen und Rollen),
Einstellungen (inkl. Startdaten und Shortcode-Übersicht).

**Anlegen und Bearbeiten laufen über Dialogfenster.** Vorher stand jedes
Formular unter der Tabelle: Ein Klick auf „Bearbeiten“ lud die Seite neu und
füllte weit unten ein Formular, das genauso aussah wie das zum Anlegen –
welcher Eintrag gemeint war, blieb offen. Jetzt öffnet sich ein Dialog über
der Seite, der den Namen des Eintrags im Kopf trägt; die betroffene Zeile
der Tabelle bleibt zusätzlich markiert, auch wenn der Dialog geschlossen
wird. Über der Tabelle steht der Knopf zum Anlegen.

Umgesetzt mit dem `<dialog>`-Element des Browsers (`Admin\AdminModal`), ohne
Bibliothek: Tastaturbedienung, Fokusfalle und Abdunkeln bringt es selbst
mit. Der Inhalt bleibt serverseitig gerendert – beim Bearbeiten lädt die
Seite weiterhin mit `?edit=…` und der Dialog geht von selbst auf. Das kostet
einen Seitenaufbau, hält aber Formularaufbau, Vorbelegung und Prüfung an
einer Stelle.

### Schnittstelle

REST unter `vereins-plugin/v1` für Termine, Ausnahmen, Zusagen,
Begleitpersonen, Mitbringliste, Helferaufgaben, Fotos und Anmeldung.
Nachgelagert für eine App gedacht; Zusage, Helferaufgaben und Mitbringliste
sind zusätzlich über signierte Terminlinks erreichbar.

`GET /me` gibt Auskunft über die angemeldete Person: Anzeigename, E-Mail,
`is_orgateam`, `is_admin` und die gespeicherten Begleitpersonen. Ohne diesen
Endpunkt kennt eine App nach dem Anmelden nur eine Zahl und könnte weder
begrüßen noch entscheiden, welche Knöpfe sie zeigen darf. Die Angaben steuern
ausschließlich die Oberfläche – jeder schreibende Endpunkt prüft die Rechte
selbst noch einmal.

Eine App gehört nicht zum Plugin. Die Schnittstelle ist so gebaut, dass
eine eigene App darauf aufsetzen kann; abgesichert ist diese Seite in
`tools/apitest.php`.

---

## Bewusste Grenzen

Damit niemand danach sucht:

- **Keine Beitragsverwaltung**, keine Buchhaltung, keine Lastschriften.
- **Keine Kommunikation** über einzelne Benachrichtigungen hinaus – kein
  Newsletter, keine internen Nachrichten.
- **Keine Anwesenheitskontrolle** vor Ort.
- **Kein eigenes Anmeldesystem.** Wer sich anmeldet, ist WordPress-Nutzer;
  das Plugin baut keine eigene Passwortverwaltung.
- **Ressourcen** sind nur eine Kennzeichnung am Termintyp, ohne Belegungsprüfung.
- **Keine App.** Die Schnittstelle ist vorbereitet, eine App gehört nicht dazu.
- **Kein Versand per SMS oder WhatsApp.** Einmalcodes und Benachrichtigungen gehen per E-Mail.
- **Kein Filter** der Terminliste nach Tags.

---

## Pflege dieser Datei

Bei jeder Änderung am Funktionsumfang:

1. Den betroffenen Abschnitt anpassen.
2. Bei neuen Zusammenhängen zwischen Bereichen die Erklärung ergänzen – die
   sind wertvoller als eine Aufzählung von Funktionen.
3. Die Versionsangabe oben nachziehen.
4. Kommt eine bewusste Nichtfunktion hinzu, unter „Bewusste Grenzen“ vermerken.

## Updates

Das Plugin meldet neue Versionen selbst: Unter *Plugins* erscheint
„Jetzt aktualisieren“, sobald es auf GitHub ein neueres Release gibt.
`Support\Updater` fragt dafür alle sechs Stunden
`api.github.com/repos/chrishelbing/vereins-plugin/releases/latest` ab und
nimmt das angehängte Paket `vereins-plugin.zip`. „Erneut prüfen“ unter
*Dashboard → Aktualisierungen* löscht die gemerkte Antwort vor der Prüfung
(Priorität 1, vor `wp_update_plugins()`) und fragt damit sofort.
„Details ansehen“ zeigt die Versionshinweise des Releases.

- Ein Release entsteht beim Push auf `main`, sobald `vereins-plugin.php`
  eine Version trägt, zu der es noch kein Release gibt
  (`.github/workflows/release.yml`, siehe CLAUDE.md). Ein Tag von Hand geht
  auch.
- Am Release hängen zwei identische Pakete: `vereins-plugin.zip` für den
  Updater – der Name ist in jeder installierten Fassung fest eingebaut und
  ändert sich nie – und `vereins-plugin-<Version>.zip` zum Herunterladen.
- Vorabversionen und Entwürfe auf GitHub werden nicht angeboten.
- Das Repository ist öffentlich; ein Token ist nicht nötig. Wäre es privat,
  bräuchte jede Website in der `wp-config.php`
  `define( 'VP_GITHUB_TOKEN', '…' );` (Fine-grained Token, nur dieses Repo,
  „Contents: Read“). Der Token ginge nur an `api.github.com`.
- Fassungen vor 1.12 kennen den Mechanismus noch nicht und müssen einmal von
  Hand aktualisiert werden.
