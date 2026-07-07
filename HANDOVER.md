# eSolutions - System Handover & Continuity Guide

This document lets any PHP developer pick up and continue the eSolutions
invoicing system without prior context. It is written so the project is never
dependent on a single person.

## What this is

- **eSolutions** is an invoicing / quoting system for NMP Mobiles.
- It is built on **SolidInvoice 3.0.1**, a mature open-source PHP/Symfony
  invoicing platform (https://github.com/SolidInvoice/SolidInvoice). Because the
  base is open source and widely used, any Symfony/PHP developer can work on it.
- This repository contains the **customised** version (branding, layout and
  feature changes listed below).

## Requirements to run

- **PHP 8.4** (required - the platform uses syntax that needs 8.4)
- **MySQL 8** database
- Standard PHP extensions: intl, gd, soap, xsl, pdo_mysql, zip, mbstring,
  bcmath, opcache, curl, openssl
- Composer (to install the `vendor/` libraries, which are not stored in git)

## Where everything lives

- **Source code:** this repository (`esolutions-nmp`).
- **Live site:** https://esolutions.website (hosted on the client's own cPanel hosting).
- **Database:** MySQL on the same hosting. All business data (invoices, clients,
  users, settings, uploaded logo) lives here - never in this repository.
- **Server config & secrets:** `.env.local`, `config/secrets/`, `config/env/.installed`
  live only on the server and are intentionally NOT in git.

## How to deploy from scratch (new server)

1. `composer install --no-dev` (builds `vendor/`)
2. Point the web server's document root at the `public/` folder.
3. Create a MySQL database and set `SOLIDINVOICE_DATABASE_URL` in `.env.local`:
   `mysql://user:pass@host:3306/dbname?serverVersion=8.0&charset=utf8mb4`
4. Install: `php bin/console doctrine:schema:create` then
   `php bin/console app:install --locale=en --application-url=https://yourdomain ...`
5. Clear cache: `php bin/console cache:clear --env=prod`

(A Docker image that bundles PHP 8.4 and auto-installs is also available on
request - it removes all of the version/extension setup.)

## How to update the live site

Code changes are pushed to this repository. On the live server, updating means
pulling the latest code and clearing the cache:

```
git pull            # or re-sync the code files
php bin/console cache:clear --env=prod
```

Updating code **never** affects the database - invoices, clients and users are
untouched by a code update. (A one-click update mechanism is set up separately so
non-technical staff can update without the command line.)

## Customisations applied (vs stock SolidInvoice)

- Rebranded "SolidInvoice" -> **eSolutions** everywhere; all "Powered by" removed.
- English currency/country, UAE Dirham (AED).
- Email made optional on contacts; phone is the key field.
- Delete removed from invoices/clients/quotes (cancel/archive only).
- **WhatsApp share** button on both invoices and quotes.
- Invoice/quote layout shows Contact Person, Business Name, Address, Contact
  Number, Email; "PENDING" watermark removed from PDFs.
- Logo removed from the sidebar header (company name text kept).
- **Tally Excel stock importer** at `public/tools/tally-importer.php`.
- Reverse-proxy friendly (`config/packages/routing.php` trusts forwarded headers).

## Settings a non-developer can change in the app (no code needed)

- Company name, address, logo: **Settings -> System/Company Settings**
- Outgoing email (SMTP), for invites & password resets: **Settings -> Email**
- Enable public sign-up: set `SOLIDINVOICE_ALLOW_REGISTRATION=true` in `.env.local`

## Continuing the project

- The full source is here and documented, on a standard open-source base, so the
  client's own developer or any PHP freelancer can continue it at any time.
- For larger features (e.g. multi-tenant subscriptions / SaaS mode), SolidInvoice
  ships a built-in SaaS platform mode (`SOLIDINVOICE_PLATFORM=saas`).
