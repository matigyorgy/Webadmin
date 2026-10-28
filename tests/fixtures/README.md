# Isolated integration fixtures

These are test images, not production deployment instructions. Never use the SSH fixture on a real game server. Its systemctl/journalctl commands are controlled substitutes.

Build from the repository root:

```sh
docker build -f tests/fixtures/php/Dockerfile -t gamedock-php82-test .
docker build --secret id=proxy_ca,src=/etc/ssl/certs/ca-certificates.crt -f tests/fixtures/ssh/Dockerfile -t gamedock-ssh-fixture .
```

The SSH build uses the supplied combined trust bundle for package downloads. Keep proxy configuration and TLS/package verification enabled. The container generates its own host keys during package installation; no private keys are checked in.

For tests, create an isolated Docker network, a disposable MySQL 8.4 container and a fresh copy of the application served by the PHP image on a loopback-only port. Do not mount your production config. Install that copy with owner@example.com / Web-test-password-123 against the disposable database. Export its generated **public** key to a temporary file named authorized_keys and mount its directory read-only at /keys in the SSH fixture. Run the SSH container on the same internal test network without publishing its SSH port.

Read the SSH host fingerprint from the fixture's trusted local console with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256`. Set GAMEDOCK_TEST_URL to the panel's loopback URL, GAMEDOCK_TEST_SSH_HOST to the SSH container name, and GAMEDOCK_TEST_SSH_FINGERPRINT to the SHA256 value. Run `python3 tests/http_workflow.py` from the host. The systemctl fixture must initially be inactive and the application database freshly installed. Stop and remove all fixture containers, test databases, temporary key files and the test network afterward.

The test creates users/nodes/servers, performs power and console operations and changes passwords. It is intentionally restricted to loopback URLs. Real games, Steam/FiveM downloads and game networking are outside this test.
