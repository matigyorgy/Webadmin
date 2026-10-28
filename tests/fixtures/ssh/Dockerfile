FROM debian:trixie-slim
RUN --mount=type=secret,id=proxy_ca,target=/run/proxy-ca.pem apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem update -qq && apt-get -o Acquire::https::CaInfo=/run/proxy-ca.pem install -y --no-install-recommends openssh-server sudo python3 tmux && rm -rf /var/lib/apt/lists/*
RUN useradd -m -s /bin/bash gamedock && useradd -m -s /bin/bash minecraft && mkdir -p /run/sshd /etc/gamedock /var/lib/gamedock-fixture && echo inactive > /var/lib/gamedock-fixture/state
COPY tests/fixtures/ssh/systemctl /usr/bin/systemctl
COPY tests/fixtures/ssh/systemd-run /usr/bin/systemd-run
COPY tests/fixtures/ssh/journalctl /usr/bin/journalctl
COPY tests/fixtures/ssh/game.py /opt/fixture/game.py
COPY linux/gamedock-launch /usr/local/sbin/gamedock-launch
COPY linux/gamedock-provision /usr/local/sbin/gamedock-provision
COPY linux/gamedock-runner /usr/local/libexec/gamedock-runner
COPY linux/gamedock-console /usr/local/sbin/gamedock-console
COPY tests/fixtures/ssh/servers.json /etc/gamedock/servers.json
COPY tests/fixtures/ssh/sudoers /etc/sudoers.d/gamedock
COPY tests/fixtures/ssh/entrypoint /entrypoint
RUN mkdir -p /srv/games /srv/gamedock/templates/minecraft /srv/gamedock/templates/cs2 /srv/gamedock/templates/fivem /srv/gamedock/templates/css && for game in minecraft cs2 fivem css; do cp /opt/fixture/game.py /srv/gamedock/templates/$game/server.py; chmod 644 /srv/gamedock/templates/$game/server.py; done
RUN chmod 644 /opt/fixture/game.py && chmod 755 /usr/bin/systemctl /usr/bin/systemd-run /usr/bin/journalctl /usr/local/sbin/gamedock-launch /usr/local/sbin/gamedock-provision /usr/local/libexec/gamedock-runner /usr/local/sbin/gamedock-console /entrypoint && chmod 440 /etc/sudoers.d/gamedock
ENTRYPOINT ["/entrypoint"]
