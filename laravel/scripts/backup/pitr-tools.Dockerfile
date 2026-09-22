# Point-in-time recovery tooling — the production MySQL server image plus the ONE tool it lacks.
#
# The official mysql:8 image (MySQL 8.4) ships mysql, mysqldump, mysqladmin and mysqlsh but NOT
# mysqlbinlog, which the recovery procedure (docs/BACKUP-AND-RESTORE.md §10.5) cannot work without.
# The 2026-09-22 rehearsal found this the hard way: the runbook said "the server image ships it",
# and the replay step died with "mysqlbinlog: command not found". Installing the full client
# package alongside the image's server-minimal package would conflict, so this extracts exactly the
# one binary from the official client package of the SAME version, after verifying the package
# signature against MySQL's key that the server image itself ships. The tool that reads the
# binlogs therefore matches the server that wrote them, byte for byte in version.
#
# The version is deliberately NOT pinned in this file: the build reads it out of the base image and
# fetches the matching client package. It WAS pinned until 2026-09-22, and the whole-server-loss
# rehearsal that day could not build the image at all — `mysql:8` had moved to 8.4.11 while the pin
# still said 8.4.10. A stale pin that only bites during a real recovery is the worst kind.
# Pass --build-arg PITR_MYSQL_VERSION=x.y.z to force a specific one (it is checked against the base).
# NB the arg is not called MYSQL_VERSION: the base image exports an ENV of that name ("8.4.11-1.el9",
# with the RPM release suffix), and an inherited ENV shadows an ARG default of the same name.
#
# Build ON THE DATABASE HOST (it needs outbound HTTPS to repo.mysql.com, ~3.3 MB). `sudo sh -c`,
# because /opt/dmc/backup is root-only and the `<` redirect is opened by the calling shell:
#   V=$(sudo docker run --rm mysql:8 mysqld --version | sed -n 's/.* Ver \([0-9.]*\).*/\1/p')
#   sudo sh -c "docker build -t dmc/mysql-pitr:$V - < /opt/dmc/backup/pitr-tools.Dockerfile"
# docs/BACKUP-AND-RESTORE.md §10.2.
ARG BASE=mysql:8
FROM ${BASE}
# empty = derive from the base image; set it only to pin deliberately
ARG PITR_MYSQL_VERSION=
ARG ARCH=aarch64
RUN set -eux; \
    # the tool must match the server that wrote the logs, so take the version FROM the base image
    # (mysqld prints e.g. "Ver 8.4.11-1.el9 for Linux"); an explicit PITR_MYSQL_VERSION is checked
    # against the base rather than trusted.
    BASE_VERSION="$(mysqld --version | sed -n 's/.* Ver \([0-9][0-9.]*\).*/\1/p')"; \
    test -n "${BASE_VERSION}"; \
    VERSION="${PITR_MYSQL_VERSION:-$BASE_VERSION}"; \
    test "${VERSION}" = "${BASE_VERSION}"; \
    cd /tmp; \
    rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql; \
    curl -fsSLo client.rpm \
        "https://repo.mysql.com/yum/mysql-8.4-community/el/9/${ARCH}/mysql-community-client-${VERSION}-1.el9.${ARCH}.rpm"; \
    # refuse an unsigned or tampered package
    rpm -K client.rpm | grep -q 'digests signatures OK'; \
    rpm2archive client.rpm; \
    tar -xzf client.rpm.tgz ./usr/bin/mysqlbinlog; \
    install -m 0755 usr/bin/mysqlbinlog /usr/local/bin/mysqlbinlog; \
    rm -rf /tmp/client.rpm /tmp/client.rpm.tgz /tmp/usr; \
    mysqlbinlog --version | grep -q "${VERSION}"
