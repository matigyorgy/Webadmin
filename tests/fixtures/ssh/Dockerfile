FROM debian:trixie-slim
RUN --mount=type=secret,id=proxy_ca,target=/run/proxy-ca.pem apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem update -qq && apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem install -y --no-install-recommends openssh-server sudo python3 cron tzdata && rm -rf /var/lib/apt/lists/*
RUN useradd -m -s /bin/bash gamedock && useradd -m -s /bin/bash minecraft && mkdir -p /run/sshd /etc/gamedock /home/gamedock/templates/custom /home/gamedock && chown root:root /home/gamedock && chmod 755 /home/gamedock && echo 'root:Fixture-root-password-123' | chpasswd && printf '\nPermitRootLogin yes\nPasswordAuthentication yes\n' >> /etc/ssh/sshd_config
COPY linux /release/linux
RUN python3 /release/linux/install.py --source-root /home/gamedock/templates --target-root /home/gamedock && touch /etc/gamedock/test-fixture && rm -f /usr/bin/systemctl /usr/bin/systemd-run /usr/bin/journalctl
COPY tests/fixtures/ssh/game.py /home/gamedock/templates/custom/server.py
RUN chmod 644 /home/gamedock/templates/custom/server.py
COPY tests/fixtures/ssh/entrypoint /entrypoint
RUN chmod 755 /entrypoint
ENTRYPOINT ["/entrypoint"]
