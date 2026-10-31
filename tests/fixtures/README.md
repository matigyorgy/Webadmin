# Isolated test fixtures

Never deploy the SSH fixture to production: it enables a known root password only for local integration testing. Do not publish its SSH port. The image deliberately removes systemctl/systemd-run/journalctl; all game processes and copy jobs use the actual detached Python/PTY implementation.

Build from the repository root:

```sh
docker build -f tests/fixtures/php/Dockerfile -t gamedock-php82-test .
docker build --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt -f tests/fixtures/ssh/Dockerfile -t gamedock-direct-fixture .
```

Create a disposable MySQL 8.4 container and a fresh copy of the application on an isolated network, publishing only the web port on loopback. Install with owner@example.com / Web-test-password-123. Mount a directory containing an empty authorized_keys file at /keys on the SSH fixture. The browser workflow then configures the host using the **web installer**, proving that it installs the actual panel key.

Run the Playwright/Chromium test with `GAMEDOCK_TEST_URL` (loopback only) and `GAMEDOCK_TEST_SSH_HOST` set: `node tests/browser_workflow.cjs`. It creates a node and custom game profile, runs the password-based bootstrap with package installation disabled, creates a template-based server, checks /home/gamedock/ID and a random free 25xxx port, resource-limit persistence, refusal to run limited games without writable cgroups, live usage, and tests start/console/restart/stop and flat mobile layout. Playwright and Chromium are development test tools, not application dependencies.

`tests/fixtures/minecraft_catalog.py` creates a clearly labelled **fake** forty-entry catalog from the test Python game, ten versions of four core Minecraft types. It is not a real Minecraft download. After running it inside the disposable SSH fixture, `tests/browser_minecraft.cjs` tests type/version selection without an EULA checkbox, automatic EULA/port configuration, AJAX controls, offline version switching with world preservation/full backup, template deletion and another random free 25xxx port. The test scripts use automatically generated numeric server identifiers and game names. Catalog parser/checksum/JDK extraction tests are in tests/test_minecraft.py.

Remove all disposable containers, databases, networks and temporary files after testing. Never run these browser workflows against real user data.

The release picker can also be checked without SSH/MySQL or upstream downloads. Render the actual Smarty page, then run the isolated Chromium test (Playwright and Chromium must be installed):

```sh
php tests/fixtures/releases.php > /tmp/gamedock-releases.html
node tests/browser_release_picker.cjs /tmp/gamedock-releases.html
```

This checks game selection, the custom-game version/path POST payload, CS2/CS:S download-button POST payloads, all seven Minecraft types, the ten-version limit, selection resets, late responses, catalog errors/retry, empty catalogs, and that only the install button submits the selected key with CSRF and node ID.

Automation UI (no SSH/MySQL):

```sh
php tests/fixtures/automation.php > /tmp/gamedock-automation.html
node tests/browser_automation.cjs /tmp/gamedock-automation.html
```

After building the disposable SSH image, run the real cron, backup/restore and PTY integration test without publishing any ports:

```sh
docker run --rm --entrypoint /bin/sh -v "$PWD/tests:/tests:ro" gamedock-direct-fixture -ec 'service cron start; python3 /tests/fixtures/automation_integration.py'
```

This fixture test uses only its own server directory and may wait up to 75 seconds for the next cron minute. It verifies offline/confirmation enforcement, snapshot/resume, gzip output, exact restore, corruption rejection, scoped retention, crash recovery and cleanup. Never run it on a real game host.

Pure-FTPd UI fixture:

```sh
php tests/fixtures/ftp.php > /tmp/gamedock-ftp.html
node tests/browser_ftp.cjs /tmp/gamedock-ftp.html
```

For the real FTP fixture, install the official `pure-ftpd` and `openssl` Debian packages in a derivative of the disposable SSH image, then copy/install the current Linux helpers (without `--setup-ftp` during the Docker build). At runtime the test configures its dedicated Pure-FTPd instance on port 21. FTP is unencrypted by request, so credentials and file traffic are visible to anyone who can observe the network. The package requires two extra capabilities inside Docker:

```sh
docker run --rm --cap-add SYS_NICE --cap-add DAC_READ_SEARCH --entrypoint /usr/bin/python3 -v "$PWD/tests:/tests:ro" gamedock-ftp-test /tests/fixtures/ftp_integration.py
```

No ports need to be published. The test may wait up to 75 seconds for the actual cron expiry pass. It checks plain FTP upload/download, chroot isolation, three accounts per server, unique usernames, hashed secrets, successful-login timestamps, maintenance session termination, actual 15-day cron expiry and repeatable setup. `ftp_ssh_setup.py` plus `ftp_ssh.php` additionally exercise the actual phpseclib SSH stdin transport using a disposable key volume shared only by the two test containers; remove the containers and key volume after this test.

Storage tests:

```sh
python3 -m unittest discover -s tests -p 'test_storage.py'
php tests/fixtures/storage.php > /tmp/gamedock-storage.html
node tests/browser_storage.cjs /tmp/gamedock-storage.html
```

The Linux integration check runs against the disposable SSH image and measures a real directory with `du`. Its container uses overlay storage, so it also verifies that a positive quota is refused without changing server configuration or files when project quotas are unavailable. It cannot prove kernel enforcement: that requires a dedicated ext4/XFS test volume mounted with project quotas enabled. Never test by changing quotas on production game directories.

Server list UI (PHP/Smarty render and Chromium required):

```sh
php tests/fixtures/server_list.php > /tmp/gamedock-server-list.html
node tests/browser_server_list.cjs /tmp/gamedock-server-list.html
```

This verifies host/port display (including IPv6), automatic online/offline polling, skipped provisioning servers, and the single management action.
