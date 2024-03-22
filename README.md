# EBMS

The PDQ® Editorial Board Management System (EBMS) supports the work of the
U.S. National Cancer Institute to create and maintain comprehensive,
evidence-based, up-to-date cancer content made available in the form of
health professional cancer information summaries as a public service of
the NCI. These published summaries are intended to

- **improve the overall quality of cancer care** by informing and educating
health professionals about the current published evidence related to individual
cancer-related topics and
- **support informed decision making between clinicians and patients**.

The summaries are produced, maintained, and updated regularly by six editorial
boards comprised of oncology specialists in fields including medical, surgical,
and radiation oncology; epidemiology; psychology; genetics; and complementary
and alternative medicine. Each board reviews published research findings on a
monthly basis and meets several times a year to review and discuss updates to
their summaries. The boards are not formal advisory or policy-making boards for
the NCI. The EBMS is used to manage the identification of the newly-published
relevant literature and to track to various stages of the review process.

This version of the EBMS has been rewritten to use Drupal 9.x or later.
The project directory was initialized with the command
`composer create-project drupal/recommended-project ebms4`.
After the new version was deployed to production the old repository
was retired and replaced with the new one (renamed from "ebms4" to "ebms").

This page focuses on setting up `Docker` containers for doing
development work on the EBMS, with non-sensitive dummy data which can
be put under version control. Jenkins will be used for refreshing lower
CBIIT tiers from the production server. The `scripts` directory contains
scripts given to CBIIT for deployment of individual releases.

If at all possible, it is best to be disconnected from the NIH's VPN while
working on your local instance of the EBMS. This is particularly true when
running `composer`, as that VPN is known to block access to required
resources.

## Prerequisites

MacOS is the only supported environment for EBMS development, as using
Docker on Windows is too painful.
You will need `git`, `homebrew`, `php`, `composer`, and `docker`.
For those tools which are not already installed, follow the instructions
here.

### git

Enable the Mac developer tools which will include things like `git`.

```bash
sudo xcodebuild -license
```

### Homebrew

Run the following command to install [Homebrew](https://brew.sh/), which is a
package manager for tools not supplied by Apple.

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### PHP
A recent version of PHP is needed (version 8.1 or higher).

1. Run `brew install php@8.1`. It's OK if you end up with a higher version.
2. Follow any additional instructions on the screen. One of this set is adding PHP to your path. Make sure you do that.
3. Edit `/opt/homebrew/etc/php/8.1/php.ini` or `/usr/local/etc/php/8.1/php.ini` and set `memory_limit = -1` (this removes any memory limits, which `composer install` usually hits. `/opt/homebrew` is the location for newer M1 Macs. If `php --version` shows that you're running a later version, you'll need to adjust the path of the file you're editing accordingly.

If you already have PHP installed, be sure the `php` executable is in your path, and that the `memory_limit` variable is set
as described above.

### Composer

If you have an older Intel-based Mac, run

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --2
```

For the newer Apple silicon (M1) Macs, the command is:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/opt/homebrew/bin --filename=composer --2
```

### Docker

1. Go to https://docs.docker.com/desktop/install/mac-install/
2. Select the right binary for your Mac's architecture
3. Follow the instructions to install.
4. Click on the Docker icon at the top right of your Mac's display
5. Select *Settings*
6. From the menu on the left select *Resources*
7. Make sure *Memory* is at least 6GB
8. Set *Virtual disk limit* to at least 100GB
9. Click **Apply & Restart**

## EBMS Developer Setup

To create a local development environment for this project, perform the following steps.

1. Clone the repository
2. Change current directory to the cloned repository
3. Run `./scripts/create-unversioned-files`
4. Optionally edit the files in the `unversioned` directory
5. Run `chmod +w web/sites/default`
6. Run `composer install`
7. Run `chmod -w web/sites/default`
8. Run `docker compose up -d`
9. Run `docker exec -it ebms-web-1 bash`
10. Inside the container, run `./install.sh`
11. Point your favorite browser (other than Safari, which doesn't recognize subdomains without a certificate) to http://ebms.localhost:8081
12. Log in as admin using the password created in steps 3-4.

## Updated packages

To update Drupal core (for example, when a new version of Drupal is
released to address serious bugs or security vulnerabilities), run

```bash
chmod 777 web/sites/default
composer update drupal/core "drupal/core-*" --with-all-dependencies
chmod 555 web/sites/default
```

Commit the updated `composer.*` files. When other developers pull down
those files, they should run

```bash
composer install
```

If there's any chance that files which should be managed by composer have
been copied into their current locations by other means (for example, using
`rsync`), then you should first remove the entire `vendor` directory, which
will be repopulated by `composer`. This will ensure that `composer`'s picture
of what is installed is accurate, even for files outside the `vendor`
directory.


## Updated Docker configuration

If settings are changed in `docker-compose.yml` or `Dockerfile` you
will need to rebuild the images and containers with

```bash
docker compose up --build -d
```

## Testing

To run the complete set of regression tests, navigate to the base
directory of the project in the web container and execute

```bash
./run-tests.sh
```

## Debugging

The `php-xdebug` package is no longer included in the build of the web container,
because recent versions of Drupal have broken asset aggregation when that package
is installed and enabled. If you need to debug the site, use `apt install php-xdebug`
to install the package and `service apache2 reload` to restart the web server. Be
sure to disable `xdebug` when you have finished debugging.
