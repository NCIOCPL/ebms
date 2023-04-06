# EBMS 4.0

This version of the PDQ® Editorial Board Management System has been
rewritten to use Drupal 9.x. The project directory was initialized
with the command `composer create-project drupal/recommended-project
ebms4`. This page focuses on setting up a `Docker` container for doing
development work on the EBMS, with non-sensitive dummy data which can
be put under version control. Jenkins will be used for refreshing lower
CBIIT tiers from the production server the `scripts` directory contains
scripts given to CBIIT for deployment of individual releases.

## Developer Setup

To create a local development environment for this project, perform the following steps. You will need a recent PHP (8.1 is recommended), composer 2.x, and Docker.

1. Clone the repository.
2. Change current directory to the cloned repository.
3. Create a new `unversioned` directory.
4. Run `composer install`.
5. Copy `templates/dburl.example` to `unversioned/dburl`.
6. Create an admin password, copy `templates/adminpw.example` to `unversioned/adminpw`  and put the admin password in the copied file.
7. Create a user password, copy `templates/userpw.example` to `unversioned/userpw`  and put the user password in the copied file.
8. Copy `templates/sitehost.example` to `unversioned/sitehost` and replace the host name if appropriate.
9. Run `docker compose up -d`.
10. Run `docker exec -it ebms-web-1 bash`.
11. Inside the container, run `./install.sh`.
12. Point your favorite browser to http://ebms.localhost:8081.
13. Log in as admin using the password you created in step 5.

## Updated packages.

To update Drupal core (for example, when a new version of Drupal is
released to address serious bugs or security vulnerabilities), run

```bash
chmod 777 web/sites/default
composer update drupal/core "drupal/core-*" --with-all-dependencies
chmod 555 web/sites/default
```

Commit the updated `composer.*` files. When other developers pull down
to those files, they should run

```bash
composer install
```

## Updated Docker configuration

If settings are changed in `docker-compose.yml` or `Dockerfile` you
will need to rebuild the images and containers with

```bash
docker compose up --build
```

## Testing

To run the complete set of regression tests, navigate to the base
directory of the project and run:

```bash
vendor/bin/phpunit web/modules/custom
```

You can run tests for just one module, for example:

```bash
vendor/bin/phpunit web/modules/custom/ebms_review
```

Or even a specific test:

```bash
vendor/bin/phpunit web/modules/custom/ebms_article/tests/src/Kernel/SearchTest.php
```

Until we move to Drupal 10 the tests will trigger annoying bogus deprecation
warnings complaining about an older version of Guzzle which can be ignored.
There is a [ticket](https://www.drupal.org/project/drupal/issues/3281667) filed
with the Drupal issue tracker following the progress of the efforts to deal
with the problem.
