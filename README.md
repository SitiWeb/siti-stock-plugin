# Siti Stock Plugin (WordPress plugin)

Deze repository bevat de WordPress plugin **Siti Stock Plugin**. De plugincode leeft geheel in deze map en volgt dezelfde ontwikkelworkflow als onze andere Siti plugins, zodat je eenvoudig lokaal kunt bouwen, testen en releasen.

## Functionaliteit

- Houd WooCommerce voorraad synchroon via een extern API-endpoint.
- Stel API-sleutel, standaard voorraadstatus en cron-interval in vanuit het beheerscherm **Siti Stock**.
- Start een sync handmatig vanuit de beheerpagina of via de REST-route `siti-stock/v1/sync`.

## Installatie & gebruik

1. Download de nieuwste release (`siti-stock-plugin-x.y.z.zip`) vanaf GitHub Releases of gebruik het zip-bestand uit `dist/`.
2. Upload het zip-bestand in WordPress via **Plugins → Nieuwe plugin → Plugin uploaden** of plaats de map handmatig in `wp-content/plugins/`.
3. Activeer **Siti Stock Plugin** en configureer eventueel de instellingen onder **Instellingen → Siti Stock**.

## Ontwikkelvereisten

- Docker Desktop of Docker Engine + Docker Compose v2
- Een API-key of andere geheimen plaats je in `.env` (wordt genegeerd door git)

## Lokale ontwikkeling met Docker

1. Start de containers:
   ```bash
   docker compose up --build -d
   ```
2. Doorloop de WordPress installatie op http://localhost:8086.
   - Database host: `db`
   - Database naam: `wordpress`
   - Database gebruiker/wachtwoord: `wordpress`
3. Activeer binnen WordPress de plugin **Siti Stock Plugin** (deze map wordt in de container gemount naar `wp-content/plugins/siti-stock-plugin`).

### Handige commando's

```bash
# Bash in de WordPress container (voor wp-cli of composer)
docker compose exec wordpress bash

# Voorbeeld: lijst plugins met WP-CLI
docker compose exec wordpress wp plugin list

# phpMyAdmin
open http://localhost:8089 (zelfde DB-gegevens als hierboven)

# Containers stoppen
docker compose down
```

## Werken met git

De code blijft op de host staan en wordt alleen als bind-mount gebruikt. Daardoor kun je gewoon lokaal commits maken:

```bash
git status
git add .
git commit -m "Omschrijf je wijziging"
git push origin <branch>
```

## Releasen

De workflow `.github/workflows/release.yml` maakt op basis van de pluginversie automatisch een distributie-zip en GitHub Release aan. Pas vóór een release de `Version` in `siti-stock-plugin.php` aan en zorg dat alle wijzigingen gecommit zijn.
