# Vereins-Plugin – Hinweise für die Arbeit an diesem Plugin

## Zuerst lesen: FEATURES.md

`FEATURES.md` beschreibt den Funktionsumfang zusammenhängend – Personenstufen,
Termine, Zusagen, Mitbringliste, Fotos, Flyer, Rollen, dazu die bewussten
Grenzen. Vor einer Änderung dort nachsehen, was es schon gibt und warum es so
gebaut ist.

**Die Datei ist bei jeder Änderung am Funktionsumfang mitzuführen.** Eine
neue, geänderte oder entfernte Funktion gehört dort hinein, bevor die Arbeit
als erledigt gilt. Eine veraltete Übersicht ist schlimmer als keine, weil man
ihr noch glaubt.

## Vor jedem Abschluss: Prüfungen laufen lassen

```
php tools/pruefen.php
```

Das ist verbindlich, nicht optional. Der Befehl startet zehn Prüfungen und
liefert Rückgabewert 1, sobald eine anschlägt. Läuft `exec()` in der
Umgebung nicht, sind die zehn Dateien einzeln aufzurufen.

| Datei | prüft |
|---|---|
| `tools/check.php` | Syntax, Autoloader-Pfade, Symbole, Shortcode-Registry, Frontend-Skript, Einstellungen, Versionsnummer, „keine WordPress-Profile für Unbestätigte" |
| `tools/zugriffstest.php` | Wer sich anmelden darf, wer in den Mitgliederbereich kommt, wer ins Backend – dazu Aussperrschutz und die Kopplung von Status und Vereinsrolle |
| `tools/personentest.php` | Der Weg einer Person: Zusage über Terminlink, Wiedererkennung, Bestätigung, Aufnahme, Ablehnung, Übernahme von Altbeständen |
| `tools/apitest.php` | Die Schnittstelle aus Sicht der App: Lebensdauer, Verlängerung und Widerruf von Sitzungen, die Form von `GET /me`, die Erkennung nicht angeschlossener Versandwege |
| `tools/startdatentest.php` | Startdaten: einmalig bei der Aktivierung, Nachlegen auf Anforderung ohne Dubletten, Wirksamkeit der Vorlagen am Termin |
| `tools/qrtest.php` | Der selbstgebaute QR-Encoder gegen hinterlegte Sollwerte |
| `tools/bannertest.php` | Terminbanner: Sichtbarkeitsfenster, Reihenfolge, nur die nächste Serieninstanz, nur freigegebene Termine |
| `tools/tagtest.php` | Infotermine (Termintyp ohne Zusagen) und Tags: Vorgabe am Typ, Auswahl am Termin, Speichern und Leeren |
| `tools/updatertest.php` | Updates aus GitHub-Releases: Version aus dem Tag, Paket am Release, Vorabversionen und Entwürfe ausgenommen |
| `tools/texttest.php` | Beschreibung mit Links: nackte Adressen, `[Text](Adresse)`, Maskierung, nur http(s), Textfassung für Banner und Flyer |

Neue Fachlogik gehört mit einem Fall in die passende Testdatei. Die Tests
laufen gegen `tools/lib/wp-stub.php`, einen kleinen WordPress-Ersatz im
Speicher – ohne Datenbank, ohne Installation.

**Wenn ein Test etwas Überraschendes meldet, zuerst den Ersatz verdächtigen.**
Er ist absichtlich naiv, und drei Fehlalarme kamen schon von ihm: Einmal
wurde `WHERE m.status = …` wegen des Tabellenkürzels stillschweigend
ignoriert, einmal fraß die Kürzelbereinigung das `example.` in E-Mail-Adressen,
einmal fiel `expires_at > …` durch – der Ersatz kannte nur Gleichheit, `IN`
und `IS NULL`, und jede abgelaufene Zeile galt damit als gültig.

Zwei Eigenheiten, die man wissen muss, bevor man dem Ersatz glaubt:

- **`ORDER BY` wird ignoriert.** Zeilen kommen in Einfügereihenfolge zurück.
  Ein Test, der eine Sortierung prüfen will, prüft sie nicht.
- **Spalten-DEFAULTs kennt er nicht.** Was ein `INSERT` weglässt, fehlt in der
  Zeile schlicht, und `WHERE revoked = 0` trifft sie dann nie. Wer eine
  Tabelle prüft, bei der das zählt, trägt die Vorgaben in
  `$wpdb->defaults['tabelle']` ein – siehe `tools/apitest.php`.

## Was die Tests nicht abdecken

Ehrlich benannt, damit niemand sich in Sicherheit wiegt:

- **Alles im Browser.** Die Anordnung auf der Flyer-Leinwand, die Reihenfolge
  beim Laden des Medienmanagers, das Verhalten der Overlays. Das wurde
  einmalig mit eigenen Werkzeugen geprüft, ist aber nicht dauerhaft abgesichert.
  Für das Skript der Terminliste in `assets/js/events/` prüft `check.php`
  immerhin die drei Nähte, an denen es lautlos auseinanderlaufen kann: Texte
  gegen `script_texts()`, Dateien gegen die Liste `SCRIPTS`, und jeden Aufruf
  über eine Modulgrenze gegen den Eintrag in `window.vpEvents`. Ob ein Knopf
  tatsächlich das Richtige tut, sagt auch das nicht.
- **Echte Datenbank.** Die Migrationen in `Database\Schema` – Umbenennen von
  Spalten, Wechsel auf vorzeichenbehaftete Zahlen – laufen im Test nicht mit.
- **Der QR-Nachweis selbst.** Der Test hält das Ergebnis fest, prüft aber
  nicht erneut gegen einen Decoder.

## Verabredungen im Code

- **Personen-IDs**: positiv = `wp_users`-ID eines aufgenommenen Mitglieds,
  negativ = Eintrag in `vp_people` (Gast oder wartende Registrierung).
  Aufgelöst wird ausschließlich über `Members\Person`.
- **Ein WordPress-Profil entsteht nur bei der Aufnahme.** `wp_insert_user()`
  darf nur in `Members\MemberService` vorkommen; `tools/check.php` wacht darüber.
- **Vereinsrollen werden zusätzlich vergeben**, nie ersetzend. Wer eine Rolle
  der Website trägt – Redakteur, Autor, Abonnent –, behält sie samt Backend.
  Das Plugin nimmt nie etwas weg, was die Seite schon vergeben hatte.
- **Status und Vereinsrolle laufen synchron**: Ohne Vereinsrolle ist niemand
  aktives Mitglied, und Deaktivieren nimmt die Vereinsrolle mit.
- **Rollenkennungen** stehen als Konstanten in `Core\Activator`; frühere Namen
  stehen in `ROLE_HISTORY` und werden beim Update übernommen.
- **Versionsnummer erhöhen**, sobald sich das Schema oder eine Migration
  ändert – sonst läuft `maybe_upgrade()` nicht an. Kopfzeile und `VP_VERSION`
  müssen übereinstimmen, `tools/check.php` prüft das.

## Neue Version veröffentlichen

Websites holen Updates aus den GitHub-Releases (`Support\Updater`, Header
`Update URI`). Ein Release entsteht beim Push auf `main` von selbst:

1. Version in `vereins-plugin.php` erhöhen – Kopfzeile **und** `VP_VERSION`.
2. Committen und auf `main` pushen. **Kein Tag von Hand** – Claude-Sitzungen
   dürfen keine Tags pushen, deshalb setzt GitHub ihn selbst.
3. `.github/workflows/release.yml` sieht eine Version ohne Release, lässt die
   Prüfungen laufen, baut das Paket und legt Release und Tag `v1.2.3` an.
   Pushes ohne neue Version lösen nichts aus.

Am Release hängen zwei identische Pakete: `vereins-plugin.zip` für den
Updater und `vereins-plugin-1.2.3.zip` zum Herunterladen. **Der Name
`vereins-plugin.zip` darf sich nie ändern** – er ist in jeder installierten
Version fest eingebaut (`Support\Updater::ASSET`); ein neuer Name ließe alle
bestehenden Websites ohne Update zurück.

Ein Tag von Hand (`v1.2.3` oder `1.2.3`) funktioniert weiterhin, muss aber
zur Version passen. Lokale Kopien holen den Tag danach mit `git pull`.

Ohne angehängtes Paket bietet keine Website das Update an.

## Sprache

Code-Kommentare, Oberfläche und Commit-Nachrichten auf Deutsch.
