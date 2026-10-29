@extends('layouts.public')
@section('title', 'Par aptauju privātumu')
@section('content')
<article class="privacy-page">
    <a class="back-link" href="{{ route('surveys.index') }}">← Atpakaļ uz aptaujām</a>
    <h1>Par aptauju privātumu</h1>
    <p class="lead">Pēc noklusējuma aptaujas varat aizpildīt anonīmi, bez vecāka konta.</p>
    <h2>Anonīmas atbildes</h2>
    <p>Ja atzīme “Anonīmi” ir ieslēgta vai aptaujā respondenta datu bloka nav, pašam aptaujas atbilžu ierakstam nepievienojam jūsu vārdu, e-pastu vai citus respondenta datus. Atbilžu ierakstiem nepievienojam IP adresi vai ģimenes identifikatoru.</p>
    <p>Brīvajās atbildēs neiekļaujiet personas datus. Ja aptaujā ir īpaši tiem paredzēti lauki, savus datus varat norādīt tur.</p>
    <h2>Ja vēlaties norādīt savus datus</h2>
    <p>Aptaujas veidotājs var pievienot neobligātu respondenta datu bloku un izvēlēties, ko jautāt, piemēram, vārdu, ģimenes lomu vai kontaktinformāciju. Atzīme “Anonīmi” sākotnēji ir ieslēgta. Datus ievadāt tikai tad, ja pats noņemat šo atzīmi.</p>
    <p>Šādi norādītie dati būs saistīti ar jūsu atbildēm un redzami iestādes administratoram pie katra jautājuma atbildes drukas skatā un lejupielādētajā HTML atskaitē. Tie neparādās publiskajā vietnē vai apkopotajā CSV eksportā. Pēc iesniegšanas atbildes lietotnē vairs nevarēsiet labot.</p>
    <h2>Kontakti tikai vienai aktivitātei vai jautājumam</h2>
    <p>Pie atsevišķiem jautājumiem, piemēram, par dalību pavasara talkā, aptaujas veidotājs var piedāvāt brīvprātīgi atstāt kontaktinformāciju. Pēc noklusējuma atbildat anonīmi un kontaktu atstāšana nav atzīmēta. Aptaujas beigās varat izvēlēties “Atstāt kontaktinformāciju”. Atbilstošās aktivitātes nosaka jūsu atbildes un aptaujas veidotāja nosacījumi; tās parādām informācijai, bez papildu ķeksīšiem.</p>
    <p>Vienādus kontaktlaukus aizpildāt tikai vienreiz. Katrai norādītajai aktivitātei nosūtām tikai tai paredzētos laukus un izvēli attiecīgajā jautājumā. Kontaktinformācija ir saistīta tikai ar šo aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas. Laukus ar atšķirīgu nosaukumu vai tipu neaizpildām cita lauka vietā. Ja kontaktus nevēlaties atstāt, aptauju tik un tā varat iesniegt. Noņemot piekrišanas atzīmi vai mainot atbildes tā, ka mainās kontaktu aktivitātes vai attiecīgās izvēles, pārlūkā ievadītie kontakti tiek notīrīti. Kontaktus var ievadīt no jauna pēc atkārtotas piekrišanas.</p>
    <p>Pēc aptaujas atbilžu iesniegšanas kontaktinformāciju nosūtām atsevišķā pieprasījumā. Bez JavaScript to var atstāt atsevišķā aktivitātes lapā. Iestāde saņem tikai šim jautājumam paredzēto izvēli un kontaktus. Kontaktinformācija, šī jautājuma anonīmā atbilde un pārējās anketas atbildes glabājas atsevišķi, bez kopīga atbildes, ģimenes vai kontaktu identifikatora un bez iesniegšanas laikzīmogiem šajos ierakstos.</p>
    <p>Atkārtoti izmantojamās kontaktu vērtības līdz nosūtīšanai glabājas tikai atvērtās lapas atmiņā, nevis sesijā vai pārlūka pastāvīgajā glabātuvē. Ja tos pašus kontaktus atstājat vairākām aktivitātēm, iestāde var atpazīt vienu cilvēku šo aktivitāšu kontaktu sarakstos. Tas nepievieno sasaisti ar pārējām aptaujas atbildēm.</p>
    <p>Kontakti redzami tikai iestādes administratoram atsevišķā jautājuma kontaktu sarakstā. Tie neparādās anonīmajos rezultātos, CSV, HTML vai PDF atskaitē. Saraksts ir pieejams arī pirms piecām aptaujas atbildēm, jo tā mērķis ir sazināties ar cilvēkiem, kuri paši izvēlējušies atstāt kontaktus. Tas nav dalībnieku identitātes vai aptaujas aizpildīšanas apliecinājums.</p>
    <p>Kontaktus sniedzat ar savu piekrišanu norādītajam aktivitātes mērķim. Lai piekrišanu atsauktu vai lūgtu kontaktu labošanu vai dzēšanu, sazinieties ar iestādi, norādot aktivitāti un savus kontaktus. Administrators var dzēst atsevišķu kontaktierakstu; tas nemaina anonīmās aptaujas atbildes. Iestādei jānosaka glabāšanas termiņš un kontakti jādzēš, kad tie vairs nav vajadzīgi šim mērķim; automātiska dzēšana pēc termiņa nav ieviesta. Dzēstie dati var palikt aizsargātās rezerves kopijās līdz šo kopiju glabāšanas termiņa beigām.</p>
    <p>Vienā aptaujā atsevišķos jautājumu kontaktus nevar apvienot ar visas anketas respondenta datu bloku. Tas novērš nejaušu visas anketas identificēšanu.</p>
    <h2>Ja saņēmāt ģimenes saiti</h2>
    <p>Ģimenes saite ļauj atzīmēt, vai aptauju aizpildījusi mamma, tētis vai cits aizbildnis. Dārziņš var redzēt šo dalības atzīmi. Tā glabājas atsevišķi no atbilžu satura, un atbildēs neierakstām saiti uz ģimeni. Ja brīvprātīgi norādāt savu lomu respondenta datu blokā, tā kļūst arī par daļu no jūsu norādītajiem datiem.</p>
    <p>Kopīga aptaujas saite neapliecina respondenta identitāti. Ģimenes saitē viena loma var iesniegt vienu atbildi, taču pati saite nepārbauda, kurš to izmanto.</p>
    <h2>Apkopojumi un neliels atbilžu skaits</h2>
    <p>Jautājumu rezultātus, atbildes un to HTML eksportu administrācijai rādām, sākot no pieciem iesniegumiem. Tas mazina identificēšanas risku, taču nav absolūta anonimitātes garantija. Īpaši atpazīstams var būt brīvais teksts.</p>
    <p>Iestāde var labot publicētās aptaujas tekstus vai jautājumus. Jau iesniegtās atbildes un kontaktus šāda labošana nepārraksta. Ja mainās jautājuma uzbūve vai tas tiek izņemts no aptaujas, agrākās atbildes un kontaktus saglabā atsevišķi pie iepriekšējā jautājuma. Pie atbilžu ierakstiem nepievienojam kopīgu versijas identifikatoru vai jautājumu parādīšanas laikus.</p>
    <h2>Pārlūka un servera darbība</h2>
    <p>Vietne izmanto sesijas sīkdatni drošai iesniegšanai un atkārtota klikšķa novēršanai. Reklāmas un trešo pušu analītikas rīkus nepievienojam. Servera drošības žurnāli var atsevišķi saturēt tehniskus savienojuma datus; tos nepievienojam aptaujas atbildēm.</p>
    <p>Atsevišķa glabāšana nav absolūtas anonimitātes garantija. Atpazīstams teksts, neliels dalībnieku skaits, savienojuma laiki vai infrastruktūras žurnāli var ļaut izdarīt secinājumus. Kontaktinformāciju un aptaujas atbildes nedrīkst ierakstīt pieprasījumu vai kļūdu žurnālos; servera žurnālu formātu un glabāšanu pārvalda vietnes uzturētājs.</p>
    @include('surveys.partials.source-link', ['explanation' => true])
    <h2>Jautājumi</h2>
    <p>Par aptaujas mērķi, datu glabāšanas laiku vai datu apstrādi sazinieties ar PPII Mikausis: <a href="mailto:mikausis@mikausis.lv">mikausis@mikausis.lv</a>.</p>
</article>
@endsection
