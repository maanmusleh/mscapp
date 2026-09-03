# CAT setup guide

## Requirements

- PHP 7.4+ (a supported PHP 8 release is recommended)
- MySQL 8.0.21+ or MariaDB 10.11+
- PHP extensions: `pdo_mysql`, `mbstring`, and `sodium`
- HTTPS

Optional PHP extensions provided by the web host: `zip` and `SimpleXML` are
needed for Excel imports, `zip` is needed for Excel exports, and `gd` is needed
for report-card signature images. 

## Install on web host

1. **Upload a clean CAT release.** It must not contain
   `config/installed.php`. Set the domain or subdomain document root to:

   ```text
   /path/to/CAT/public
   ```

   Then open the domain itself. If your host cannot change the document root,
   place `CAT/` below the public website folder, ensure Apache honours CAT's
   `.htaccess` files (`AllowOverride All`), and open:

   ```text
   https://example.ca/CAT/public/
   ```

   `/CAT/` may correctly return **403 Forbidden**. Nginx hosts must set their
   web root to `CAT/public/`; Nginx does not use `.htaccess`.

2. **Create an empty database and database user** in the hosting control panel.
   Give the user full privileges on that database. Keep the host, port, database
   name, username, and password for the installer.

3. **Set permissions.** PHP must be able to read the application, write to
   `config/` during installation, and continue writing to `tmp/`. Do not use
   permission mode `777`.

4. **Open the website over HTTPS** and complete the first-run form. CAT creates
   the schema, club, administrator, and `config/installed.php` automatically.

5. **After installation**, remove write access from `config/` while keeping
   `config/installed.php` readable by PHP. Keep `tmp/` writable. Confirm that
   direct requests for `config/installed.php` and `createBlankDb.sql` return
   `403` or `404`, then sign in and create a database backup.

   HTTPS installations enable an application-level HTTP-to-HTTPS redirect and
   a one-year HSTS policy. Prefer enforcing the same redirect and HSTS header
   at the hosting proxy or nginx virtual host as well. Enable
   `hsts_include_subdomains` only after confirming every affected subdomain is
   permanently available over HTTPS.

## Upload limits

For the historical Excel importer:

```ini
upload_max_filesize = 12M
post_max_size = 16M
```

For CAT's optional browser database restore:

```ini
upload_max_filesize = 256M
post_max_size = 272M
```

Browser backup and restore also require PHP `proc_open` plus executable
`mysqldump` and `mysql` programs. If the shared host does not provide them, use
its database backup tools instead.

## Common problems

| Problem | Fix |
| --- | --- |
| `403 Forbidden` | Use the domain whose root is `CAT/public/`, or the fallback URL ending in `/CAT/public/`. |
| Database connection fails | Check the hosting provider's database host, port, credentials, and privileges. |
| Database is not empty | Select a new empty database. CAT does not overwrite existing data. |
| Configuration cannot be written | Temporarily give PHP write access to `config/`, retry, then remove write access after success. |
| Upload is rejected | Increase both PHP upload settings above the relevant limit. |

## Upgrade an existing site

Do not run the installer or `createBlankDb.sql` on an existing database.

1. Put the site in maintenance mode and back up both the database and files,
   including `config/installed.php`.
2. Preserve `config/installed.php`, deploy the new release, and apply only the
   missing files in `migrations/`, in numeric version order.
   Release 1.2.15 adds indexes used by database-backed password and
   authenticator throttling.
3. Test sign-in and key features before reopening the site.
