# BACKLOG RESULT Sprint 6

### Projectstatus

HealthMonitor bevindt zich aan het einde van de functionele ontwikkelfase.

De architectuur is stabiel en uitbreidbaar. De belangrijkste domeinconcepten (`HealthResult`, `HealthAction`, `HealthCheckStatus`, `details`) zijn generiek genoeg gebleken om meerdere health checks te ondersteunen zonder architectuurwijzigingen.

De resterende werkzaamheden richten zich voornamelijk op afronding, gebruikerservaring en releasevoorbereiding.

---

## Nog uit te voeren

### UX

#### Plugin-tab uitbreiden

De pluginpagina bevat momenteel alleen de standaardbeschrijving.

Toevoegen:

- korte uitleg van HealthMonitor;
- overzicht van de beschikbare health checks;
- uitleg van de Health Score;
- uitleg van het Health Endpoint;
- betekenis van de UNKNOWN-status.

---

#### Basisinstellingen

Endpoint-URL is inmiddels aanwezig.

Nog toevoegen:

- korte uitleg waarvoor de URL bedoeld is;
- vermelden dat dezelfde URL gebruikt wordt door externe monitoring.

---

#### Statuspagina

Functioneel gereed.

Nog cosmetisch verbeteren:

- detailinformatie overzichtelijker tonen;
- SMTP-foutmeldingen duidelijk presenteren;
- Scheduler-details als lijst tonen;
- ontbrekende contentfragmenten overzichtelijk weergeven.

---

## Technische afronding

### Dependency Injection

Controleren of resterende directe instanties (`new HealthService`) kunnen worden vervangen door Joomla Dependency Injection waar dit de architectuur vereenvoudigt.

---

### Regressietests

Minimaal testen van:

- HealthService
- MailCheck
- SchedulerCheck
- ContentCheck
- EndpointUrlService
- AdminUrlService

---

### JSON-contract

Het JSON-endpoint als stabiel contract documenteren.

Vastleggen van:

Topniveau:

- status
- score
- threshold
- http_code
- checks

Per check:

- name
- status
- penalty
- message
- details

---

## Releasevoorbereiding

Uitvoeren van praktijktesten op:

- Joomla 5.4
- Joomla 6

Daarnaast controleren:

- upgradepad;
- lege configuraties;
- SMTP uitgeschakeld;
- Scheduler zonder taken;
- Content Check zonder zoekteksten.

Eenmalig de performance van de Content Check meten.

---

## Mogelijke uitbreidingen na versie 1.0

De huidige architectuur ondersteunt zonder aanpassingen nieuwe health checks.

Mogelijke uitbreidingen:

- Database Check
- PHP Version Check
- Disk Space Check
- SSL Certificate Check
- Joomla Update Check
- Maintenance Mode Check
- Debug Mode Check

Nieuwe checks kunnen worden toegevoegd zonder wijzigingen aan de bestaande architectuur.

---

## Conclusie

De nadruk verschuift van nieuwe functionaliteit naar afronding.

Belangrijkste resterende werkzaamheden:

- UX afronden;
- regressietests;
- documentatie afronden;
- releasevoorbereiding.

De architectuur wordt als stabiel beschouwd en vormt een goede basis voor versie 1.0.


# BACKLOG RESULT Sprint 5

## Technische kwaliteitscontrole

### Projectbrede standaardwaarden als constants

Controleer de volledige codebase op hardcoded standaardwaarden (magic values) en vervang deze waar passend door class constants.

Voorbeelden:

- standaard timeoutwaarden;
- booleaanse standaardinstellingen;
- overige configuratiewaarden.

Doel is een consistente stijl in de gehele codebase.

---

### Consistente codekwaliteit

Voer een laatste kwaliteitscontrole uit op de volledige codebase.

Controlepunten:

- constructor injection waar passend;
- PHP type hints;
- PHPDoc;
- coding standards;
- consistente naamgeving;
- verwijderen van eventuele tijdelijke ontwikkelcode.

---

### Eindcontrole architectuur

Voer een laatste architectuurreview uit.

Controlepunten:

- verantwoordelijkheden zijn helder gescheiden;
- geen duplicatie van businesslogica;
- geen speculative architecture;
- documentatie sluit volledig aan op de uiteindelijke implementatie.

---

## Documentatie

### Eindcontrole documentatie

Controleer alle documentatie op onderlinge consistentie.

Bestanden:

- README.md
- ARCHITECTURE.md
- WAYOFWORK.md
- CHANGELOG.md

Controleer tevens of alle voorbeelden, instellingen en beschrijvingen overeenkomen met de uiteindelijke implementatie.

---

### Richtlijn begrijpelijke gebruikerscommunicatie

Werk de tijdelijke notitie uit tot een definitieve richtlijn in `WAYOFWORK.md`.

Uitgangspunt:

> Configuratie is geschreven voor websitebeheerders, niet voor ontwikkelaars.

Labels en beschrijvingen leggen uit:

- wanneer een instelling aangepast moet worden;
- welk effect de wijziging heeft;

Technisch jargon wordt waar mogelijk vermeden.

BACKLOG RESULT Sprint 4
## Backend – Detailweergave van penalties

### Doel

Wanneer een Health Check de status **FAILED** heeft, moet de beheerder direct kunnen zien waardoor de penalty is veroorzaakt.

### Achtergrond

De detailinformatie is reeds beschikbaar binnen de `HealthResult` en wordt ook opgenomen in de JSON-uitvoer.

Momenteel toont de backend uitsluitend de samenvattende melding.

### Gewenste uitbreiding

* Uitklapbare of compacte detailweergave per Health Check.
* Hergebruik van de reeds aanwezige `details`.
* Geen extra businesslogica.
* Presentatielaag blijft verantwoordelijk voor de weergave.

---

## Health Endpoint – Uitbreidbare API

### Doel

Onderzoeken of het health-endpoint in de toekomst uitgebreid kan worden met aanvullende metadata zonder bestaande monitoringtools te breken.

### Mogelijke uitbreidingen

* uitvoertijd van de health checks;
* tijdstip van uitvoering;
* pluginversie;
* Joomla-versie;
* PHP-versie.

Dit betreft uitsluitend een architectuuronderzoek en heeft momenteel geen functionele prioriteit.

---

## Backend – Historische Health Status

### Doel

Onderzoeken of de ontwikkeling van de Health Score over een bepaalde periode zichtbaar gemaakt kan worden.

### Opmerking

HealthMonitor slaat momenteel bewust geen eigen gegevens op.

Een eventuele historie vraagt daarom eerst om een architectuurbesluit.

---

## Monitoring – Extra Health Checks

Nieuwe Health Checks worden uitsluitend toegevoegd wanneer hiervoor een concrete gebruikersbehoefte bestaat.

Mogelijke kandidaten:

* Database Check.
* Disk Space Check.
* PHP-versiecontrole.
* Joomla Update Check.
* Extensie Update Check.

Er wordt geen voorbereidende infrastructuur gebouwd zolang hiervoor geen concrete functionele behoefte bestaat.

---

## UX – Dashboardweergave

Onderzoeken of de huidige backend-statusweergave in de toekomst uitgebreid kan worden met een compact dashboard.

Mogelijke onderdelen:

* samenvattende statuskaart;
* totaal aantal mislukte controles;
* totale Health Score;
* visuele indicator van de storingsdrempel.

Dit betreft uitsluitend een gebruikersinterface-uitbreiding.

---

## Refactoring – Dependency Injection

Op termijn onderzoeken of alle services volledig via de Joomla Dependency Injection Container kunnen worden geregistreerd.

Doel:

* minder directe objectcreatie;
* betere testbaarheid;
* verdere aansluiting op Joomla Core-conventies.

Dit is een interne kwaliteitsverbetering en heeft geen functionele prioriteit.


BACKLOG RESULT Sprint 3