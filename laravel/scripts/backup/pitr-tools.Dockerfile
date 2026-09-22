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
# Build ON THE DATABASE HOST (it needs outbound HTTPS to repo.mysql.com, ~3.3 MB). `sudo sh -c`,
# because /opt/dmc/backup is root-only and the `<` redirect is opened by the calling shell:
#   sudo sh -c 'docker build -t dmc/mysql-pitr:8.4.10 - < /opt/dmc/backup/pitr-tools.Dockerfile'
# Rebuild whenever the production MySQL image changes version (--build-arg MYSQL_VERSION=<new>,
# tag dmc/mysql-pitr:<new>); the build refuses a mismatch. docs/BACKUP-AND-RESTORE.md §10.2.
ARG BASE=mysql:8
FROM ${BASE}
ARG MYSQL_VERSION=8.4.10
ARG ARCH=aarch64
RUN set -eux; \
    # the base must BE the version we are about to extract a tool for
    mysqld --version | grep -q " Ver ${MYSQL_VERSION} "; \
    cd /tmp; \
    rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-mysql; \
    curl -fsSLo client.rpm \
        "https://repo.mysql.com/yum/mysql-8.4-community/el/9/${ARCH}/mysql-community-client-${MYSQL_VERSION}-1.el9.${ARCH}.rpm"; \
    # refuse an unsigned or tampered package
    rpm -K client.rpm | grep -q 'digests signatures OK'; \
    rpm2archive client.rpm; \
    tar -xzf client.rpm.tgz ./usr/bin/mysqlbinlog; \
    install -m 0755 usr/bin/mysqlbinlog /usr/local/bin/mysqlbinlog; \
    rm -rf /tmp/client.rpm /tmp/client.rpm.tgz /tmp/usr; \
    mysqlbinlog --version | grep -q "${MYSQL_VERSION}"
