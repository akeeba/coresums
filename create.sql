create table sources
(
    cms     TEXT default 'joomla' not null,
    version TEXT                  not null,
    url     TEXT                  not null
);

create table checksums
(
    cms           text default 'joomla' not null,
    version       text                  not null,
    filename      text                  not null,
    md5           text                  not null,
    sha1          text                  not null,
    sha256        text                  not null,
    sha512        text                  not null,
    md5_squash    text                  not null,
    sha1_squash   text                  not null,
    sha256_squash text                  not null,
    sha512_squash text
);
