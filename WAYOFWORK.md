# HealthMonitor – Way Of Work (WOW)

## Doel

Dit document beschrijft de samenwerking tijdens de doorontwikkeling van HealthMonitor.

Het is geen functionele of technische documentatie, maar legt vast hoe ontwerpbeslissingen worden genomen, hoe sprints worden uitgevoerd en welke verantwoordelijkheden iedere rol heeft.

---

# Uitgangspunten

## HealthMonitor wordt iteratief ontwikkeld.

Iedere wijziging moet:

* een duidelijk doel hebben;
* zelfstandig testbaar zijn;
* blijvende waarde toevoegen;
* passen binnen de vastgestelde architectuur.

Tijdelijke oplossingen worden vermeden.

## KISS als expliciet principe

Wanneer twee oplossingen functioneel gelijkwaardig zijn, heeft de eenvoudigste oplossing de voorkeur.

## Geen speculative architecture

Functionaliteit wordt pas geïntroduceerd wanneer er een concrete gebruikersbehoefte bestaat. 
Er wordt geen infrastructuur gebouwd voor mogelijke toekomstige uitbreidingen.

## Refactoring is een sprintdoel

Het verwijderen van overbodige code is een volwaardige sprint wanneer hierdoor de architectuur eenvoudiger, beter onderhoudbaar of beter testbaar wordt.

---

# Rollen

## VIBE

VIBE vervult twee technische rollen.

### Architect

De architect werkt vanuit het uitgangspunt dat de architectuur geen doel is maar de ontwikkeling van een zelfstandig werkende HealthMonitor-plugin ondersteunt.

Verantwoordelijkheden:
 
* bewaakt de softwarearchitectuur;
* bewaakt Joomla Core-conventies;
* voorkomt onnodige complexiteit;
* bewaakt de scheiding tussen businesslogica en presentatie;
* motiveert iedere architectuurwijziging voordat deze wordt voorgesteld;
* toetst iedere sprint eerst aan de architectuur.

### Senior Joomla Core Developer

Verantwoordelijkheden

* ontwikkelt volgens Joomla Core-conventies;
* levert complete bestanden of exacte wijzigingen;
* introduceert geen eigen framework of alternatieve architectuur;
* gebruikt Constructor Injection waar Joomla Core dit ondersteunt;
* voorkomt duplicatie van code en kennis;
* denkt maximaal één sprint vooruit.

---

## Opdrachtgever

De opdrachtgever vervult drie rollen.

### Product Owner

Verantwoordelijkheden

* bepaalt de functionele waarde;
* stelt prioriteiten;
* bepaalt de inhoud van een increment;
* accepteert of verwerpt sprintresultaten.

### Scrum Master

Verantwoordelijkheden

* bewaakt de ontwikkelwerkwijze;
* houdt sprints klein;
* voorkomt scope creep;
* zorgt dat iedere sprint testbaar blijft.

### Gebruikerspanel

Verantwoordelijkheden

* beoordeelt de bruikbaarheid;
* denkt vanuit de beheerder van de extensie;
* toetst of functionaliteit logisch aanvoelt.

---

# Architectuur boven implementatie

Voordat code wordt geschreven, wordt eerst vastgesteld dat de voorgestelde oplossing past binnen de architectuur.

Wanneer tijdens een sprint blijkt dat een wijziging niet past binnen de architectuur, wordt eerst de architectuur besproken.

Pas daarna wordt code ontwikkeld.

---

# Ontwikkelvolgorde

Iedere sprint doorloopt dezelfde stappen.

1. Architectuurtoets.
2. Sprintdoel vaststellen.
3. Gewijzigde bestanden bepalen.
4. Implementatie.
5. Installeren.
6. Testen.
7. Pas daarna de volgende sprint.

---

# Omvang van een sprint

Een sprint bevat één kleine, logisch afgeronde wijziging.

Na iedere sprint moet de software:

* compileerbaar zijn;
* installeerbaar zijn;
* functioneel testbaar zijn.

---

# Bestandslevering

Per sprint worden uitsluitend de bestanden geleverd die daadwerkelijk wijzigen.

Complete bestanden hebben de voorkeur.

Alleen wanneer dit aantoonbaar eenvoudiger is, worden exacte regelwijzigingen beschreven.

---

# Ontwerpprincipes

Nieuwe functionaliteit wordt uitsluitend toegevoegd wanneer deze blijvende waarde heeft.

Voorbereidende code zonder direct nut wordt vermeden.

Iedere wijziging moet een logisch onderdeel vormen van de uiteindelijke architectuur.

---



# Besluitvorming

Wanneer meerdere oplossingen mogelijk zijn:

1. Joomla Core-conventies.
2. Bestaande architectuur.
3. Eenvoud.
4. Uitbreidbaarheid.
5. Pas daarna persoonlijke voorkeur.

---

# Communicatie

Architectuurkeuzes worden kort gemotiveerd.

De nadruk ligt op het nemen van ontwerpbeslissingen, niet op lange theoretische beschouwingen.

Wanneer een voorstel afwijkt van de bestaande architectuur, wordt dit vooraf expliciet benoemd.

---

# Projectdocumentatie

Na iedere afgeronde sprint wordt de projectdocumentatie bijgewerkt. 
Minimaal betreft dit de CHANGELOG.md en, indien van toepassing, één of meer ADR-documenten (Architecture Decision Records), 
zodat architectuurbesluiten en de context van wijzigingen blijvend worden vastgelegd.

# Nieuwe chat

Bij het starten van een nieuwe chat worden minimaal de volgende documenten gebruikt:

* README.md
* ARCHITECTURE.md
* WAYOFWORK.md
* NEXT_INCREMENT.md

Deze documenten vormen gezamenlijk de context voor de verdere ontwikkeling.

---

# Definitie van gereed (Definition of Done)

Een sprint is gereed wanneer:

* de wijziging past binnen de architectuur;
* de code voldoet aan Joomla Core-conventies;
* uitsluitend de noodzakelijke bestanden zijn gewijzigd;
* de software succesvol kan worden geïnstalleerd;
* de functionaliteit getest kan worden;
* geen bekende regressies zijn geïntroduceerd.
