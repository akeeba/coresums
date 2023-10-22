create table if not exists sources
(
    cms     TEXT default 'joomla' not null,
    version TEXT                  not null,
    url     TEXT                  not null
);