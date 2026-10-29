# Mikausis — vecāku aptaujas

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Mikauša bērnudārza vecāki aizpilda aptaujas telefonā bez lietotāja konta. Iestādes administrācija izveido un atkārtoti izmanto aptaujas, publicē saites un saņem atskaites aizsargātā panelī.

## Product Purpose

Apkopot vecāku viedokli un padarīt aptauju organizēšanu atkārtojamu. Pirmā īstā aptauja ir `Aptauja vecākiem oktobrī.docx`, nevis demonstrācijas saturs.

## Operating Context

Sākumā sistēmu izmanto tikai Mikausis; datu modelī paredzēta iestāžu nodalīšana nākotnei. Sākotnēji apstiprināta kopīga aptaujas saite. Vēlākais precizējums: jāspēj uzskaitīt mammas un tēta dalību vienā ģimenē; IP adrese ģimenes identificēšanai netiek izmantota.

## Capabilities and Constraints

- Laravel, Filament administrācija un SQLite; izvietošana ar Laravel Forge.
- Īstās aptaujas jautājumi, atbilžu varianti, brīvais teksts un nosacītie papildjautājumi.
- Aptauju sagatavošana, publicēšana, slēgšana, kopēšana un rezultātu pārskati.
- Vecāku konti nav nepieciešami. Administrācija piesakās ar savu kontu.
- Papildu ģimenes uzaicinājuma saite ļauj norādīt mammas, tēta vai cita aizbildņa dalību. Dalības ierakstus un atbilžu saturu glabā atsevišķi.
- Kopīga saite negarantē vienu iesniegumu no personas. Ģimenes saite ierobežo iesniegumu uz izvēlēto lomu; saites turētāja identitāti tā neapliecina.
- Anonīmām atbildēm nepievieno respondenta datus. Vizuālajā redaktorā var pievienot vai noņemt “Respondenta dati” bloku, izvēlēties laukus, to etiķetes un obligātumu. “Anonīmi” vecākiem sākotnēji ir atzīmēts. Tikai to izslēdzot, brīvprātīgi norādītie dati tiek šifrēti un glabāti pie atbildēm, redzami aizsargātā HTML/drukas atskaitē. Esošām aptaujām šī iespēja paliek izslēgta. Pēc datu saņemšanas identifikācijas režīmu un lauku struktūru saglabā; nosaukumus var labot.
- Atbildēm nepievieno IP, ģimenes identifikatorus vai iesniegšanas laikzīmogus. Brīvajā tekstā respondents pats var sevi identificēt; tas ir jāpaskaidro.
- Redaktora beigās ar ķeksīšiem izvēlas kontaktu jautājumus un vienreiz konfigurē kopīgus kontaktlaukus, mērķi un aicinājumu. Katram jautājumam var izvēlēties aktivizējošās atbildes. Šādu jautājumu anonīmās atbildes glabā atsevišķi no pārējās anketas; katra jautājuma kontakti glabājas vēl atsevišķi, bez kopīga iesnieguma vai ģimenes identifikatora un laikzīmogiem. Sūtīšana notiek atsevišķos pieprasījumos; kontaktus neglabā sesijā vai localStorage.
- Jautājumu kontaktu saraksti pieejami tikai savas iestādes administrācijai, arī pirms pieciem iesniegumiem. Tajos ir tikai attiecīgā aktivitātes izvēle un kontakti; tos neievieto anonīmajās atskaitēs vai eksportos. Kontaktu var dzēst, nemainot aptaujas atbildes. Iestāde nosaka glabāšanas termiņu; automātiska dzēšana nav ieviesta.
- Vecāks kontaktinformāciju ievada vienreiz aptaujas beigās pēc vienas kopējas, sākotnēji neatzīmētas piekrišanas. Atbilstošās aktivitātes nosaka atbildes un veidotāja nosacījumi; vecāks redz informatīvu sarakstu bez papildu ķeksīšiem. Vienāda nosaukuma un tipa laukus apvieno tikai pārlūka atmiņā; katram atbilstošajam jautājumam sūta atsevišķu, tā konfigurācijai atbilstošu pieprasījumu. Piekrišanas noņemšana, atbilstošo aktivitāšu, to izvēļu vai kontaktu konfigurācijas maiņa noņem kopējo piekrišanu un notīra vēl nenosūtītos kontaktus. Pārējās aptaujas atbildes paliek anonīmas.
- SQLite jautājumu kontaktu un atbilžu tabulās nav arī slēpta secīga `rowid`. Precīzu jau saglabātu kontaktu pieprasījumu var atkārtoti apstiprināt pēc termiņa; mainīts saturs ar to pašu identifikatoru rada skaidru kļūdu. Nenoteikta tīkla rezultāta gadījumā pārlūks atkārto sākotnējās vērtības un līdz apstiprināšanai aptur to labošanu.
- Visas anketas identifikācijas bloks un jautājumu kontakti ir savstarpēji izslēdzoši.
- Publicētas aptaujas saturu var labot, saglabājot saiti un atbildes. Tekstu labojumiem paliek stabilas atslēgas; struktūras maiņa vai dzēšana saglabā agrāko jautājuma definīciju servera pārvaldītā arhīvā un mainītajam jautājumam piešķir jaunu atslēgu. Agrākās atbildes un kontakti paliek savos pārskatos. Versijas vai to parādīšanas laikus nepievieno atbilžu ierakstiem; pēc izmaiņām neizdomā izlaisto atbilžu skaitu pa versijām. Novecojuša formas iesnieguma saturu nepieņem daļēji.
- HTML atskaite ir patstāvīga lejupielādējama lapa ar drukas stiliem: katram jautājumam seko tā atbildes. PDF saglabā ar pārlūka drukas funkciju. CSV paliek tikai apkopotie skaiti bez respondenta datiem; visiem rezultātu eksportiem saglabā piecu iesniegumu slieksni.
- Lietotne nedrīkst apgalvot absolūtu anonimitāti: atsevišķi jākonfigurē servera piekļuves un kļūdu žurnāli.
- Sākuma valoda ir latviešu, atbilstoši dokumentam. Citas valodas nav pieprasītas.

## Brand Commitments

Esošais publiskais vizuālais pamats ir https://mikausis.lv/ — WordPress/Oxygen vietne. Saglabājam atpazīstamo Mikauša identitāti: violetais `#3c3876`, oranžais `#ff7402`, Montserrat burtveidols un oriģinālie zīmola materiāli, ja tie ir pieejami. Aptauja ir pieaugušajiem paredzēts, viegli lasāms darba rīks.

## Evidence on Hand

- `Aptauja vecākiem oktobrī.docx`: oriģinālais aptaujas ievads un jautājumi; dokumenta numurācijā izlaists 11. Dokuments nav iekļauts publiskajā repozitorijā; jautājumi pieejami sākotnējo datu JSON.
- https://mikausis.lv/: iestādes nosaukums un vizuālā identitāte.
- Reālu atbilžu, testa vecāku vai administrācijas kontu nav. Piegāde nedrīkst izdomāt reālus rezultātus vai publicēt kopīgu noklusējuma paroli.

## Product Principles

- Vecākiem viena saprotama darbība vienā brīdī; anketa darbojas telefonā un ar tastatūru.
- Atbilžu satura aizsardzība ir svarīgāka par smalku individuālu izsekošanu.
- Administrācijai atkārtojams darbs un skaidri rezultātu saucēji.
- Aptauju veido vizuāli kā Google Forms: administrators ievada jautājumus un atbilžu tekstus; saites un iekšējos identifikatorus sistēma izveido pati.
- Maz uzturamu komponentu, bez maksas par aptauju vai atbildi.
