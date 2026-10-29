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
