# Vereins-Plugin – Funktionsumfang

> **Diese Datei ist bei jeder Änderung mitzuführen.**
> Sie ist die einzige Stelle, an der der Funktionsumfang zusammenhängend
> beschrieben ist. Wer eine Funktion ergänzt, ändert oder entfernt, pflegt
> sie hier nach – sonst veraltet sie innerhalb weniger Sitzungen und ist
> schlimmer als keine Dokumentation, weil man ihr dann noch glaubt.
>
> Stand: Version 1.22.1

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

## Aufbau: Basis und Module (seit 1.16.0)

Das Plugin besteht aus einer **gemeinsamen Basis** und **Modulen**
(`Core\Modules`):

- **Basis – Mitglieder & Anmeldung.** Mitglieder und Gäste (Personen),
  Anmeldung mit Passwort oder Zugangscode, Wiedererkennung von Gästen per
  Cookie, Rollen, Mailversand, Datenschutz-Anbindung, Updates. Im Backend
  unter **„Verein“** (Mitglieder, Einstellungen).
- **Modul Termine.** Alles ab „Termine“ in dieser Datei. Backend: **„Termine“**
  (Termine, Termintypen, Vorlagen).
- **Modul Umfragen.** Siehe „Umfragen“. Backend: **„Umfragen“** (Alle Umfragen, Klassen-Vorlagen).

Module teilen nur die Basis und greifen nicht ineinander; `tools/check.php`
wacht darüber. Bis 1.15.8 hing alles unter einem Menüpunkt „Verein“; die
Seiten-Adressen (`?page=vp-events` usw.) sind gleich geblieben.

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

**Wiedererkennung über das Cookie** (`PublicLink::current_guest()`) prüft
deshalb über `Person::find()`. Bis 1.15.1 stand dort `get_userdata()`: Gäste
mit ihrer negativen ID galten nie als erkannt, und jede Zusage über einen
Terminlink endete bei „Bitte gib zuerst an, wer du bist“.

**Das Cookie weist nur Gäste aus, nie ein Mitgliedskonto.** Bis 1.15.5
genügte es, am Terminlink die E-Mail-Adresse eines Mitglieds einzutragen: Das
Cookie wurde ungeprüft auf dessen Konto ausgestellt – mit seinen Zusagen,
seinen gespeicherten Begleitpersonen und, bei Orgateam und Administratoren,
dem Recht, Mitbringliste und Helferaufgaben zu verwalten. Seit 1.15.6 gilt:
Gehört die Adresse zu einem Konto, wird nichts hinterlegt; es geht ein
**Zugangscode** an die Adresse, und erst seine Eingabe meldet das Mitglied an
(siehe „Teilnahme ohne Anmeldung“). Cookies, die frühere Versionen auf ein
Konto ausgestellt haben, gelten nicht mehr – betroffene Mitglieder werden
beim nächsten Besuch über den Link einmal nach dem Code gefragt.

**Name sichtbar und änderbar.** Im Dialog „Wer kommt mit?“ steht in der
eigenen Zeile, unter welchem Namen der Gast erkannt wurde („Ich – Anna
Berger“). Gäste können ihn dort ändern („Name ändern“, `POST /guest/name`,
nur für Gäste – Mitglieder ändern ihren Namen im Profil). „Nicht du?“
vergisst die Kennung auf diesem Gerät (`POST /guest/forget`, löscht nur das
Cookie); danach fragt der Termin wieder nach dem Namen – wichtig für
geteilte Geräte.

**Erst „Bestätigen“ speichert.** Ein Klick auf „Zusagen“ oder „Absagen“ an
der Kachel öffnet „Wer kommt mit?“ mit dieser Antwort vorgewählt; eigene
Antwort, Begleitpersonen und neu getippte Namen werden im Dialog nur
vorgemerkt. „Bestätigen“ speichert alles in einem Schritt (ein noch nicht
hinzugefügter Name im Eingabefeld kommt mit) und schließt den Dialog; bei
einem Fehler bleibt er mit der Meldung offen. Wer ihn schließt, ohne zu
bestätigen, hat nichts geändert. Ausnahmen, weil sie nicht die Zusage
betreffen: das × an einer gespeicherten Begleitperson entfernt sie sofort
aus der eigenen Liste, und ein zweiter Klick auf die aktive Antwort an der
Kachel zieht die Zusage weiterhin direkt zurück.

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

**Termine und Beiträge je Person** (seit 1.15.8, `Members\PersonActivity`).
In der Mitgliederliste und bei den offenen Personen steht unter jedem Namen
eine Kurzzeile – „3 Zusagen · 1 Absage · 2 Posten · zuletzt 17.11.2026“ bzw.
„noch keine Zusagen“. Der Bearbeiten-Dialog zeigt darunter die ganze
Aufstellung, neueste Instanz zuerst: Datum, Termin (mit Anzahl Fotos),
zugesagt/abgesagt, was die Person mitbringt (mit Menge, Notiz und ggf. für
welche Begleitperson), wobei sie hilft (übernommene Helferaufgaben oder
„packt mit an“ samt Aufgabe) und wer sie begleitet. Gelöschte Termine
bleiben als „(Termin gelöscht)“ stehen. Anlass: ein Mitglied, das dreimal
als Gast ohne E-Mail zugesagt hatte und dreimal aufgenommen wurde – welches
Konto „das richtige“ ist, zeigt erst, woran jedes hängt.

**Mitglieder ohne E-Mail** (seit 1.21.0). Wer ohne E-Mail aufgenommen wird,
bekommt eine Ersatzadresse auf `@invalid.local`
(`MemberService::PLACEHOLDER_DOMAIN`, `is_placeholder_email()`), weil
WordPress eine verlangt. Damit kann sich die Person nicht anmelden (der
Einmalcode käme nie an) und wird über Terminlinks nicht wiedererkannt – so
entstehen Doppelte. Deshalb:
- Die Mitgliederliste zeigt statt der Ersatzadresse „keine E-Mail – kann sich
  nicht anmelden“; im Bearbeiten-Dialog ist das Feld leer, mit Hinweis, und
  eine eingetragene Adresse ersetzt die Ersatzadresse.
- Beim **Aufnehmen einer offenen Person ohne E-Mail** steht ein Feld für die
  Adresse daneben (`accept_person( $id, $email )`); ohne Adresse fragt das
  Formular nach, ob wirklich ohne aufgenommen werden soll. Gehört die Adresse
  schon zu einem WordPress-Nutzer, wird wie bisher verknüpft.
- **„doppelt?“** steht unter Namen, die es bei Mitgliedern oder offenen
  Personen noch einmal gibt – gleich (ohne Groß-/Kleinschreibung) oder nur
  Vorname zu vollem Namen („Julia“/„Julia Melzer“).

**Zusammenführen** (seit 1.21.0, `Members\PersonMerge`). Im Bearbeiten-Dialog
eines Mitglieds: „Doppelten Eintrag übernehmen“ – Auswahl aus anderen
Mitgliedern (nicht Administratoren, nicht das eigene Konto) und offenen
Personen, ähnliche Namen oben, je mit Kontakt und Zahl der Zusagen. Das
Mitglied bleibt, der andere Eintrag geht darin auf:
- Alle Personentabellen samt Modulen (`person_tables()`, Filter
  `vp_person_tables`) und Fotos ziehen um – Zeile für Zeile: Gibt es beim
  behaltenen Mitglied schon dieselbe Zusage (eindeutiger Schlüssel), gilt
  dessen Antwort und die doppelte Zeile fällt weg.
- Gemerkte Begleitpersonen mit gleichem Namen werden eins; Zusagen und
  Mitbringposten der wegfallenden zeigen danach auf die behaltene.
- Ergänzt, nie überschrieben: echte E-Mail (wenn das behaltene Konto nur die
  Ersatzadresse hat), Telefon, Orgateam-Rolle.
- Der andere Eintrag verschwindet wie beim Löschen bzw. Ablehnen (Konto mit
  Beiträgen oder fremden Rollen bleibt ohne Vereinsrollen stehen).
Nicht rückgängig zu machen; das Formular fragt nach.

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

### Beschreibung mit Formatierung und Links

Die Beschreibung steht auf der Kachel unter Datum und Ort. Sie versteht eine
kleine Teilmenge von Markdown – ohne Editor und ohne Menü, nur mit Zeichen
im Text (`Support\RichText`):

| Eingabe | Ergebnis |
|---|---|
| `**fett**` | **fett** |
| `*kursiv*` oder `_kursiv_` | *kursiv* |
| Zeilen mit `- `, `* ` oder `• ` am Anfang | Aufzählung |
| Zeilen mit `1. ` oder `1) ` am Anfang | nummerierte Liste |
| `https://…` oder `www.…` | Link, angezeigt als gekürzte Adresse |
| `[Ausschreibung](https://…)` | Link mit dem Text „Ausschreibung“ |

Links öffnen in einem neuen Tab. Alles Übrige wird maskiert – HTML aus dem
Feld landet nie ungefiltert auf der Seite; Zeilenumbrüche bleiben. Ein
Sternchen oder Unterstrich mitten im Wort bleibt stehen: „Sportler*innen“
wird nicht kursiv, `datei_name` und Unterstriche in Adressen ebenso wenig.
Im Banner (selbst ein Link) erscheint nur der Text ohne Sternchen, auf dem
Flyer zusätzlich nackte Adressen zum Abtippen; Aufzählungen stehen dort
mit „• “.

**Erklärt wird es an zwei Stellen:** am **?** neben der Feldbeschriftung
„Beschreibung“ – im Backend, im Anlegen-Formular und im Bearbeiten-Dialog
(`EventForm::format_hint()`) – und unter *Verein → Einstellungen →
Beschreibung formatieren* als Tabelle mit Eingabe, Wirkung und dem
tatsächlichen Ergebnis. Beide lesen ihre Beispiele aus
`RichText::syntax()`; `tools/texttest.php` prüft, dass jedes davon wirkt.

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

**Auch die Dateien selbst sind geschützt** (seit 1.15.7, `Photos\ProtectedPhotos`).
Bis 1.15.6 zeigte nur die Oberfläche die Fotos ausschließlich Mitgliedern; die
Dateien lagen als gewöhnliche Medien in `uploads/`, waren mit der Adresse für
jeden abrufbar, und `/wp/v2/media` listete sie öffentlich samt „Quelle: <Name>“.
Jetzt gilt:

- Neue Fotos landen in `uploads/vereins-fotos/JJJJ/MM/`. Der Ordner bekommt
  eine `.htaccess` mit `Require all denied` (angelegt beim Update und vor jedem
  Upload; schlägt das fehl, wird der Upload abgelehnt). Gilt für Apache – unter
  nginx muss der Server den Ordner selbst sperren.
- Ausgeliefert wird über `admin-post.php?action=vp_photo&id=…&size=full|thumb`
  mit Prüfung auf ein freigeschaltetes Mitglied; ohne Anmeldung 403. Die
  Terminliste bekommt nur noch diese Adressen.
- Mediathek-Schnittstelle (Liste und Einzelabruf), Mediathek im Backend und
  Anhangseiten zeigen die Fotos nur Konten mit `upload_files` (Redakteure,
  Autoren, Administratoren) – für die Weiterverwendung mit Quellenangabe.
- **Weiterverwenden auf der Website:** Ein Foto aus dem geschützten Ordner
  lässt sich nicht öffentlich einbinden – Besucher bekämen 403. Dafür das Foto
  herunterladen (ZIP oder einzeln) und als normales Medium neu hochladen.
- **App mit Bearer-Token:** Die Auslieferung prüft die Anmeldung per Cookie.
  Eine App bräuchte dafür einen eigenen Weg; bisher gibt es keine.
- Fotos aus Versionen vor 1.15.7 bleiben, wo sie sind (werden aber über die
  neue Adresse ausgeliefert und aus der Mediathek-Schnittstelle gehalten);
  auf der JVL-Website gab es zu dem Zeitpunkt keine.
- Das **Titelbild** eines Termins (Banner, Flyer) bleibt bewusst öffentlich.

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
- **Mitglieder brauchen auch am Terminlink ihren Zugangscode.** Trägt jemand
  eine Adresse ein, die zu einem Mitgliedskonto gehört, wechselt der Dialog
  auf das Feld „Zugangscode“: Der Code geht per E-Mail an diese Adresse
  (derselbe Einmalcode wie bei `[vp_login]`, 10 Minuten gültig). Nach der
  Eingabe ist das Mitglied angemeldet und die Seite lädt neu; die begonnene
  Aktion ist dann noch einmal anzustoßen. Die Antwort der Schnittstelle
  (`/guest/identify` → `code_required`) nennt weder Namen noch Rolle. Ein
  Konto ohne freigeschalteten Mitgliederzugang bekommt keinen Code, sondern
  den Hinweis, das E-Mail-Feld leer zu lassen und als Gast zuzusagen.
- **Namen anderer bleiben verborgen** – auch in der Schnittstelle. Fremde
  Übernahmen erscheinen nur als „vergeben“.
- Offen sind Zusage, Helferaufgaben und Mitbringliste. **Nicht** Fotos.

Unbestätigte Kontakte sind in Frontend-Listen, Backend-Listen und CSV-Exporten
gekennzeichnet – in der Namensliste als orangefarbenes Ausrufezeichen mit dem
Hinweis „Kontakt noch nicht bestätigt“, das auch ihre Begleitpersonen
tragen. Das betrifft nur Gäste: Bestätigt ist ein Gast, sobald er den Link
in der Bestätigungsmail anklickt. Mitglieder gelten nie als unbestätigt –
sie hat ein Administrator angelegt oder aufgenommen (`Person::unconfirmed_map()`).

---

## Umfragen (Modul, seit 1.16.0)

Terminumfragen wie bei Doodle, Abstimmungen, Nominierungen, Ideensammlungen
(seit 1.18.0) und Wettkampfmeldungen mit Alters- und Gewichtsklassen (seit 1.17.0). Angelegt und
ausgewertet im Backend unter **„Umfragen“**, auf der Website über
`[vp_umfragen]` (alle offenen und beendeten, Entwürfe nie) bzw.
`[vp_umfragen id="…"]`. Anker `#umfrage-<id>` springt zu einer Umfrage. Die Umfragen-Liste im Backend zeigt den fertigen Shortcode je Umfrage zum Kopieren.

**Arten**
- **Terminumfrage** (`kind = date`): je Vorschlag *passt / vielleicht / passt
  nicht*; „vielleicht“ abschaltbar. Nicht beantwortete Vorschläge zählen als
  „passt nicht“. Vorschläge mit Beginn, optionalem Ende (Uhrzeit) und Zusatz.
  Auswertung im Backend als Tabelle Person × Vorschlag, Gäste gekennzeichnet.
- **Abstimmung / Nominierung** (`kind = choice`): eine oder (einstellbar)
  mehrere Antworten; bei einer Nominierung je Zeile eine Person.
- **Ideensammlung** (`kind = text`, seit 1.18.0): Vorschläge als Freitext,
  etwa Slogans, Namen oder Wünsche. Je Person bis zu *n* Vorschläge
  (`max_texts`, 1–10, Vorgabe 3), je Vorschlag bis 300 Zeichen; doppelte
  (ohne Rücksicht auf Groß-/Kleinschreibung) zählen einmal. Ändern ersetzt die
  eigenen Vorschläge, unveränderte behalten ihren Platz. Wann die anderen
  Vorschläge sichtbar sind, regelt „Vorschläge sichtbar“ (vier Stufen wie
  unten; „Niemand“ zeigt sie nur dem Orgateam, ohne Namen). Im
  Backend: Liste mit **Löschen je Vorschlag** (Moderation) und **„Als
  Abstimmung übernehmen“** – legt eine Abstimmung als Entwurf an, jeder
  verschiedene Vorschlag eine Antwort (Ergebnis nach dem Ende, „nur
  Mitglieder“ wie die Sammlung). Tabelle `vp_poll_texts`; Schnittstelle wie
  bei Stimmen (`POST /polls/{id}/vote` mit `texts: [...]`).
- **Wettkampfmeldung** (`kind = entry`, seit 1.17.0): siehe unten.

**Übersicht im Backend** (seit 1.20.4) wie bei Beiträgen: Knopf „Neue
Umfrage“, Filter Alle / Offen / Entwürfe / Beendet, je Zeile Titel mit
Linkzeile „Ergebnis bzw. Meldeliste/Vorschläge | Bearbeiten | Beenden bzw.
Veröffentlichen/Wieder öffnen | Einbinden | Löschen“, dazu Art mit Symbol,
Status, Teilnahmen, Ende. „Einbinden“ kopiert `[vp_umfragen id="…"]` in die
Zwischenablage. Ohne Umfragen zeigt die Seite gleich die vier Kacheln zum
Anfangen.

**Anlegen.** Nach „Neue Umfrage“ wählt man die Art aus vier Kacheln mit
Ziel statt Fachbegriff – „Termin finden“, „Abstimmen“, „Ideen sammeln“, „Zum
Wettkampf melden“ – je mit einem erklärenden Satz. Die Art steht danach fest – das Formular zeigt nur die Felder
dieser Art, und der Server übernimmt beim Speichern die gespeicherte Art,
nicht einen Formularwert (seit 1.19.0).

**Einstellungen je Umfrage:** Status (Entwurf / offen / beendet), optionales
Ende (danach keine Teilnahme mehr), **nur angemeldete Mitglieder** (ohne
Anmeldung kann sich jemand unter mehreren Namen eintragen) und
**Ergebnis sichtbar** – seit 1.19.0 eine Einstellung mit vier Stufen:

| Stufe | Wert | wer sieht was |
|---|---|---|
| Sofort sichtbar | `always` | alle, mit Namen, schon während es läuft |
| Nach Abschluss sichtbar | `after_close` | alle, mit Namen, nach dem Ende |
| Nur Orgateam | `orga` | nur das Orgateam, mit Namen |
| Niemand (geheim) | `nobody` | keine Namen für niemanden; das Orgateam sieht nur Zahlen bzw. Vorschläge ohne Namen |

`anonymous` ist keine eigene Einstellung mehr, sondern folgt aus `nobody`
(gespeichert wird die Person trotzdem, damit niemand doppelt abstimmt). Die
früheren Werte „nach eigener Abstimmung“ (`after_vote` → `always`) und
„geheim“ (→ `nobody`) stellt `PollSchema::migrate()` beim Update um.
Vorbelegung: Abstimmung „nach Abschluss“, Terminumfrage und Ideensammlung
„sofort“; Ideensammlung zusätzlich „nur Mitglieder“. Wettkampfmeldungen
haben keine Wahl – die Meldungen sieht nur das Orgateam.

**Übersicht auf der Website** (seit 1.19.0). `[vp_umfragen]` zeigt alle
Umfragen als zuklappbare Karten, getrennt in „Laufende Umfragen“ und
„Beendet“. Die Kopfzeile nennt Titel, Art, Stand (offen bis … / beendet bzw.
Wettkampftag) und – wenn man teilgenommen hat – „✓ abgestimmt“, „✓ gemeldet“ bzw. „✓ Vorschläge eingereicht“; aufgeklappt ist
höchstens eine. Die geöffnete Umfrage steht als `#umfrage-<id>` in der
Adresse, so lässt sich ein Link auf genau sie teilen. Läuft nur eine
Umfrage, ist sie aufgeklappt; mit `id="…"` oder bei nur einer Umfrage gibt es
keine Klappliste. Große Tippflächen fürs Handy. Rechts in der Kopfzeile
„Details anzeigen ▾“ bzw. aufgeklappt „Details ausblenden ▴“; beim Überfahren Rahmen in Akzentfarbe mit
leichtem Schatten, aufgeklappt hellgraue Kopfzeile mit Trennlinie – wie die
Gürtel-Kacheln der JVL-Seite (seit 1.20.0).

**Aussehen folgt dem Theme** (seit 1.20.0) – siehe „Farben und Theme“. Die Umfragen nehmen dieselben Farben wie der Rest des Plugins und
lassen sich zusätzlich einzeln über `--vp-poll-…` (z. B. `--vp-poll-accent`,
`--vp-poll-radius`) anpassen.

**Teilnahme ohne Anmeldung.** Wer nicht bekannt ist, nennt beim ersten
Abstimmen seinen Namen, E-Mail freiwillig (`POST /polls/identify`). Das ist
die Gast-Erkennung der Basis (`GuestService`, Cookie); gehört die E-Mail zu
einem Mitgliedskonto, geht ein Zugangscode dorthin (`Members\MemberCode`,
dieselbe Regel wie am Terminlink seit 1.15.6), nach der Eingabe ist die
Person angemeldet und die Seite lädt neu. Bremse: zehn Gast-Anmeldungen je
IP-Adresse und Stunde (gemeinsam mit den Terminen).

**Abstimmen und ändern.** Eine Abstimmung gilt als Ganzes; wer erneut
abstimmt, ersetzt seine Antwort. „Antwort zurückziehen“ löscht sie, solange
die Umfrage läuft.

**Bearbeiten.** Antwortmöglichkeiten behalten ihre Stimmen, wenn nur der Text
geändert wird; eine geleerte Zeile fällt samt ihren Stimmen weg.

**Technik.** Tabellen `vp_polls`, `vp_poll_options`, `vp_poll_votes`
(`Polls\PollSchema`). Schnittstelle `GET /polls` (`?id=`), `POST /polls/identify`,
`POST|DELETE /polls/{id}/vote`, `POST|DELETE /polls/{id}/entries`. Oberfläche `assets/js/polls.js` +
`assets/css/polls.css`, Texte aus `PollsShortcode::texts()`; Farben über
CSS-Variablen (`--vp-poll-accent` usw.) anpassbar.

### Wettkampfmeldung (seit 1.17.0)

Abfrage, wer bei einem Turnier startet – mit Alters- und Gewichtsklasse, so
wie der Ausrichter die Meldung haben will.

**Klassen-Vorlagen** (Backend „Umfragen → Klassen-Vorlagen“, `Polls\ClassTemplates`,
Option `vp_poll_class_templates`). Eine Klasse hat Kennung, Alter von–bis,
Geschlecht (m + w / nur m / nur w), Gewichtsklassen je Geschlecht oder
**gewichtsnah** (Einteilung beim Wiegen, keine Auswahl). Mitgeliefert:
- **BJV Brandenburg** – Wettkampfordnung Stand Januar 2024: U11 9–10, U13
  11–12, U15 12–14, U18 15–17, U21 17–20, Erwachsene ab 17, jeweils mit den
  Gewichtsklassen der WKO; dazu U9 7–8 gewichtsnah (keine BJV-Klasse, aber auf
  vielen Turnieren).
- **DJB** – Wettkampfordnung Stand Dezember 2025: U11 8–10 und U13 10–12
  gewichtsnah, U15, U18, U21, Erwachsene, Ü30 (Gewichte der Erwachsenen).
Vorlagen lassen sich bearbeiten, kopieren, entfernen; „Auslieferung
wiederherstellen“ holt die mitgelieferte Fassung zurück. Mannschaftsklassen
sind nicht enthalten.

**Anlegen** („+ Wettkampfmeldung“): **Wettkampftag (Pflicht;** sein Jahr bestimmt die
Jahrgänge), Vorlage übernehmen, dann je Turnier anpassen – nicht
ausgeschriebene Klassen abhaken, **Jahrgänge** und Gewichte so eintragen, wie
sie in der Ausschreibung stehen (leerer ältester Jahrgang = „und älter“).
Gespeichert wird eine Kopie an der Meldung (`vp_polls.classes`); spätere
Änderungen an der Vorlage wirken nicht zurück. Ändert sich der Wettkampftag
auf ein anderes Jahr, wandern die Jahrgänge im Formular mit. Meldeschluss =
„Ende“ der Umfrage. **Ohne Wettkampftag oder ohne angehakte Klasse bleibt die
Meldung Entwurf** – auch wenn „Offen“ gewählt war; das Formular öffnet sich
wieder mit einem Hinweis (seit 1.19.0).

**Altersklasse aus dem Jahrgang** (DJB-WKO 3.1.2): Alter = Wettkampfjahr −
Geburtsjahr. Passen mehrere Klassen (z. B. Jahrgang mit 12 Jahren: U13 und
U15 beim BJV), wählt der Melder; passt genau eine, wird sie gesetzt.

**Melden** (Website): Ein Melder trägt einen oder mehrere Starter ein (bis 12,
`EntryService::MAX_ROWS`) – je Starter Vorname, Nachname, Jahrgang,
Geschlecht, Altersklasse, Gewichtsklasse (Auswahl passend zu Klasse und
Geschlecht), Hinweis. Geschwister in verschiedenen Klassen gehen in einer
Meldung. **Ein Starter darf in mehreren Klassen stehen** (seit 1.20.2), etwa
U13 und U15 oder U18 und U21: „In weiterer Klasse melden“ erscheint,
sobald für Jahrgang und Geschlecht noch eine Klasse frei ist, und legt
darunter eine Zeile mit Name, Jahrgang und Geschlecht an (nächste freie
Klasse vorgewählt, Gewicht neu wählen). **In derselben Klasse nur einmal** –
gleich ist, wer Vor- und Nachname (ohne Groß-/Kleinschreibung und
Leerzeichen) und Jahrgang teilt (`EntryService::starter_key()`). Das gilt auch
über Melder hinweg: Hat jemand anderes den Starter in dieser Klasse schon
gemeldet, kommt eine Fehlermeldung mit dem Hinweis aufs Orgateam (ohne zu
sagen, wer gemeldet hat). Das Formular prüft vor dem Absenden mit. Die Meldung gilt als Ganzes, ändern und zurückziehen bis zum
Meldeschluss. Jeder Melder sieht nur seine eigenen Starter.
- **Gäste** brauchen für Rückfragen eine E-Mail-Adresse **oder** Telefonnummer
  (Abfrage im Namensdialog; serverseitig `contact_required`).
- **Mit Login** werden die Namen der Starter am Konto gemerkt
  (`Members\SavedNames`, dieselbe Liste wie die Begleitpersonen bei Terminen) und
  beim nächsten Mal als „Schnell eintragen“ angeboten – **nur der Name**, kein
  Jahrgang, kein Gewicht. Mit „×“ am Namen fliegt er aus der Liste
  (`DELETE /polls/saved-names`, seit 1.19.0) – auch bei den Terminen, wo
  dieselbe Liste schon immer ein „×“ hatte. Abgegebene Meldungen bleiben.
- **„Mich selbst melden“** (seit 1.22.0) – für Jugendliche und Erwachsene,
  die selbst starten: füllt eine freie Zeile mit dem eigenen Namen (Konto
  bzw. Gastname, `self_name` im Payload); Jahrgang, Geschlecht und Gewicht wie
  bei jedem Starter, am Konto wird nichts gespeichert. In Meldeliste und CSV
  steht als Melder „selbst“ (`is_self`: Startername = Name des Melders).
- **Der eigene Name kommt nie in die gemerkten Namen** (seit 1.22.0,
  `SavedNames::is_own_name()`) – weder über Wettkampfmeldungen noch über
  Termine (`CompanionModel::remember()`). Sonst stand man bei Terminen als
  seine eigene Begleitung da. Tippt man bei einem Termin den eigenen Namen
  als Begleitung ein, kommt der Hinweis, dass man über „Ich“ dabei ist.
  Das Update auf 1.22.0 räumt den Altbestand einmalig auf
  (`SavedNames::remove_own_names()`): Die Begleitung am Termin fällt weg,
  Mitbringposten für diese „Begleitung“ zählen als eigene.

**Meldeliste fürs Orgateam** (Backend, „Meldeliste“): nach Klasse und
Geschlecht gruppiert, nach Gewicht sortiert, je Starter „gemeldet von“ mit
E-Mail/Telefon; umschaltbar „nach Melder ordnen“. **CSV-Download** (Semikolon,
UTF-8 mit BOM für Excel): Nachname, Vorname, Jahrgang, Geschlecht,
Altersklasse, Gewichtsklasse, Verein (aus Einstellungen → Vereinsname),
Melder, Kontakt, Hinweis.

**Meldung beim Veranstalter** (seit 1.23.0; seit 1.23.3 steuern die Haken
alles). Oben ein Prozessband **Meldungen sammeln → Beim Veranstalter melden →
Gemeldet (wartet auf Bestätigung) → Bestätigt** (erledigt grün, aktuell blau),
darunter ein Kasten **„Nächster Schritt“**:
- **Vor dem Melden:** 1. Meldeliste holen – „BJV-Meldeliste (Excel)“
  (`Polls\BjvExport`, per `ZipArchive`) baut das offizielle BJV-„Meldeformular
  für alle Wettkämpfe“ nach: Blatt „Meldung“, Kopf (Veranstaltung, Datum,
  meldender Verein, Ansprechpartner = angemeldeter Orga, Kontakt-Mail,
  Kampfrichter Samstag/Sonntag – **die Kampfrichter bleiben leer**), Tabelle
  Nr. | Name | Vorname | Jahrgang | Geschlecht | AK | Gewichtsklasse, mindestens
  40 Zeilen; dazu die CSV. 2. Excel schicken oder in DokuMe eintragen.
  3. unten abhaken und speichern.
- **Nach dem Melden:** Knopf „Status auf ‚Bestätigt‘ setzen“ mit Liste, was
  passiert, Empfängern und Mail-Vorschau; noch nicht abgehakte Starter
  (Nachmeldungen) werden gezählt, Excel/CSV bleiben erreichbar.

**Die Haken sind die einzige Quelle** („Wer ist beim Veranstalter gemeldet?“):
Haken = gemeldet (`vp_poll_entries.submitted_at`). „Alle abhaken“ / „Alle
Haken entfernen“, Kopf-Häkchen je Klasse. Unter der Liste klebt eine Leiste,
die **beim Abhaken live** zeigt: wie viele abgehakt sind, **wer namentlich
eine Mail bekommt – mit den Vornamen seiner neu abgehakten Starter**, wer
mangels Adresse nicht (selbst Bescheid geben), wer schon informiert ist, und
ein Mail-Beispiel. Zwei Knöpfe:
- **„Als gemeldet speichern und Melder informieren“** (später „Speichern und
  Melder informieren“): speichert die Haken, setzt beim ersten Mal den Status
  „Gemeldet“ (`vp_polls.submitted_at`) und mailt „Meldung abgeschickt“ an jeden
  Melder mit abgehakten Startern, zu denen noch keine Mail rausging
  (`notified_at`, `PollModel::melder_to_notify()`). Die Mail nennt nur seine
  abgehakten Starter (`EntryMail::plan()`). Mail-Haken abgewählt: Status und
  Vermerk ohne Mail.
- **„Nur Haken speichern“** – Arbeitsstand, z. B. beim Eintragen in DokuMe:
  keine Mail, Status bleibt.
Nachmeldung = Starter ohne Haken nach „Gemeldet“ (gelb); abhaken und wieder
„Speichern und Melder informieren“ – Mail nur an deren Melder. Unveränderte
Starter behalten beim erneuten Speichern der Meldung Haken und Mail-Vermerk
(`PollModel::entry_key()`: Name, Jahrgang, Klasse, Gewicht).
**Bestätigt:** `confirmed_at`, Mail „Meldung bestätigt“ (abwählbar) an alle
Melder mit abgehakten Startern.
Melder ohne echte E-Mail (Platzhalter `@invalid.local` oder keine) bekommen
nichts; die Rückmeldung nennt sie zum selbst Bescheid geben
(`Polls\EntryMail`). Auf der Website sehen Melder „Die Meldung ging am … an
den Veranstalter“ bzw. „… bestätigt“, an ihren Startern „gemeldet“ und nach
dem Abschicken den Hinweis auf Nachmeldungen.

**Mail-Vorlagen** (Einstellungen → „Wettkampfmeldung: E-Mails an die Melder“):
Betreff und Text je für „abgeschickt“ und „bestätigt“, Platzhalter `{name}`,
`{wettkampf}`, `{datum}`, `{starter}` (Liste der eigenen Starter), `{verein}`,
`{absender}` (wer im Backend geklickt hat). Leer = mitgelieferte Vorlage.

**Meldung über DokuMe** (seit 1.23.0): Häkchen „Gemeldet wird über DokuMe –
Judopass abfragen“ an der Wettkampfmeldung (`vp_polls.dokume`). Dann fragt das
Formular je Starter „Digitaler Judopass: vorhanden / nicht vorhanden“ (Pflicht,
`vp_poll_entries.judopass`); Meldeliste und CSV zeigen die Spalte, Starter ohne
Pass sind rot gezählt – in DokuMe nur mit Pass meldbar.

**Löschen nach dem Wettkampf** (`Polls\EntryCleanup`, täglich 4:00): die
Starter einer Meldung werden „Meldungen löschen … Tage nach dem Wettkampf“
(Vorgabe 30, 0 = nie) entfernt, die Umfrage bleibt mit Vermerk stehen.
Geburtsjahr, Geschlecht und Gewicht stehen nur an der Meldung
(`vp_poll_entries`), nie am Profil.

**Personen löschen/übernehmen.** Stimmen und Meldungen hängen über den Filter
`vp_person_tables` (Stimmen, Vorschläge, Meldungen) an der Tabellenliste der Basis (`MemberService::person_tables()`):
Wird eine Person gelöscht, gehen sie mit; wird ein Gast aufgenommen, wandern
sie zum Mitglied.

**Technik.** Tabelle `vp_poll_entries`; Spalten `event_date`, `classes`,
`purge_days`, `purged_at`, `dokume`, `submitted_at`, `confirmed_at` an `vp_polls`;
`judopass`, `submitted_at`, `notified_at` an `vp_poll_entries`. Backend-Aktionen
`vp_poll_entries_xlsx`, `vp_poll_entries_mark`, `vp_poll_entries_state`. Schnittstelle
`POST|DELETE /polls/{id}/entries` (`starters: [...]`). Klassen-Editor im
Backend `assets/js/poll-classes.js` (Texte aus `ClassesAdmin::texts()`).

**Noch nicht enthalten (geplant):** aus einer Terminumfrage direkt einen Termin anlegen.
Nominierungen mit Personenauswahl aus der Mitgliederliste – bisher werden die
Namen als Antworten eingetragen.

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

### Anmeldung mit Einmalcode

`[vp_login]` kennt nur einen Weg: **Einmalcode per E-Mail**. Adresse
eingeben, sechsstelligen Code aus der Mail eintragen, fertig – ohne
Passwort. Für eine eigene App gibt es zusätzlich Bearer-Token
(`vp_sessions`).

**Passwörter nur über das normale WordPress-Login.** Bis 1.15 hatte
`[vp_login]` auch einen Passwort-Reiter. Der lief ins Leere: Mitglieder, die
das Plugin anlegt oder aufnimmt, bekommen ein zufälliges Passwort, das
niemand kennt, und zwei Wege verwirrten nur. Wer ein Passwort hat –
Administratoren, Redakteure –, nutzt `wp-login.php`; unter dem Formular
steht dafür ein unauffälliger Link „Mit Passwort anmelden (WordPress-Login)“.
Auch dort gilt: Nicht freigeschaltete Mitglieder kommen nicht hinein
(`MemberService::block_unapproved_login()`).

Einen Code gibt es nur für Adressen mit Mitgliedskonto. **Die Antwort
verrät aber nicht, ob es eines gibt** (seit 1.15.7): `request-otp` antwortet
für jede Adresse gleich mit 200 und „Wenn zu dieser Adresse ein
freigeschaltetes Mitgliedskonto gehört, ist ein Code unterwegs … Kommt nichts
an, nimm die Adresse, mit der du beim Verein angemeldet bist.“ Bis 1.15.6
gab es `unknown_account` (404) und `not_approved` (403) – hilfreich bei einer
falschen Adresse, aber damit ließ sich für jede beliebige Adresse erfragen, ob
sie Mitglied ist. Den Hinweis auf die richtige Adresse trägt jetzt die
Erfolgsmeldung. Noch früher kam bei fehlendem Konto ein Code an, beim
Bestätigen fand sich kein Konto, und die Meldung lautete „Ungültiger
Code“ – obwohl er stimmte. `verify_otp()` sucht deshalb auch zuerst das
Konto und prüft dann den Code, damit kein richtiger Code verbraucht wird.
Es gilt immer nur der zuletzt angeforderte Code; der Knopf „Code senden“
ist gesperrt, bis die Antwort da ist.

**Falsche Codes werden gezählt** (seit 1.15.6). Nach fünf falschen Eingaben
ist die Adresse 15 Minuten gesperrt (`OtpModel::MAX_FAILURES`,
`LOCK_MINUTES`): offene Codes verfallen, `verify-otp` und `request-otp`
antworten mit `too_many_attempts` (429), und auch ein neu angeforderter Code
hilft in dieser Zeit nicht. Vorher war nur das *Anfordern* begrenzt (fünf
Codes je Adresse in zehn Minuten) – das Raten nicht, und sechs Ziffern lassen
sich durchprobieren. Ein richtiger Code leert den Zähler. Die Kehrseite: Wer
die Adresse eines Mitglieds kennt, kann dessen Code-Anmeldung für jeweils
15 Minuten blockieren; das ist gegenüber einem erratbaren Zugang das
kleinere Übel. Die Bremse fürs Anfordern (`AuthService::may_request()`) gilt
gemeinsam für `[vp_login]` und für den Code am Terminlink.

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

## Farben und Theme (seit 1.20.0)

Das Plugin bringt keine eigene Farbwelt mit, sondern übernimmt die des
Themes. `assets/css/theme.css` (Handle `vp-theme`, angemeldet über
`Support\ThemeStyle`) setzt auf `:root` die Plugin-Farben `--vp-c-…`:

- **Grundfarben aus der Theme-Palette**, jeweils mit Ersatzwert:
  Akzent (`--wp--preset--color--primary` → Astra `--ast-global-color-0` →
  Kadence `--global-palette1` → Blau `#2563eb`), Überschriften/Text dunkel,
  Fließtext, Hintergrund. Auf der JVL-Seite (Astra) ergibt das Rot `#B3121B`.
- **Abgeleitet per `color-mix()`:** gedämpfter Text, Linien, helle Flächen,
  helle und dunkle Akzenttöne. Browser ohne `color-mix()` bekommen die
  früheren festen Werte.
- **Fest bleiben** die Bedeutungsfarben: Zusage grün, Absage/Löschen rot,
  Hinweise bernsteinfarben, Text auf farbigem Grund weiß.
- **Termintypen behalten ihre eigene Farbe.** Die Farbe, die das Orgateam je
  Termintyp einstellt, kommt weiterhin als Inline-Stil an Kachel
  (Typ-Beschriftung, Titelfläche ohne Bild), Banner (`--vp-accent`) und
  Flyer – Inline-Stile gewinnen immer gegen das Stylesheet. Nur ein Banner
  ohne gültige Typfarbe fällt auf den Theme-Akzent zurück.

Alle Stylesheets (`events.css`, `banner.css`, `form.css`, `polls.css`)
hängen von `vp-theme` ab; Anmeldung und Registrierung mit Inline-Stil
fordern es selbst an. Jede Verwendung trägt einen Ersatzwert
(`var(--vp-c-ink, #111827)`). Anpassen ohne Code im Customizer unter
„Zusätzliches CSS“, z. B. `:root { --vp-c-accent: #0a7d3b; }`.
**Knöpfe gegen Theme-Stile geschützt** (seit 1.20.3). Themes wie Astra
färben *jeden* `button` beim Überfahren und im Fokus um (Astra: schwarz,
weiße Schrift). Ein Schutzblock in `theme.css` gibt jedem Plugin-Knopf für
`:hover`/`:focus` zunächst seine Grundfarben; die eigenen Überfahren-Effekte
der Stylesheets stehen danach und wirken weiter. Häkchen und Auswahlknöpfe
tragen die Akzentfarbe (`accent-color`). Häkchen-Felder im Terminformular
(Tags, Wochentage, Ganztägig/Wiederkehrend) haben bewusst höhere
Spezifität, damit Seiten-CSS wie `.jvl-termine .vp-form label
{ display:block }` sie nicht untereinanderstellt.

`tools/check.php` meldet Variablen mit Selbstbezug, `--vp-c-…`-Namen, die in
`theme.css` fehlen, und Knöpfe (aus `el('button', …)`, `<button class>` und
`createElement('button')`), die im Schutzblock fehlen.

## Was wo zu finden ist

### Shortcodes

| Shortcode | Zweck |
|---|---|
| `[vp_events]` | Terminliste als Kacheln, mit allem daran; zeigt bei gültigem Schlüssel auch ohne Anmeldung einen einzelnen Termin |
| `[vp_create_event]` | Termin vorschlagen (bearbeitet wird über den Stift an der Kachel in `[vp_events]`) |
| `[vp_banner]` | Hervorgehobene Termine als Banner, z. B. auf der Startseite – öffentlich, verschwinden nach Terminende von selbst |
| `[vp_login]` | Anmeldung mit Einmalcode per E-Mail; Link zum WordPress-Login für Passwörter |
| `[vp_register]` | Selbstregistrierung |
| `[vp_umfragen]` | Modul Umfragen: offene und beendete Umfragen zum Mitmachen, auch ohne Anmeldung; `id="…"` für eine einzelne |

Eine stets aktuelle Übersicht steht im Backend unter *Verein → Einstellungen*.

### Backend

*Verein* (Basis) → Mitglieder (inkl. offener Personen und Rollen),
Einstellungen (inkl. Startdaten und Shortcode-Übersicht). Seit 1.23.2 mit
Seitenleiste: *Einstellen* – Allgemein (Vereinsname, Absender,
Benachrichtigung, Freischaltung), Aushänge und Flyer, Fotos,
Wettkampfmeldung (Mail-Vorlagen als Klappkästen), Datenschutz;
*Nachschlagen* – Shortcodes, Text formatieren. Es ist ein Bereich zu sehen,
der Anker in der Adresse wählt ihn (`#wettkampf`, auch nach dem Speichern;
`SettingsPage::sections()`), „Einstellungen speichern“ unten klebt und
speichert alle Bereiche. Ohne Script stehen alle Bereiche untereinander; auf
dem Handy wird die Seitenleiste zur waagerechten Reiterleiste.
*Termine* → Termine (inkl. Freigabe, Teilnehmerlisten, CSV-Export, Flyer),
Termintypen, Vorlagen.
*Umfragen* → Liste, Anlegen/Bearbeiten, Ergebnis.

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

**„Nach Updates suchen“** (seit 1.20.0) steht direkt in der Zeile des
Plugins unter *Plugins* (nur für Nutzer mit `update_plugins`). Der Link
verwirft die gemerkten Antworten (eigene und die von WordPress), fragt
sofort neu und kehrt zur Plugin-Übersicht zurück – mit Hinweis „Version x ist
verfügbar“, „Du hast die neueste Version“ oder „GitHub war nicht
erreichbar“. Ist eine neue Version da, steht in der Zeile wie gewohnt
„Jetzt aktualisieren“.

**Mindestversionen:** WordPress 6.0, PHP 8.2 (Kopfzeile „Requires at least“ /
„Requires PHP“, Konstanten `VP_MIN_WP` / `VP_MIN_PHP`). PHP 8.2, weil der Code
den Rückgabetyp `true` nutzt; WordPress kennt die Updates über `Update URI`
ab 5.8, 6.0 ist die getestete Untergrenze. WordPress verweigert die
Aktivierung bei zu altem PHP selbst; wird ein Server nachträglich
zurückgestuft, schaltet sich das Plugin ab und zeigt nur einen Hinweis,
statt die Website mit einem Fehler mitzureißen. Die Angaben gehen auch an den
Update-Dialog. `tools/check.php` prüft, dass Kopfzeile und Konstanten
übereinstimmen.

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
