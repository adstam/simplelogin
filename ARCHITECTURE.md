# SimpleLogin Plugin — Architecture

**Component:** `plg_system_simplelogin`
**Doel van dit document:** richtinggevend. Beschrijft de architectuurprincipes, verantwoordelijkheden en ontwerpkeuzes van de plugin — niet de actuele implementatie in detail. Voor de bestandsinventaris, klassenstructuur, databaseschema en het ADR-archief, zie [ARCHITECTURE_APPENDIX.md](ARCHITECTURE_APPENDIX.md).

---

## Inhoudsopgave

1. [Doel van de plugin](#1)
2. [Architectuurpatroon](#2)
3. [Ontwerpprincipes](#3)
4. [Verantwoordelijkheidslagen](#4)
5. [Registratie- en goedkeuringsflow (conceptueel)](#5)
6. [Beveiligingsprincipes](#6)
7. [Configuratie](#7)
8. [Afhankelijkheden](#8)
9. [Grenzen van de architectuur](#9)

---

## Doel van de plugin<a id="1"></a>

SimpleLogin is een Joomla system-plugin voor **wachtwoordloze authenticatie** via e-maillinks. Gebruikers loggen in door een beveiligde link per e-mail aan te vragen; nieuwe gebruikers kunnen zich op dezelfde manier wachtwoordloos registreren, optioneel achter handmatige admingoedkeuring.

De plugin is bedoeld als **vervanging of aanvulling** op Joomla's ingebouwde wachtwoord-login, niet als los-staand authenticatiesysteem naast Joomla core.

---

## Architectuurpatroon<a id="2"></a>

De plugin volgt een **modulaire, trait-gebaseerde architectuur** met een dunne service-laag voor cross-cutting concerns (mail):

- De hoofdklasse (`Extension\Simplelogin`, uitbreiding van `CMSPlugin`) bevat zelf geen businesslogica. Zij bestaat uit event-subscripties en stelt haar gedrag samen uit traits.
- Iedere trait vertegenwoordigt precies één verantwoordelijkheidsdomein (login, registratie, beveiliging, logging, hulpfuncties, AJAX-dispatch). Zie [Verantwoordelijkheidslagen](#verantwoordelijkheidslagen).
- Cross-cutting functionaliteit die vervangbaar moet zijn — op dit moment alleen mailverzending — wordt niet in een trait ondergebracht, maar als losse service via Dependency Injection (`services/provider.php`) geïnjecteerd achter een interface.

**Waarom trait-gebaseerd in plaats van los-staande servicelagen per domein:** een Joomla system-plugin heeft één centraal event-aanknopingspunt (`getSubscribedEvents()`) en werkt in de praktijk als één samenhangende request-handler. Traits houden de code per domein gescheiden en toetsbaar zonder de overhead van een eigen DI-graaf per domein, die voor een plugin van deze omvang speculatieve complexiteit zou zijn (zie [Geen speculative architecture](WAYOFWORK.md#geen-speculative-architecture)). Zodra een verantwoordelijkheid vervangbaar of mockbaar moet zijn — zoals mailverzending — verhuist die wél naar een geïnjecteerde service. Zie ADR-0001 in de appendix voor de volledige afweging.

---

## Ontwerpprincipes<a id="3"></a>

1. **Separation of Concerns** — iedere trait/service bedient één domein.
2. **Single Responsibility** — iedere methode heeft één duidelijk doel.
3. **Security first** — alle input wordt gevalideerd/escaped, rate limiting is verplicht, tokens worden nooit in leesbare vorm bewaard.
4. **Fail-safe defaults** — een onbekende log-status of -type valt terug op een veilig, niet-throttled gedrag in plaats van een fout te werpen.
5. **Extensibility zonder speculatie** — nieuwe flows (zoals de goedkeuringsflow) worden toegevoegd als nieuwe traitmethoden, zonder de centrale routing aan te passen; er wordt geen infrastructuur vooruit gebouwd voor flows die nog niet bestaan.

Deze principes zijn een concretisering van de uitgangspunten in [WAYOFWORK.md](WAYOFWORK.md) (KISS, geen speculative architecture, Joomla Core First) voor deze specifieke plugin.

---

## Verantwoordelijkheidslagen<a id="4"></a>

| Laag | Verantwoordelijkheid |
|---|---|
| **Extension** | Event-subscripties, samenstelling van traits. Geen businesslogica. |
| **LoginFlowTrait** | Centrale routing, login- en tokenflow, versturen van loginlinks. |
| **RegisterFlowTrait** | Registratieformulier, invite-tokenactivatie, goedkeuringsflow. |
| **SecurityTrait** | Rate limiting, cooldown, scanner-/bot-detectie, wachtwoordafdwinging. |
| **LogTrait** | Centrale logging, status→type-mapping, IP/UA/e-mail hashing. |
| **UtilityTrait** | Tokenbeheer, cleanup, validatie, PRG-redirects. |
| **AjaxTrait** | `com_ajax`-dispatcher voor admin-only acties. |
| **MailService** (DI) | Mailverzending, placeholdersubstitutie, CID-embedding van afbeeldingen. |
| **Custom Fields** | Presentatie van admin-rapportages (logs, throttle, goedkeuringen) binnen het Joomla-configuratieformulier. |

Businesslogica en presentatie zijn strikt gescheiden: traits en de service bevatten geen HTML, templates (`src/tmpl/*.php`, `layouts/simplelogin/*.php`) bevatten geen businesslogica.

Voor de concrete klassen, bestanden en hun onderlinge afhankelijkheden: zie [ARCHITECTURE_APPENDIX.md](ARCHITECTURE_APPENDIX.md#klassenstructuur) en [ARCHITECTURE_APPENDIX.md](ARCHITECTURE_APPENDIX.md#bestandsstructuur).

---

## Registratie- en goedkeuringsflow (conceptueel)<a id="5"></a>

```
Registratie → invite-mail (selector/validator link)
   → gebruiker activeert link
        → goedkeuring vereist?  Nee → account direct bruikbaar
                                Ja  → account in wachtrij ("pending approval")
                                        → admin keurt goed  → account bruikbaar
                                        → admin keurt af    → account + gegevens verwijderd
```

Het principe achter deze flow: de wachtrij voor goedkeuring identificeert accounts uitsluitend via hun herkomst uit déze plugin (een gekoppelde invite-tokenrij), niet via de generieke Joomla "geblokkeerd"-status. Dat voorkomt dat een goedkeurings- of afkeuractie per ongeluk een account raakt dat om een andere reden geblokkeerd is. Deze scheiding tussen "geblokkeerd door SimpleLogin" en "geblokkeerd door iets anders" is een architectuurprincipe, geen implementatiedetail — een toekomstige wijziging aan de goedkeuringsflow moet dit onderscheid intact laten.

Voor de exacte statemachine, tokentypen en admin-AJAX-acties: zie de appendix.

---

## Beveiligingsprincipes<a id="6"></a>

- **Login voltrekt zich uitsluitend op POST.** Een kale GET op een tokenlink logt nooit in en activeert nooit een account — dit ontkracht link-preview bots en mailscanners die alleen GET-requests doen. Dit is een vast architectuurprincipe, niet een implementatiedetail dat per sprint heroverwogen wordt.
- **Tokens zijn selector/validator-paren.** De selector is publiek en dient als lookup-sleutel; de validator wordt nooit in leesbare vorm opgeslagen, alleen gehasht.
- **Rate limiting en cooldown zijn verplicht**, zowel per IP als per gebruiker, onafhankelijk van elkaar.
- **Iedere state-changing actie vereist CSRF-verificatie**, zowel frontend-POSTs als admin-AJAX-acties.
- **Iedere admin-AJAX-actie vereist bovendien autorisatie** (`core.manage`) — nooit alleen CSRF.
- **Mail-afbeeldingen worden nooit server-side van externe bronnen opgehaald.** Alleen lokale media (`/media/`, `/images/`) mogen worden ingebed; dit sluit SSRF via mailtemplates principieel uit.
- **Alle dynamische output wordt geëscaped** op het punt van renderen, niet op het punt van opslaan.

Deze principes zijn getoetst en (waar nodig) hersteld tijdens de beveiligingsreview voorafgaand aan 1.1.0. De concrete bevindingen van die review — inclusief wat er mis was en hoe dat is opgelost — staan in de appendix, niet hier: dat is implementatiegeschiedenis, geen doorlopend architectuurprincipe.

---

## Configuratie<a id="7"></a>

Configuratie is geschreven voor websitebeheerders, niet voor ontwikkelaars (zie [WAYOFWORK.md](WAYOFWORK.md#ontwerpprincipes)). Voor de volledige lijst parameters, hun defaultwaarden en hun betekenis: zie **[DEFAULT.md](DEFAULT.md)**. Dit document dupliceert die referentie niet.

---

## Afhankelijkheden<a id="8"></a>

- Joomla core (geen versie-specifieke aannames buiten wat in de appendix als "actueel geteste versie" staat).
- PHP, actuele ondersteunde versie.
- Geen third-party Composer-packages naast wat Joomla core al aanbiedt — dit is een bewuste keuze (zie ADR-0002 in de appendix), niet een toevallige huidige staat.

De exacte geteste versies en eventuele afwijkingen staan in de appendix, omdat dat kan wijzigen zonder dat het architectuurprincipe ("geen extra dependencies tenzij noodzakelijk") wijzigt.

---

## Grenzen van de architectuur<a id="9"></a>
Wat bewust **buiten** de scope van deze plugin blijft, ongeacht toekomstige sprints:

- Geen eigen framework of alternatieve architectuur naast Joomla Core.
- Geen infrastructuur voor authenticatiemethoden die niet concreet gevraagd zijn (bijv. WebAuthn/passkeys) totdat daar een reële gebruikersbehoefte voor bestaat.
- Geen server-side ophalen van externe content (afbeeldingen, links) ten behoeve van mailtemplates — dit principe weegt zwaarder dan het gemak dat het zou bieden.

Bekende, tijdelijk geaccepteerde beperkingen (die wél kunnen wijzigen naarmate er een oplossing gevonden wordt) staan niet hier, maar in [ARCHITECTURE_APPENDIX.md](ARCHITECTURE_APPENDIX.md#bekende-beperkingen) — dat zijn implementatiestatus, geen architectuurgrenzen.

---

## Gerelateerde documenten

- **ARCHITECTURE_APPENDIX.md** — implementatie-inventaris, klassenstructuur, databaseschema, beveiligingsreview, bekende beperkingen, ADR-archief.
- **README.md** — installatie en gebruik voor eindgebruikers/beheerders.
- **CHANGELOG.md** - Overzicht van de wijzigingsgeschiedenis
