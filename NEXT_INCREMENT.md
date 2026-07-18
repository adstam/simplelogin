# Simplelogin Plugin - Next Increment (1.0.7)
**Doel:** Email templates voor de complete flow implementeren.

---

## 📌 **Context**
De **core flow** werkt nu correct:
- ✅ Registratie met admin approval
- ✅ Goedkeuren/afkeuren door beheerder
- ✅ Invite link activatie (inclusief edge cases)
- ✅ Database logging

**Openstaand:** De **email inhoud** moet nog worden afgestemd op de nieuwe flow.

---

## 📦 **Benodigde bestanden bij aanvang sprint**

### **1. Taalbestanden** (voor email placeholders)
- `administrator/language/nl-NL/plg_system_simplelogin.ini`
- `administrator/language/en-GB/plg_system_simplelogin.ini`

### **2. Email templates** (indien apart bestand)
- Eventuele template files in `layouts/` of `tmpl/`

### **3. Configuratie** (voor email inhoud)
- Huidige parameter definities in `simplelogin.xml` (mail_login_body, mail_invite_body, etc.)

---

## 🎯 **Doelstellingen voor 1.0.7**
1. **Email inhoud** aanpassen voor:
   - Registratie bevestiging (met wachtmelding als admin approval aan staat)
   - Goedkeuringsmelding voor beheerder
   - Inloglink voor gebruiker (na goedkeuring)
   - Afkeurmelding voor gebruiker

2. **Placeholders** standaardiseren:
   - `#name`
   - `#email`
   - `#link`
   - `#expiry`
   - `#sitename`
   - `#adminlink` (voor beheerder emails)

---
## 🚀 **Startpunt**
Deel de bovenstaande bestanden, dan begin ik direct met de email implementatie!