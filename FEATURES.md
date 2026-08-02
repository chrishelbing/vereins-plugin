# Vereins-Plugin – Funktionsumfang

> **Diese Datei ist bei jeder Änderung mitzuführen.**
> Sie ist die einzige Stelle, an der der Funktionsumfang zusammenhängend
> beschrieben ist. Wer eine Funktion ergänzt, ändert oder entfernt, pflegt
> sie hier nach – sonst veraltet sie innerhalb weniger Sitzungen und ist
> schlimmer als keine Dokumentation, weil man ihr dann noch glaubt.
>
> Stand: Version 1.7.1

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

- **Neun Termintypen** – Fest, Versammlung, Stammtisch, Arbeitseinsatz,
  Fahrt und Zeltlager, Training und Übung, Sitzung, Stand und Markt sowie
  die Ressource „Vereinsheim (Belegung)“. Jeder mit Farbe und passend
  gesetzten Schaltern: Ein Stammtisch hat keine Mitbringliste, eine
  Versammlung keine Fotos.
- **Neun Vorlagen** – vier für die Mitbringliste (Fest, Arbeitseinsatz,
  Fahrt, Adventsfeier), fünf für Helferaufgaben (Fest, Versammlung,
  Arbeitseinsatz, Fahrt, Marktstand). Die Mengen sind plausible Vorschläge
  für einen mittleren Verein, keine Vorschrift.
- Die **Verknüpfung** beider: Wo es passt, ist die Vorlage als Standard am
  Termintyp hinterlegt und wird beim Anlegen eines Termins vorbelegt.

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
| **Orgateam** | wie Mitglied, Rolle `vp_orgateam` | zusätzlich: Bedarf vorgeben, Titelbilder, Flyer | wer Termine betreut |

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

Termintypen können auch **Ressourcen** sein (Bus, Vereinsheim) – gedacht für
Belegungen.

### Termine anlegen und freigeben

Anlegen darf **jedes Mitglied** über `[vp_create_event]`, Administratoren
zusätzlich im Backend. **Freigeben dürfen nur Administratoren, nur im
Backend.** Bis dahin ist ein Termin nicht sichtbar. In der Übersicht steht,
wer ihn angelegt und wer ihn freigegeben hat.

### Serien und Ausnahmen

Termine können sich täglich, wöchentlich (mit Wochentagsauswahl), monatlich
oder jährlich wiederholen, begrenzt durch Enddatum oder Anzahl. Einzelne
Instanzen lassen sich abweichend ändern oder absagen.

Eine Termininstanz ist immer das Paar aus **Termin-ID und Instanzdatum** –
aufgelöst über `Events\InstanceLocator`.

### Mehrtägige Termine

Ein Camp von Freitag 16 Uhr bis Sonntag 17 Uhr wird als solches dargestellt:
„Mehrtägig · 3 Tage“ statt „Ganztägig“, auf der Kachel `11.–13. Sep`, dazu
die ausführliche Angabe mit Wochentagen. Endet ein Termin punktgenau um
Mitternacht, zählt der Vortag als letzter Tag – sonst wäre jede lange Feier
zweitägig.

---

## Zu- und Absagen

Mitglieder sagen je Termininstanz zu oder ab. Sichtbar sind **Zahlen** direkt
und **Namen** hinter einem Klick – bei fünfzig Zusagen wäre eine offene Liste
unbrauchbar.

### Begleitpersonen

Weil aus einer Familie mehrere kommen, lässt sich die Zusage um Begleit­
personen erweitern. Namen werden je Mitglied als **persönliche Vorschlagsliste**
gespeichert, damit man sie nicht jedes Mal tippt.

**Eigene Antwort und Begleitpersonen sind unabhängig.** Man kann selbst
absagen und die Kinder trotzdem anmelden. In der Auswertung erscheinen sie
deshalb bei den Zusagen, mit dem Vermerk, zu wem sie gehören.

---

## Mitbringliste und Helferaufgaben

Technisch dasselbe, per `kind` getrennt:

- **Mitbringliste** – das Orgateam gibt Bedarf vor („3× Kuchen“), Mitglieder
  übernehmen. Zusätzlich kann jeder einen freien Eintrag machen („Ich bringe
  Salat mit“).
- **Helferaufgaben** – legt nur das Orgateam an („Auf- und Abbau“, „Catering“,
  „Einlass“). Mitglieder übernehmen sie.

Auch **Begleitpersonen können Aufgaben übernehmen** – eine Familie teilt sich
mehrere Posten.

Die Helferzahl wird aus den übernommenen Aufgaben abgeleitet, nicht aus einem
Häkchen.

### Vorlagen

Wiederverwendbare Listen je Art, im Backend gepflegt und **aus einem
bestehenden Termin erzeugbar**. Je Termintyp eine Standardvorlage, beim
Anlegen überschreibbar. Neun Vorlagen sind bereits vorhanden – siehe
„Auslieferungszustand“.

---

## Fotos

Mitglieder laden Fotos je Termininstanz hoch – **Mehrfachauswahl**, auf dem
Handy direkt aus der Fotos-App, am Rechner per Drag & Drop. Beim Hochladen
werden Bilder verkleinert und von Standortdaten befreit.

Begrenzt wird über Einstellungen: Speicher je Mitglied und Termin, maximale
Dateigröße, längste Kante.

**Upload-Zeitraum**: standardmäßig 14 Tage nach Terminende, global einstellbar,
am einzelnen Termin übersteuerbar. Nach Ablauf werden die Teilnehmer
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

Passwort über `[vp_login]` und **Einmalcode** per E-Mail. Für die spätere App
gibt es zusätzlich Bearer-Token (`vp_sessions`).

Einmalcode-Anfragen (samt IP-Adresse) werden täglich um 3:15 Uhr per Cron
aufgeräumt (`Auth\OtpCleanup`), sobald sie abgelaufen sind – nicht erst bei
der nächsten Anmeldung.

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
| `[vp_create_event]` | Termin vorschlagen |
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

---

## Bewusste Grenzen

Damit niemand danach sucht:

- **Keine Beitragsverwaltung**, keine Buchhaltung, keine Lastschriften.
- **Keine Kommunikation** über einzelne Benachrichtigungen hinaus – kein
  Newsletter, keine internen Nachrichten.
- **Keine Anwesenheitskontrolle** vor Ort.
- **Kein eigenes Anmeldesystem.** Wer sich anmeldet, ist WordPress-Nutzer;
  das Plugin baut keine eigene Passwortverwaltung.
- **Ressourcen** sind als Termintyp angelegt, aber ohne Belegungsprüfung.

---

## Pflege dieser Datei

Bei jeder Änderung am Funktionsumfang:

1. Den betroffenen Abschnitt anpassen.
2. Bei neuen Zusammenhängen zwischen Bereichen die Erklärung ergänzen – die
   sind wertvoller als eine Aufzählung von Funktionen.
3. Die Versionsangabe oben nachziehen.
4. Kommt eine bewusste Nichtfunktion hinzu, unter „Bewusste Grenzen“ vermerken.
