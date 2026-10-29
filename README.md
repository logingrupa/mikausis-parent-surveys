# Mikausis — vecāku aptaujas

Latviešu valodas vecāku aptauju lietotne ar Laravel 12, Filament 4 administrācijas paneli un SQLite. Sākotnējais saturs ir PPII “Mikausis” oktobra aptauja, kuras jautājumi saglabāti [sākotnējo datu JSON](database/seed-data/october-survey.json). Oriģinālais Word dokuments publiskajā repozitorijā nav iekļauts.

Publiskais pirmkods: <https://github.com/logingrupa/mikausis-parent-surveys>.

## Lokālā palaišana Windows / Laragon

Nepieciešams Composer 2 un PHP **8.4.1 vai jaunāks** ar `pdo_sqlite`, `intl`, `mbstring`, `zip` un pārējiem Composer pieprasītajiem paplašinājumiem. Laragon terminālī pārbaudiet `php --version`; tīmekļa serverim un terminālim jāizmanto saderīgas PHP versijas.

Jaunai instalācijai PowerShell terminālī:

```powershell
Set-Location C:\laragon\www
git clone https://github.com/logingrupa/mikausis-parent-surveys.git mikausis
Set-Location C:\laragon\www\mikausis
composer install
Copy-Item .env.example .env
php artisan key:generate
if (-not (Test-Path -LiteralPath database/database.sqlite)) {
    New-Item -ItemType File -Path database/database.sqlite | Out-Null
}
php artisan migrate --seed
php artisan filament:assets
php artisan mikausis:admin --email="admin@example.com" --name="Administrators" --generate-password
php artisan serve --host=127.0.0.1 --port=9381
```

Esošai instalācijai saglabājiet tās `.env`, `APP_KEY` un datubāzi. `.env.example` izmanto `APP_ENV=local`, `DB_CONNECTION=sqlite`, failu sesijas un kešatmiņu. Bez `DB_DATABASE` norādes lietotne izmanto `database/database.sqlite`.

Atveriet <http://127.0.0.1:9381>; administrācija ir <http://127.0.0.1:9381/admin>. `admin@example.com` aizstājiet ar savu adresi. Noklusējuma administratora konta nav. Komanda izveido administratoru **pirmajai datubāzes iestādei** un ģenerēto paroli parāda vienreiz — saglabājiet to paroļu pārvaldniekā. Bez `--generate-password` parole jāievada interaktīvi divreiz, vismaz 12 rakstzīmes. Pieslēdzoties paroli var nomainīt lietotāja profilā.

Publiskie CSS, JavaScript, attēli un fonti ir lokāli mapē `public`; npm instalācija vai Vite būvēšana šai lietotnei nav vajadzīga. Filament failus publicē `php artisan filament:assets`. Var izmantot arī `composer run dev`, kas palaiž PHP serveri 9381. portā.

Saite **“Pirmkods”** publisko lapu kājenē un privātuma lapā ved uz šo repozitoriju. Savas kopijas publisko repozitoriju var norādīt ar `APP_SOURCE_URL` HTTPS adresi; tukša vērtība paslēpj saiti.

Lietotnes kodam piemēro [MIT licenci](LICENSE). Montserrat fontiem piemēro [SIL Open Font License](public/fonts/OFL.txt). Mikausis nosaukums un zīmola attēli nav lietotnes MIT licences daļa; to izcelsme aprakstīta [zīmola materiālu piezīmēs](public/images/README.md). Savā instalācijā izmantojiet savas iestādes zīmolu.

Pārbaude atsevišķā terminālī:

```powershell
composer check-platform-reqs
php artisan test
```

PHPUnit izmanto izolētu SQLite `:memory:` datubāzi, nevis instalācijas datubāzi. JavaScript pārbaudes palaidiet ar `node --test tests/JavaScript/*.test.mjs`.

## Sākotnējā aptauja un administrēšana

[Sākotnējais JSON](database/seed-data/october-survey.json) satur 14 pamata jautājumus un papildu `q7_details` lauku — kopā 15 jautājumu ierakstus piecās sadaļās. [Pārnesuma piezīmes](database/seed-data/README.md) izskaidro avota numerāciju un saglabātās neskaidrības. Katrs sākotnējās aptaujas jautājums ir neobligāts, bet iesniegšanai jāatbild vismaz uz vienu.

`migrate --seed` izveido iestādi un īsto oktobra aptauju. `local` vidē aptauja ir uzreiz **atvērta** un redzama sākumlapā; citās vidēs, arī `production`, tā tiek izveidota kā **melnraksts**. Atkārtota sākotnējo datu ievietošana nepārraksta esošās aptaujas labojumus vai statusu un neveido lietotājus.

Panelī var izveidot un labot arī publicētas aptaujas, mainīt sadaļas un jautājumus, publicēt, slēgt, atvērt atkārtoti un kopēt aptaujas. Atbilžu veidi: viena izvēle, vairākas izvēles un brīvais teksts; pieejami nosacījumi, obligātie jautājumi un “Cits” papildinājums.

Vizuālajā redaktorā pietiek ierakstīt aptaujas nosaukumu, jautājumu un atbilžu tekstus. Enter pievieno nākamo atbildes variantu; “Pievienot jautājumu” izveido nākamo jautājumu. Slug, sadaļu un jautājumu identifikatori un atbilžu iekšējās vērtības rodas automātiski. “Vecāku skats” rāda arī nesaglabātās izmaiņas. Nosacījumus un papildu iespējas atver ar “Vairāk” un izvēlas pēc jautājumu un atbilžu tekstiem.

Ja vēlaties ļaut vecākiem brīvprātīgi norādīt savus datus kopā ar visas anketas atbildēm, pievienojiet **“Respondenta dati”** bloku. Var izvēlēties vārdu, ģimenes lomu, e-pastu, tālruni vai pievienot savu lauku, mainīt etiķeti un obligātumu. Vecākiem **“Anonīmi” vienmēr sākotnēji ir atzīmēts**; tikai pēc atzīmes noņemšanas parādās izvēlētie lauki. Bez bloka atbildes vienmēr ir anonīmas. Esošās aptaujās bloks automātiski netiek ieslēgts. Kad jau saņemti dati, visas anketas identificēšanas režīmu un datu lauku struktūru mainīt nevar; nosaukumu kļūdas joprojām var labot.

Publicētu aptauju labo tajā pašā redaktorā un nospiež **“Saglabāt”**; vecāku saite nemainās, un labojumi kļūst redzami uzreiz pēc saglabāšanas. Tekstu un nosaukumu labojumi saglabā esošos jautājumus. Mainot atbildes veidu, dzēšot variantus vai mainot kontaktu struktūru, sistēma saglabā agrāko jautājuma definīciju un izveido jaunu aktīvo versiju. Arī dzēstu jautājumu jau saņemtās atbildes paliek atskaitē ar atzīmi **“Iepriekšējā jautājuma versija”**. Tukšas agrākās versijas atskaitē nerāda. Pēc struktūras maiņas neuzrāda izlaisto atbilžu skaitu pa versijām, jo neglabā respondenta versijas vai jautājumu parādīšanas vēsturi. Arī pēc publicēšanas pievienotam jautājumam izlaisto skaitu nerāda: agrākie respondenti šo jautājumu nebija redzējuši.

Ja vecāks atvēris formu pirms izmaiņām, jaunu iesniegumu lūdz sākt aktuālajā formā; novecojušas atbildes nepieņem daļēji vai klusējot. Jau saglabāta iesnieguma atkārtojums nerada dublikātu. **“Kopēt jaunai aptaujai”** joprojām izveido atsevišķu melnrakstu ar jaunu saiti, bez atbildēm un uzaicinājumiem. Publicētu aptauju nevar atgriezt melnrakstā vai dzēst. Termiņa iestāšanās aptur atbilžu pieņemšanu, bet pati nemaina statusu uz “Slēgta”.

### Kontakti tikai vienam jautājumam

Redaktora beigās kontaktinformācijas sadaļā atzīmējiet jautājumus, par kuriem vēlaties saņemt kontaktus. Izvēlieties atbilstošās atbildes, piemēram, “Jā, piedalīšos”, un vienreiz konfigurējiet kopīgos laukus — vārdu, tālruni, e-pastu vai citus. Pieejams kopīgs aicinājums un izmantošanas mērķis. Iepriekš atšķirīgi konfigurētu jautājumu laukus sistēma pati nepārraksta; tos var apzināti vienādot redaktorā. Vecākiem kontaktu iespēju un atbilstošās aktivitātes rāda tikai aptaujas beigās. Sākotnēji kontaktu atstāšana nav atzīmēta. Vecāks pēc izvēles atzīmē vienu kopējo piekrišanu; aktivitātes nosaka viņa atbildes un veidotāja nosacījumi, un saraksts ir tikai informatīvs. Lauku obligātums attiecas tikai uz kontaktu atstāšanu; bez kontaktiem aptauju var iesniegt.

Kontaktus ievada vienreiz. Lauki ar vienādu nosaukumu un tipu tiek apvienoti; katram atbilstošajam jautājumam nosūta tikai tā konfigurētos laukus ar oriģinālajām atslēgām. Atšķirīgus nosaukumus un tipus neapvieno. Pēc kopējās piekrišanas ieslēgšanas iekļauj visas tobrīd atbilstošās aktivitātes. Ja mainās atbilstošo aktivitāšu saraksts, to izvēles vai kontaktu konfigurācija, kopējā piekrišana tiek noņemta un vēl nenosūtītie kontakti notīrīti. Tos var ievadīt no jauna pēc atkārtotas piekrišanas. Arī paša vecāka noņemta kopējā piekrišana notīra kontaktus. Atkārtotai izmantošanai tos glabā tikai lapas atmiņā. Vienādi kontakti ļauj atpazīt cilvēku atbilstošo aktivitāšu kontaktu sarakstos; pārējās anketas atbildes paliek anonīmas.

Kopīgo kontaktlauku nosaukumus labo tieši virs to attēlojuma. Atlasiet lauku, lai mainītu tā tipu, atzīmi **“Obligāts”** vai to noņemtu. **“Pievienot lauku”** atver vārda, e-pasta, tālruņa, lomas un sava teksta lauka izvēli. Rediģēšanas režīmā atbildes lauki ir ilustratīvi; ievadi un piekrišanu var izmēģināt **“Vecāku skatā”**.

Vispirms tiek iesniegtas anonīmās atbildes, pēc tam atsevišķos pieprasījumos — katra izvēlētā jautājuma kontakti. Kontaktlauki nav nosaukti galvenās formas laukos un nenonāk tās `FormData`. Ja savienojums pārtrūkst un nevar zināt, vai kontakti saglabāti, pārlūks saglabā nosūtīto vērtību kopiju tikai atmiņā, aptur labošanu un atkārto tieši to pašu pieprasījumu, vēlreiz neiesniedzot aptauju. Var arī pabeigt, neatkārtojot pieprasījumu; kontakti jau var būt saglabāti. Zināmas validācijas kļūdas ļauj labot. Serveris neatļauj ar vienu kontaktu UUID klusējot aizstāt saturu: mainīta atkārtojuma gadījumā rāda skaidru kļūdu; jau saglabāta precīza atkārtojuma apstiprināšana darbojas arī pēc termiņa. Pēc pārlādes vērtības netiek atjaunotas no sesijas vai pārlūka glabātuves. Bez JavaScript kontaktus var atstāt atsevišķā lapā no aptaujas pateicības skata. Kontaktinformācijas iesniegšana pati par sevi neapliecina aptaujas aizpildīšanu vai personas identitāti.

Administrācijā **“Brīvprātīgie kontakti”** atver atsevišķus sarakstus pa jautājumiem ar attiecīgo izvēli un kontaktiem. Tie pieejami arī pirms pieciem aptaujas iesniegumiem, jo cilvēks ir skaidri piekritis atstāt kontaktus aktivitātei. Kontakti neparādās anonīmajos rezultātos, CSV, HTML vai PDF. Atsevišķu kontaktierakstu var dzēst ar apstiprinājumu; anonīmās atbildes tas nemaina. Iestādei jānosaka kontaktu glabāšanas termiņš un tie jādzēš pēc nepieciešamības beigām; automātiska dzēšana nav ieviesta.

Jautājumu kontakti nav savienojami ar visas anketas **“Respondenta dati”** bloku. Gan redaktors, gan serveris nepieļauj abu režīmu ieslēgšanu vienā aptaujā. Labošana pati nepārraksta jau iesniegtās atbildes vai kontaktus; agrāko versiju kontakti paliek savas iestādes aizsargātajā kontaktu sarakstā. Jaunu kontaktu iesniegšanu agrākajai versijai nepieņem, bet precīzu jau saglabātu pieprasījumu joprojām var apstiprināt.

## Saites un dalības uzskaite

- **Kopīgā saite** `/a/{slug}` neprasa vecāka kontu vai uzaicinājumu. Tā pieejama panelī ar darbību “Atvērt vecāku saiti”. Sākumlapā uzskaitītas atvērtās Mikausis aptaujas.
- **Izvēles ģimenes saite** `/a/{slug}/g/{token}` tiek izveidota aptaujas pārvaldīšanas sadaļā “Ģimeņu uzaicinājumi”. Vecāks izvēlas “Mamma”, “Tētis” vai “Cits aizbildnis”. Vienam uzaicinājumam katra loma var iesniegt vienu reizi. Saite neapliecina personas identitāti; ikviens tās turētājs redz jau izmantotās lomas. Saites izplatīšana jāveic pašiem; automātiska e-pasta nosūtīšana nav ieviesta.
- Ģimenes apzīmējums un lomu dalības atzīmes glabājas **atsevišķi no atbilžu satura**. Atbilžu ierakstā nav ģimenes identifikatora. Ja vecāks izslēdz “Anonīmi” un aptaujas datu blokā ir ģimenes loma, šī brīvprātīgi norādītā loma glabājas arī pie atbildēm. Kopīgās saites iesniegumiem dalības atzīmes nav; uzaicinājumu skaits nav respondentu procentu saucējs.

Sesijas iesnieguma identifikators novērš atkārtota klikšķa radītas kopijas. Kopīgā saite neierobežo vienu atbildi uz personu: jaunā pārlūka sesijā var iesniegt vēlreiz. Mamma un tētis var izmantot vienu pārlūku: pēc pirmā iesnieguma ģimenes saite jāatver no jauna, lai sāktu nākamās lomas anketu. Vienu anketu nevajag iepriekš atvērt vairākās cilnēs dažādiem respondentiem.

## Atskaišu lasīšana un eksports

Administrators redz savas iestādes aptaujas. Darbība “Rezultātu pārskats” rāda iesniegumu un dalības kopskaitus; jautājumu rezultāti un komentāri kļūst redzami pēc **vismaz pieciem aptaujas iesniegumiem**. Līdz tam arī CSV eksports ir slēgts. Slieksnis attiecas uz visu aptauju, nevis atsevišķu jautājumu.

Procentus katram jautājumam rēķina no uz to atbildējušo skaita. Vairāku izvēļu jautājumos procentu summa var pārsniegt 100%. “Izlaiduši” ietver arī nosacītos jautājumus, kuri respondentam netika parādīti. Brīvā teksta atbildes rāda pie attiecīgā jautājuma sajauktā secībā.

**CSV** satur apkopotus atbilžu variantu skaitus un procentus; atdalītājs ir semikols, kodējums UTF-8 ar BOM. Tajā nav brīvā teksta, respondenta datu, ģimeņu apzīmējumu, saišu vai atsevišķa respondenta visu atbilžu rindas. Tikai teksta jautājumiem CSV nav atsevišķu rezultātu rindu.

**HTML atskaite** atverama atsevišķā drukas skatā vai lejupielādējama kā patstāvīga HTML lapa. Jautājumi seko aptaujas secībā; pie katra ir kopsavilkums un attiecīgās atbildes. Ja vecāks brīvprātīgi norādīja savus datus, tie parādās pie viņa atbildes; pārējām atbildēm ir atzīme “Anonīmi”. Katra jautājuma atbildes sajauc atsevišķi, neeksportējot UUID vai stabilu anonīma respondenta numuru. Drukas skatā **“Drukāt / saglabāt PDF” → “Saglabāt kā PDF”** izveido PDF ar A4 drukas stiliem bez administrācijas navigācijas. HTML atskaitei un lejupielādei nepieciešama administratora piekļuve savai iestādei un vismaz pieci iesniegumi. Lejupielādētais fails var saturēt personu datus, tādēļ tas jāglabā un jāizplata atbilstoši tā saturam.

## Privātums un datu glabāšana

Anonīmiem atbilžu ierakstiem nepievieno respondenta datus. Arī manuāli iesūtīti vārdi un cita respondenta informācija tiek atmesta, ja “Anonīmi” ir atzīmēts. Ja vecāks skaidri izvēlas norādīt datus, saglabā tikai aptaujā konfigurētos aizpildītos laukus šifrētā `respondent_details` laukā; tā atšifrēšanai vajadzīgs saglabātais lietotnes `APP_KEY`. Respondenta dati neglabājas sesijas vecajā ievadē vai parastajā modeļa JSON serializācijā.

Atbilžu ierakstiem nepievieno IP adresi, iesniegšanas laiku vai ģimenes saiti. Parastos jautājumus glabā iesnieguma atbilžu komplektā. Jautājumiem ar atsevišķu kontaktu bloku **katru atbildi** (arī bez kontaktu piekrišanas vai ar citu atbildes variantu) izņem no kopējā komplekta un glabā `question_answers` ar neatkarīgu, servera ģenerētu UUID. Kontakti glabājas `question_contacts`: tikai aptauja, jautājums, šim kontaktam izvēlētais variants un konfigurētie kontaktlauki. Variants un kontakti šajā tabulā ir šifrēti ar `APP_KEY`. Abās tabulās nav kopīga iesnieguma, ģimenes, sesijas vai otra ieraksta identifikatora un nav laikzīmogu. Kontaktierakstu identifikatori netiek saglabāti sesijā; jautājuma atbilžu un kontaktu UUID ir neatkarīgi. Atskaite apvieno tikai jautājuma apkopojumu, neatjaunojot cilvēku sasaisti.

SQLite tabulas `question_answers` un `question_contacts` izmanto `WITHOUT ROWID`: tajās nav slēpta secīga ievietošanas numura, ko varētu salīdzināt ar galvenās anketas ierakstu. To nodrošina papildu migrācija, saglabājot esošos ierakstus un šifrētās vērtības.

Servera administrators ar datubāzes un atslēgas piekļuvi var nolasīt datus. Lietotne nesola absolūtu anonimitāti: servera žurnāli var saturēt IP adreses, laikus un ģimenes saišu URL; sesija satur galvenā iesnieguma identifikatoru; brīvais teksts, nosacīti jautājumi vai neliels dalībnieku skaits var ļaut izdarīt secinājumus. Pieprasījumu saturu, kontaktus un atbildes nedrīkst reģistrēt žurnālos. Kontaktu piekrišanu var atsaukt, sazinoties ar iestādi un norādot jautājumu un savus kontaktus; atsevišķā ieraksta dzēšana neatpazīst un nemaina personas anonīmās atbildes. Rezerves kopijām piemēro atsevišķus glabāšanas termiņus.

Reklāmas un trešo pušu analītikas nav. Sesijas pēc noklusējuma glabājas failos šifrētā veidā (`SESSION_DRIVER=file`, `SESSION_ENCRYPT=true`); tas nenozīmē visas SQLite datubāzes šifrēšanu. SQLite konfigurēts `WAL`, 5000 ms aizņemtības gaidīšanas laiks un ārējās atslēgas. Iestādei jānosaka datu un žurnālu glabāšanas termiņš; automātiska datu dzēšana nav ieviesta.

Izvietošana, HTTPS, pastāvīgs datubāzes ceļš un drošas rezerves kopijas aprakstītas [Forge instrukcijā](docs/forge.md). Datu modelis un administratoru piekļuve nodala iestādes, bet publiskais noformējums, sākumlapa un administratora izveides komanda pašlaik pielāgoti Mikausim. Citu iestāžu pievienošana un to administratoru piesaiste vēl jānodrošina tehniski; pašapkalpošanās reģistrācijas nav.
