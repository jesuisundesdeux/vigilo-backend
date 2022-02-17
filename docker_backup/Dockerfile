FROM alpine

RUN apk add --no-cache mysql-client

COPY mysql_dump /etc/periodic/daily
COPY save_files /etc/periodic/daily

RUN mkdir /backup

ENV RETENTION 7

CMD [ "/usr/sbin/crond", "-f", "-d8" ]

