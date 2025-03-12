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

This page focuses on setting up a `ddev` environment for doing development
work on the EBMS (as recommended by the Drupal community), with non-sensitive
dummy data which can be put under version control. Jenkins will be used for
refreshing lower CBIIT tiers from the production server. The `scripts`
directory contains scripts given to CBIIT for deployment of individual
releases.

If at all possible, it is best to be disconnected from the NIH's VPN while
working on your local instance of the EBMS. This is particularly true when
running `composer`, as that VPN is known to block access to required
resources.

## Prerequisites

MacOS is the only supported environment for EBMS development, as using
Docker on Windows is too painful.
You will need `git`, `homebrew`, `ddev` and `docker`.
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

### Docker

1. Go to [the Docker installer page](https://docs.docker.com/desktop/install/mac-install/)
2. Select the right binary for your Mac's architecture
3. Follow the instructions to install.
4. Click on the Docker icon at the top right of your Mac's display
5. Select *Settings*
6. On the *General* tab set the sharing implementation to *gRPC FUSE*
7. From the menu on the left select *Resources*
8. Make sure *Memory* is at least 6GB
9. Set *Virtual disk limit* to at least 100GB
10. Click **Apply & Restart**

### ddev

Run the following command to install [ddev](https://ddev.com), which is used to
manage Docker-based PHP development environments.

```bash
brew install ddev/ddev/ddev
```

## EBMS Developer Setup

To create a local development environment for this project, perform the following steps.

```bash
git clone git@github.com:NCIOCPL/ebms.git
cd ebms
ddev start
ddev composer install
ddev exec ./install.sh
ddev launch
```

Get the password `unversioned/userpw` and use it to log in as `admin`.

## Updated packages

To update Drupal core (for example, when a new version of Drupal is
released to address serious bugs or security vulnerabilities), run

```bash
chmod 777 web/sites/default
ddev composer update drupal/core "drupal/core-*" --with-all-dependencies
chmod 555 web/sites/default
```

Commit the updated `composer.*` files. When other developers pull down
those files, they should run

```bash
chmod 777 web/sites/default
composer install
chmod 555 web/sites/default
```

If there's any chance that files which should be managed by composer have
been copied into their current locations by other means (for example, using
`rsync`), then you should first remove the entire `vendor` directory, which
will be repopulated by `composer`. This will ensure that `composer`'s picture
of what is installed is accurate, even for files outside the `vendor`
directory.

## Managing the containers

To list the running containers:

```bash
ddev list
```

To stop the probject:

```bash
ddev stop
```

To start the probject's containers:

```bash
ddev stop
```

To stop and restart the probject's containers (useful if things get sluggish
or wonky):

```bash
ddev restart
```

For more information about the containers:

```bash
ddev describe
```

## Testing

To run the complete set of regression tests, navigate to the base
directory of the project in the web container and execute

```bash
ddev exec ./run-tests.sh
```

You can restrict the tests to just one or more modules.

```bash
ddev exec ./run-tests.sh ebms_article ebms_meeting
```

You can even run just a single test script.

```bash
ddev exec ./run-tests.sh ebms_review/tests/src/FunctionalJavascript/QueueTest.php
```

Make sure the sharing implementation for Docker is *gRPC FUSE*. When set to
*VirtioFS* (the default) some of the tests will fail (likely because of a
[permissions issue](https://github.com/docker/for-mac/issues/6614)).
You'll need to check this after every Docker Desktop update, because Docker
has the nasty habit over forgetting the choice you originally made when
moving to the next version.

## Debugging

1. Install the *PHP Debug* extension in Visual Studio Code if it's not
already installed.
2. Set a breakpoint for the code you want to debug (click to the left
of the line number).
3. From the menu, choose *Terminal -> Run Task...* and choose *DDEV:
Enable Xdebug*.
4. From the menu, choose *Run -> Start Debugging*. You may need to select
"Listen for Xdebug" by the green arrowhead at the top left. The bottom
pane of VS Code should now be orange (live) and should say "Listen for
Xdebug."
5. In your browser, navigate to the page you want to debug.
6. Use the navigation buttons in the debugging toolbar to step into or
past each line of code.
7. When you have finished debugging, click the *Stop* button (a red
rectangle) on the debugging toolbar.
8. From the menu, choose *Terminal -> Run Task...* and choose *DDEV:
Disable Xdebug*.

That last step is important, because recent versions of Drupal have
broken asset aggregation when the `php-xdebug` package is installed
and enabled.
