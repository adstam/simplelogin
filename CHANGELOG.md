# Changelog

## [1.1.0] - 2026-07-29

### Nieuwe functionaliteit
- **Goedkeuringsflow voor registraties***: Er is nu de mogelijkheid om in de registratieflow goedkeuring af te dwingen
- 				***Admin notificatiemail***: Beheerders ontvangen nu automatisch een e-mail bij nieuwe registraties (indien ingeschakeld in de plugin instellingen).
				***Afkeurmail met reden***: Beheerders kunnen nu bij het afkeuren van een registratie een reden invoeren. Deze reden wordt meegestuurd in de afkeurmail naar de gebruiker.
- **Onderscheid HTML/Tekst opmaak**: Keuze tussen HTML of tekst opmaak voor mailberichten, met de ingestelde editor voor HTML modus.
				***CID Embedding voor afbeeldingen***: Afbeeldingen in HTML mails worden nu embedded als CID (Content-ID) voor betere compatibiliteit en uiterlijk in e-mailclients.
				***Afbeeldingsvalidatie***: Controle op afbeeldingsgrootte (max. 500KB) en locatie (alleen `/media/` of `/images/` folders). Te grote of externe afbeeldingen worden niet embedded, maar als link getoond.
				***Image Error Logging***: Fouten bij afbeeldingsverwerking worden nu gelogd in `#__simple_login_log` met types `image_not_found` en `image_too_large`.
				***Robuuste Placeholder Buttons***: Knoppen voor het invoegen van placeholders (`#name`, `#link`, `#expiry`, `#reason`, `#email`, `#sitename`) in mail templates, **werkend in alle editors** (TinyMCE, JCE, CodeMirror) en beide modi (text/HTML).
				***Template Validatie Popup***: Waarschuwing bij opslaan van mail templates met externe of te grote afbeeldingen.
				***Absolute Image URLs***: Ondersteuning voor absolute URLs in afbeeldingspaden via `getAbsoluteImageUrl()`.

### Technische verbeteringen
- **Code Ontdubbeling**: Maillogica van de verschillende flows maakt nu gebruik van dezelfde code. Gebruik van `LogTrait::log()` voor consistent logging in plaats van duplicatie in `MailService`.
- **MailService Refactor**: Toegevoegde methodes: `getAbsoluteImageUrl()`, `getLastImageErrors()`, `validateImageForEmbedding()`.
- **Log Filter**: `ImageError` type toegevoegd aan `ReportHelper::getLogTypes()` voor filtering in de logpagina.
- **Editor Integraction**: Buttons werken nu met **alle Joomla editors** via de officiële `Joomla.editors.instances[].replaceSelection()` API.
- **Tabstructuur**: Configuratiepagina overzichtelijker gemaakt met logische tabindeling.
- **Placeholders**: Standaard strings consistent gemaakt in alle mail templates.
- **Code Kwaliteit**: Debug code uit eerdere versies verwijderd, inline JavaScript naar externe bestanden gebracht, hardcoded strings opgeschoond.

### Bugfixes
- Gebruikers ontvingen geen invite-mail na registratie door een te vroege opruiming van de sessie-marker. Opgelost door de marker alleen in `onUserAfterSave()` op te ruimen.
- Regeleinden in mail templates werden niet correct getoond in de uiteindelijke e-mail. Opgelost door `nl2br()` toe te passen in `buildMailBody()`.
- Overlay toont nu alleen neutrale melding na link-opvraag (geen formulier meer).
- Melding "Als dit adres bekend is, is er een e-mail verzonden" heeft nu de bestaande 'info' stijl.


## [1.0.5] - 2026-07-05 (bugfixrelease)
### Fixed
- Overlay toont nu alleen neutrale melding na link-opvraag (geen formulier meer)
- Melding "Als dit adres bekend is, is er een e-mail verzonden" heeft gebruitk bestaande 'info' stijl

### Technical Details
- `LoginFlowTrait.php`: `showLoginForm = false` en `statusType = 'info'` na succesvolle POST

## [1.0.0] - 2026-07-05 (Eerste release)
### Bevat
- Eerste stabiele release
- Wachtwoordloos inloggen via e-maillinks
- Wachtwoordloze gebruikersregistratie met e-mailactivering
- Uitgebreide beveiligingsfuncties (snelheidsbeperking, afkoelperiode, scannerdetectie)
- Volledige logging en monitoring
- Meertalige ondersteuning (Engels, Nederlands)
- Aangepaste formuliervelden (bodybuttons, contactcategory, exportlog, hashpasswords, logreport, throttlereport)