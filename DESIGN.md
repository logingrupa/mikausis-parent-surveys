---
name: Mikausis — vecāku balss
description: Esošā Mikauša identitāte vecāku aptaujām.
colors:
  primary: "#3c3876"
  primary-deep: "#2d295c"
  accent: "#ff7402"
  accent-ink: "#963d00"
  ink: "#27243d"
  muted: "#666276"
  background: "#f7f7fb"
  surface: "#ffffff"
  lavender: "#eeedf7"
  border: "#dedce8"
  danger: "#a32937"
  success: "#27634b"
typography:
  display:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "clamp(2rem, 4vw, 3.25rem)"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.03em"
  body:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.7
  question:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "18px"
    fontWeight: 600
    lineHeight: 1.65
  control:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "14px"
    fontWeight: 500
    lineHeight: 1.7
  metadata:
    fontFamily: "Montserrat, sans-serif"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: 1.8
rounded:
  control: "8px"
  panel: "16px"
  notice: "12px"
spacing:
  small: "8px"
  medium: "16px"
  large: "32px"
---

# Design System: Mikausis — vecāku balss

## Overview

mikausis.lv identitātes turpinājums darba rīkam: oriģinālais logo, violets un oranžs, Montserrat. Režīms ir Operate. Vecāki lieto telefonu mājās vai pa ceļam; gaišs, augsta kontrasta lasīšanas laukums un dāsni pieskāriena mērķi palīdz aizpildīt īsto aptauju. Administrācijā priekšplānā ir īstais aptauju saraksts, ne izdomāti skaitļi.

## Colors

Violetais nes virsrakstus un primārās darbības. Oranžais ir zīmola akcents; maziem tekstiem uz gaiša fona lieto accent-ink. Baltais lasīšanas laukums nodalīts no gaiša fona. Izvēlēm — gaiša lavanda. Statusus vienmēr papildina teksts.

## Typography

Montserrat glabājas lokāli; vecāku pārlūks nekontaktējas ar Google Fonts. Virsraksti 600/700, pamatteksts 16px, jautājumi 18–20px (telefonā 16px), varianti 14px (telefonā 13px), palīgteksts un sadaļu numuri vismaz 13px. Garu rindu mērs ap 65–75 rakstzīmēm.

## Layout

Darba laukums līdz 1180px, anketas daļa ap 760px. Datorā tematisko sadaļu ceļvedis ir blakus; telefonā — kompakts pašreizējās sadaļas indikators. DOM un lasīšanas secība sakrīt. Vadīklas vismaz 44px augstas.

## Elevation & Depth

Plakanas virsmas ar robežām. Galvenajam darba laukumam drīkst būt viegls pacēlums ar ēnu uz leju. Fokuss ir kontūra. Bez dekoratīva stikla vai spīdumiem.

## Shapes

Vadīklām 8px stūri, lielām virsmām 16px. Pazīstami radio un checkbox. Oranžie akcenti nav jauni vadīklu veidi.

## Components

- Primārā poga violeta ar baltu tekstu, tumšāka hover; iesniegšanas laikā nosaukums paskaidro procesu.
- Varianta viss laukums ir label, ar radio/checkbox un atlasītu lavandas fonu.
- Ceļvedis norāda tematisko nodaļu, ne uzspiež atbildēt uz visiem jautājumiem.
- Kļūdas sasaistītas ar attiecīgo lauku. Rezultātu joslām arī skaits un procents tekstā.
- Administrācijas redaktorā jautājumu tekstus un izvēles labo tieši baltajā jautājuma laukumā. Tehniskie identifikatori ir paslēpti; “Vairāk” atver papildu nosacījumus, “Vecāku skats” rāda pašreizējo saturu. Redaktora virzienu nosaka [administrācijas virsmas apraksts](docs/surfaces/admin-survey-editor.md).
- Redaktora beigās viena kontaktinformācijas sadaļa apvieno jautājumu izvēles ķeksīšus un kopīgo kontaktlauku formu. Nosaukumu labo tieši virs ilustratīva atbildes lauka, atlasītajam laukam rāda kompaktu vadīklu joslu. Viena “Pievienot lauku” poga atver lauku izvēli. Vecākiem kontaktu iespēju rāda tikai beigās ar vienu sākotnēji neatzīmētu piekrišanu un informatīvu aktivitāšu sarakstu bez ķeksīšiem; atgādinājumus pie katra jautājuma neatkārto.

## Do's and Don'ts

- Do saglabāt īsto saturu un testēt garos jautājumus pie 360px.
- Do atbalstīt tastatūru un samazinātu kustību.
- Don't aizņemt aptauju ar bērnu fotogrāfijām vai ilustrācijām.
- Don't publiskot administrācijas statistiku vai ģimeņu etiķetes.
