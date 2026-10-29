# Pagaidu Cloudflare saite

Quick Tunnel publicē lokālo lietotni ar HTTPS, kamēr darbojas šis dators, PHP serveris un `cloudflared`. Saite ir pagaidu; tuneļa atkārtota palaišana izveido citu adresi.

Vispirms pabeidziet [lokālo instalāciju](../README.md), izveidojiet savu administratoru ar `php artisan mikausis:admin` un instalējiet [cloudflared](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/). Noklusējuma konta vai kopīga paroles faila repozitorijā nav.

Projekta mapē atveriet divus termināļus. Pirmajā palaidiet PHP serveri:

```powershell
php artisan serve --host=127.0.0.1 --port=9381
```

Otrajā palaidiet tuneli un nokopējiet tā izvadīto HTTPS adresi:

```powershell
cloudflared tunnel --url http://127.0.0.1:9381
```

Savā `.env` iestatiet `APP_DEBUG=false` un `APP_URL` ar iegūto HTTPS adresi. Izpildiet `php artisan config:clear` un pārstartējiet tikai savas instalācijas PHP serveri. Pārbaudiet aptaujas lapu un `/admin/login`; administrācijā izmantojiet savu izveidoto kontu. Repozitorija `MAIL_MAILER=log` piemērs nesūta atjaunošanas e-pastus; to piegādei vajadzīga pasta pakalpojuma konfigurācija.

PHP serveris klausās tikai `127.0.0.1:9381`, un tunelis pārsūta pieprasījumus uz šo adresi. Laravel uzticas proxy galvenēm tikai no `127.0.0.1` un `::1`, lai pareizi ģenerētu HTTPS saites. `.env`, datubāze un žurnāli paliek ārpus publiskās `public` mapes.

Lai apturētu šo piemēra instalāciju, nospiediet `Ctrl+C` abos tai atvērtajos termināļos. Lokālai lietošanai savā `.env` atjaunojiet `APP_URL=http://127.0.0.1:9381`, izpildiet `php artisan config:clear` un atkārtoti palaidiet savu PHP serveri. Nākamajā Quick Tunnel palaišanas reizē atkārtojiet URL iestatīšanu jaunajai adresei.

Pastāvīgai izvietošanai skatiet [Forge instrukciju](forge.md).
