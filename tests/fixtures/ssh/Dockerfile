FROM debian:trixie-slim
RUN --mount=type=secret,id=proxy_ca,target=/run/proxy-ca.pem apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem update -qq && apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem install -y --no-install-recommends openssh-server sudo python3 cron tzdata && rm -rf /var/lib/apt/lists/*
RUN useradd -m -s /bin/bash webadmin && useradd -m -s /bin/bash minecraft && mkdir -p /run/sshd /etc/webadmin /home/webadmin/templates/custom && echo 'root:Fixture-root-password-123' | chpasswd && printf '\nPermitRootLogin yes\nPasswordAuthentication yes\n' >> /etc/ssh/sshd_config
COPY linux /release/linux
RUN python3 /release/linux/install.py --source-root /home/webadmin/templates --target-root /home/webadmin/servers && touch /etc/webadmin/test-fixture && rm -f /usr/bin/systemctl /usr/bin/systemd-run /usr/bin/journalctl
COPY tests/fixtures/ssh/game.py /home/webadmin/templates/custom/server.py
RUN chmod 644 /home/webadmin/templates/custom/server.py
COPY tests/fixtures/ssh/entrypoint /entrypoint
RUN chmod 755 /entrypoint
ENTRYPOINT ["/entrypoint"]
