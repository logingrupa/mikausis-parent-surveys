# Izvietošana ar Laravel Forge

Šī ir instrukcija uzturētājam vienas servera instalācijas sagatavošanai. Komandas ir piemēri Linux serverim; domēnu un ceļus aizstājiet ar faktiskajiem. Izvietošana vēl jāveic atsevišķi, kad pieejams serveris un tā ir saskaņota. Lokālā palaišana un lietotnes uzvedība aprakstīta [README](../README.md).

## Izmaksas un serveris

Forge pārvalda VPS; tas neaizstāj pašu hostingu. Kopējās izmaksas veido Forge abonements, VPS, domēns un rezerves kopiju glabāšana. Ja jau ir piemērots Forge pārvaldīts serveris, papildu vietnes izmaksas var būt nelielas, taču tas atkarīgs no servera jaudas un izvēlētajiem plāniem. Konkrētu cenu vai kapacitātes garantiju šeit nav.

Sākumam piemērota viena Laravel vietne ar lokālu SQLite disku. Šai lietotnei nav vajadzīga MySQL, Redis, npm būvēšana vai pastāvīgs rindas darbinieks: izmanto failu kešatmiņu un `QUEUE_CONNECTION=sync`. Vairāki serveri ar atsevišķām SQLite kopijām nav šī izvietojuma variants. Pirms citu iestāžu pievienošanas jānodrošina to sākotnējie dati un administratoru piesaiste; pašreizējais zīmols un publiskā sākumlapa ir Mikausim.

## 1. Forge vietne un PHP

1. Pievienojiet Forge vietnei publisko repozitoriju `https://github.com/logingrupa/mikausis-parent-surveys` vai savu tā kopiju. Saglabājiet `composer.lock`, sākotnējās aptaujas JSON un lietotnes publiskos statiskos failus; Filament failus publicē instalācijas solī. Nekopējiet repozitorijā `.env`, darba datubāzi, sesijas vai rezerves kopijas.
2. Izvēlieties aktuālu PHP **8.4** labojumu versiju, vismaz **8.4.1**; tā jāizmanto gan FPM, gan Composer/Artisan komandrindai.
3. Ieslēdziet `pdo_sqlite`, `intl`, `mbstring`, `zip`, kā arī Laravel/Composer prasītos `curl`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `openssl`, `pcre`, `session`, `tokenizer`, `xml` un `xmlreader` paplašinājumus. `ctype` arī jābūt pieejamam. Ubuntu gadījumā attiecīgās pakotnes parasti ir `php8.4-sqlite3`, `php8.4-intl`, `php8.4-mbstring`, `php8.4-zip`, `php8.4-xml` un `php8.4-curl`; daļa paplašinājumu ir PHP pamatkomplektā. Gala pārbaude ir `composer check-platform-reqs --no-dev`, papildus pārliecinoties par `pdo_sqlite`.
4. Nginx dokumentu saknei jābūt lietotnes **`/public`**, piemēram, `/home/forge/aptaujas.example.lv/public`; versiju izvietojumā — aktīvās versijas `public`. Projekta sakni, `.env`, SQLite failus un kopijas publiski nepasniedziet.
5. Pievienojiet faktisko domēnu, DNS un TLS sertifikātu, ieslēdziet pāradresāciju uz HTTPS. Piekļuvei administrācijai un ģimeņu saitēm izmantojiet HTTPS.

## 2. Pastāvīgi dati un `.env`

Datubāzei izmantojiet **absolūtu ceļu ārpus jebkuras izvietojuma versijas mapes**. Šeit piemēra datubāze ir `/home/forge/mikausis-shared/database.sqlite`, bet kopijas atrodas `/home/forge/mikausis-backups`.

Kā vietnes lietotājs `forge`, tikai pirmajā uzstādīšanā:

```bash
install -d -m 700 /home/forge/mikausis-shared /home/forge/mikausis-backups
if [ ! -e /home/forge/mikausis-shared/database.sqlite ]; then
    (umask 077; touch /home/forge/mikausis-shared/database.sqlite)
fi
```

PHP-FPM un Artisan procesiem jāspēj rakstīt datubāzē **un tās mapē**, jo SQLite tur veido `-wal` un `-shm` failus. Piemēra `700`/`600` tiesības paredz vienu un to pašu `forge` lietotāju; citā konfigurācijā pielāgojiet īpašnieku/grupu. Arī `storage` un `bootstrap/cache` jābūt rakstāmiem; neizmantojiet `chmod 777`.

Forge vides iestatījumos sagatavojiet `.env`, balstoties uz `.env.example`:

```dotenv
APP_NAME="Mikausis — vecāku aptaujas"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://aptaujas.example.lv
APP_LOCALE=lv
APP_FALLBACK_LOCALE=en
DB_CONNECTION=sqlite
DB_DATABASE=/home/forge/mikausis-shared/database.sqlite
DB_FOREIGN_KEYS=true
SESSION_DRIVER=file
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_DOMAIN=null
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
```

`APP_URL` aizstājiet ar faktisko HTTPS adresi. `DB_URL` atstājiet nenorādītu, lai tas nepārrakstītu SQLite savienojumu. `APP_KEY` ģenerējiet pirmajā instalācijā ar `php artisan key:generate --force` un pēc tam saglabājiet nemainīgu aizsargātajā vidē. Tā vajadzīga šifrētajām sesijām un ģimeņu saišu tokeniem; datubāzes atjaunošanai saglabājiet arī atbilstošo atslēgu.

Ja Forge veido jaunu versijas mapi katrā izvietošanā, konfigurējiet koplietojamu `.env` un **visu `storage`**, tostarp `storage/framework/sessions`. Pārbaudiet šīs saites pirms versijas aktivizēšanas. Datubāzes absolūtajam ceļam visās versijās jāpaliek vienam un tam pašam. `bootstrap/cache` ir konkrētās versijas rakstāma kešatmiņa. Parastā izvietošanā esošā mapē šos datus arī nedrīkst aizvietot ar lokālās izstrādes kopijām.

## 3. Pirmā instalācija

Komandas izpildiet servera lietotnes mapē pēc iepriekšējā soļa. Ja `php` nav izvēlētā Forge PHP versija, izmantojiet tās pilno ceļu arī izvietošanas skriptā.

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
php artisan filament:assets
php artisan mikausis:admin --email="admin@example.com" --name="Administrators" --generate-password
php artisan config:cache
php artisan view:cache
```

`key:generate` palaidiet **tikai jaunai instalācijai bez atslēgas**; izlaidiet to, ja Forge jau izveidojis `APP_KEY`. Administratora komandu palaidiet privātā terminālī: ģenerētā parole tiek izvadīta vienreiz, un tai nevajag nonākt publiskos izvietošanas žurnālos. Noklusējuma konta nav; komanda piesaista administratoru pirmajai iestādei.

**`config:cache` drīkst izpildīt tikai pēc pareizā `DB_DATABASE`, `APP_KEY` un produkcijas iestatījumu saglabāšanas.** Novecojusi kešatmiņa var likt lietotnei izmantot citu datubāzi vai vidi. Pēc `.env` labošanas atkārtojiet `config:clear` un `config:cache`.

Produkcijas sākotnējie dati izveido Mikausis iestādi un oktobra aptauju **melnrakstā**. Pieslēdzieties `/admin`, pārbaudiet tekstus un publicējiet apzināti. Kamēr nav publicētas aptaujas, tukša publiskā sākumlapa ir sagaidāma.

## 4. Atkārtota izvietošana

Pirms migrācijām izveidojiet pārbaudītu kopiju, kā aprakstīts nākamajā sadaļā. Forge skriptam jādarbojas pareizajā lietotnes vai jaunās versijas mapē pēc repozitorija atjaunināšanas un koplietojamo failu piesaistes:

```bash
set -e
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
php artisan config:clear
php artisan migrate --force
php artisan filament:assets
php artisan config:cache
php artisan view:cache
```

Pēc tam izpildiet Forge attiecīgā izvietošanas veida versijas aktivizēšanu un PHP-FPM pārlādi. Komandu kļūdām jāaptur izvietošana. Parastai izvietošanai esošajā mapē ar pārtraucošām migrācijām izmantojiet apkopes logu; versiju pārslēgšana pati negarantē migrāciju saderību.

Atkārtotā skriptā neģenerējiet atslēgu, neveidojiet tukšu datubāzi, nepalaižiet `migrate:fresh` un nekopējiet lokālo SQLite failu. `db:seed` ikreiz nav vajadzīgs; pašreizējais sākotnējo datu ievietotājs nepārraksta esošo aptauju, taču vēlāk to var mainīt. Darba atbildēm, dalības atzīmēm un uzaicinājumiem jāpaliek tajā pašā pastāvīgajā datubāzē.

## 5. SQLite rezerves kopijas

Lietotne izmanto `journal_mode=WAL`, `busy_timeout=5000` ms un `synchronous=NORMAL`. **Dzīvas datubāzes galvenā faila vienkārša kopēšana nav derīga kopija:** jaunākie darījumi var būt `-wal` failā. Izmantojiet SQLite tiešsaistes rezerves kopēšanas API, piemēram, `sqlite3` komandu `.backup`.

Serverī uzstādiet `sqlite3` komandrindas rīku. Šo Bash skriptu var saglabāt serverī ārpus tīmekļa saknes, piemēram, `/home/forge/mikausis-shared/backup.sh` (skripts nav daļa no lietotnes):

```bash
#!/usr/bin/env bash
set -euo pipefail
umask 077

backup_dir=/home/forge/mikausis-backups
source_db=/home/forge/mikausis-shared/database.sqlite
backup_file="$backup_dir/database-$(date -u +%Y%m%dT%H%M%SZ).sqlite"

test -s "$source_db"
install -d -m 700 "$backup_dir"
sqlite3 -readonly "$source_db" ".timeout 10000" ".backup '$backup_file'"
test "$(sqlite3 -readonly "$backup_file" 'PRAGMA integrity_check;')" = "ok"
find "$backup_dir" -maxdepth 1 -type f -name 'database-*.sqlite' -mtime +14 -delete
```

Forge plānotajos uzdevumos pievienojiet `bash /home/forge/mikausis-shared/backup.sh` ar lietotāju `forge`, piemēram, **katru dienu 02.15 servera laika joslā** (`15 2 * * *`). Lietotnē šāds uzdevums nav ieviests. Retences piemērs dzēš lokālās kopijas, kas vecākas par 14 dienām; izvēlieties iestādes vajadzībām piemērotu termiņu un uzraugiet uzdevumu kļūdas un diska vietu.

Pārbaudītās kopijas regulāri pārnesiet uz aizsargātu glabātuvi ārpus šī servera, ar ierobežotu piekļuvi un šifrēšanu. `.env` un `APP_KEY` glabājiet atsevišķi aizsargātā vietā. Šis skripts pats nepārnes kopijas ārpus servera. Arī kopijām piemēro datu glabāšanas termiņus.

### Atjaunošana

Vispirms izmēģiniet atjaunošanu atsevišķā instalācijā ar atbilstošo kodu un atslēgu. Dzīvā serverī apturiet visus rakstītājus: ieslēdziet apkopes režīmu un apturiet vietnes PHP-FPM procesus; apturiet arī attiecīgos plānotos vai komandrindas darbus. Ar `artisan down` vien nepietiek jau iesāktiem pieprasījumiem.

Kad savienojumi aizvērti, saglabājiet veco datubāzi kopā ar tās `-wal`/`-shm` failiem atsevišķā aizsargātā mapē. Pārbaudiet izvēlētās kopijas `PRAGMA integrity_check`, ievietojiet to `DB_DATABASE` ceļā ar pareizu īpašnieku/tiesībām un pārliecinieties, ka šajā ceļā nav palikuši iepriekšējās datubāzes sānu faili. Atjaunojiet atbilstošo `APP_KEY`, pārbaudiet konfigurāciju un tikai tad startējiet FPM un atceliet apkopes režīmu. Koda atgriešana iepriekšējā versijā pati neatgriež migrācijas vai datus.

## 6. Pārbaude un ikdienas uzturēšana

- Pārbaudiet HTTPS, `/up`, `/`, `/privatums` un `/admin/login`; `.env`, datubāzei un kopijām no tīmekļa jābūt nepieejamām.
- Pārbaudiet publiskās formas un administrācijas CSS/JavaScript ielādi. npm būvēšana nav vajadzīga; publiskie faili jau ir `public/css`, `public/js`, `public/fonts` un `public/images`, Filament failus publicē `filament:assets`.
- Izmēģinājuma aptauju un vismaz piecus testu iesniegumus pārbaudiet atsevišķā testa instalācijā: rezultāti, CSV un drukāšana PDF, lomu dalība (arī vienā pārlūkā, atkārtoti atverot saiti) un iestāžu piekļuves nodalīšana. Testa atbildes neievietojiet īstajā vecāku aptaujā. `php artisan test` palaidiet izstrādes/CI vidē ar izstrādes atkarībām.
- Pēc izvietošanas pārbaudiet, ka agrākie uzaicinājumi, atbilžu skaits un dalības atzīmes saglabājušās. Regulāri pārbaudiet kopiju atjaunošanu un servera brīvo diska vietu.
- Sesijas glabājas šifrētos failos, bet SQLite atbildes nav šifrētas ar šo iestatījumu. Ierobežojiet servera, žurnālu un kopiju piekļuvi. Nginx un starpniekserveru žurnāli var saturēt IP adreses, laikus un ģimeņu tokenus URL; nosakiet atbilstošu žurnālu formātu un glabāšanas termiņu. Lietotne to pati nekonfigurē.

Piecu iesniegumu slieksnis slēpj jautājumu rezultātus un komentārus līdz minimālajam kopskaitam. Tas nenodrošina absolūtu anonimitāti: nelieli atsevišķu jautājumu skaiti, atpazīstami komentāri vai secīgu apkopojumu salīdzināšana var ļaut izdarīt secinājumus par personām.

### Jautājumu kontakti un žurnāli

Migrācija `2026_10_29_000200_create_question_contacts_table.php` pievieno `question_contacts` un `question_answers`; esošos jautājumus vai atbildes tā nepārveido. Kontakti un viņu izvēlētais aktivitātes variants ir šifrēti ar esošo `APP_KEY`, kas jāsaglabā arī atjaunošanai. Vispārīgas atbilžu kopas un `question_answers` nav pilnas datubāzes šifrēšana. Kontaktus iekļauj aizsargātās SQLite rezerves kopijās un piemēro tām iestādes glabāšanas termiņu.

Nākamā migrācija `2026_10_29_000300_harden_question_privacy_tables.php` vienā transakcijā pārveido abas SQLite jautājumu tabulas par `WITHOUT ROWID`, saglabājot visus ierakstus un šifrētās vērtības. Tas noņem slēptos secīgos ievietošanas numurus, ko citādi varētu salīdzināt ar galveno anketu. Pirms migrācijas vajadzīga konsekventa kopija un pietiekama brīva vieta tabulu pārbūvei. Citas datubāzes migrācija nemaina. Tās `down()` apzināti neatjauno ievietošanas secību; iepriekšējā koda versija ar šīm tabulām ir saderīga.

Migrācija `2026_10_29_000400_add_archived_questions_to_surveys.php` pievieno aptaujai servera pārvaldītu agrāko jautājumu definīciju lauku. Tā nemaina atbildes, kontaktus, sesijas vai ģimeņu ierakstus un nepievieno tiem versijas identifikatorus. Pēc migrācijas var labot publicētu aptauju: struktūras maiņa saglabā iepriekšējo jautājumu atskaitēm un kontaktu sarakstiem. Neatgrieziet kodu vai shēmu versijā, kas šo arhīvu ignorē, ja jau saglabātas izmaiņas; citādi agrākās atbildes var pazust no pārskatiem, kaut paliek datubāzē. Atjaunošanai izmantojiet saskaņotu koda un datubāzes kopiju.

Kontaktu API `/a/{slug}/kontakti/{question}` saņem tikai neatkarīgu kontaktu UUID, piekrišanu, šī jautājuma izvēli un konfigurētos kontaktus. Parastais atbilžu API kontaktlaukus nesaņem. Neieslēdziet HTTP pieprasījumu ķermeņu, formas vērtību vai šo modeļu detalizētu reģistrēšanu APM, atkļūdošanas rīkos, Nginx, Cloudflare vai kļūdu žurnālos. Savienojumu laiki un infrastruktūras piekļuve joprojām var dot korelācijas iespējas; atsevišķa glabāšana nav garantija pret šādu novērošanu.

Kontaktinformāciju vecāks ievada vienreiz aptaujas beigās, bet katrai izvēlētajai aktivitātei joprojām sūta atsevišķu pieprasījumu. Kontaktu API izmanto atsevišķu 150 pieprasījumu minūtē budžetu; galvenajai aptaujas iesniegšanai paliek 30. Tas ļauj vienā aptaujā nosūtīt līdz 100 jautājumu kontaktus, neiztērējot galvenās formas budžetu. Pieprasījumu skaita ierobežotāja atslēgā neiekļauj kontaktlauku vērtības vai kontaktu UUID.

Pēc atjaunināšanas atsevišķā pārbaudes aptaujā izmēģiniet piekrišanu un tās noņemšanu, atsevišķu kontaktu pieprasījumu, kļūdas atkārtošanu bez atkārtotas anketas, `<5` kontaktu sarakstu, iestāžu piekļuvi un kontaktu dzēšanu. Iestādes administratoram pēc aktivitātes jādzēš nevajadzīgie kontakti; plānota automātiska kontaktu dzēšana nav ieviesta. Kontakta dzēšana nemaina anonīmās atbildes un tūlīt neizdzēš aizsargātās vēsturiskās rezerves kopijas.
