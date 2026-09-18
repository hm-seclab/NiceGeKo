# Configuration Changes

This document used to describe the changes in configuration options since the
major version release.

In server 3.x we now have *all* configuration file handling under `src/Cfg` so
it is quite easy to do a "git diff" to see the exact changes.

Get the source code:

```bash
$ git clone https://codeberg.org/eduVPN/vpn-user-portal
```

Perform a "diff":

```bash
$ git diff 3.0.0 src/Cfg
```

This will show you all changes since the 3.0.0 release. You can of course also
use it for changes _between_ releases, for example:

```bash
$ git diff 3.4.2...3.4.3 src/Cfg
```
