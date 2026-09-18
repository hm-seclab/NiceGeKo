# Changelog

## 3.13.1 (...)
- do not include comments in (admin) API + QR code client configs
- assign at most a `/112` to an OpenVPN process in `singleProcess` mode 
  ([#120](https://codeberg.org/eduVPN/vpn-user-portal/issues/120))
- show node (`vpn-daemon`) version on "Info" page in admin when hovering over
  the _node_
- `last_handshake_time` as provided  by `vpn-daemon` is now only set when it 
  has a value instead of having the `null` value

## 3.13.0 (2026-03-02)
- forgot to enable allowing choosing `it-IT` translation
- allow setting MTU with OpenVPN 
  ([#98](https://codeberg.org/eduVPN/vpn-user-portal/issues/98))
- allow running a single OpenVPN process per profile with OpenVPN >= 2.7 
  ([#115](https://codeberg.org/eduVPN/vpn-user-portal/issues/115))
- do not remove "disabled" accounts with "external authentication" when 
  removing stale accounts 
  ([#117](https://codeberg.org/eduVPN/vpn-user-portal/issues/117))

## 3.12.1 (2026-02-11)
- add `it-IT` translation
- add warning when manually downloading WireGuard+TCP configuration file that 
  the VPN client needs support for ProxyGuard
- fix `db` tool for use with other databases than SQLite 
  ([#114](https://codeberg.org/eduVPN/vpn-user-portal/issues/114))
- do not auto initialize/migrate SQLite database when using `db` tool

## 3.12.0 (2026-01-19)
- add ability to obtain (additional) user permissions from LDAP during 
  user authentication 
  ([#110](https://codeberg.org/eduVPN/vpn-user-portal/issues/110))
- update OAuth server dependency to [8.1.0](https://codeberg.org/fkooman/php-oauth2-server/src/branch/v8/CHANGES.md#8-1-0-2026-01-08)
    - add support for "OAuth 2.0 Form Post Response Mode"
- modernize CSRF protection by using the 
  [Sec-Fetch-Site](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Site) 
  header

## 3.11.0 (2025-12-09)
- Use JSON for WireGuard confguration with Node API `/server_config` response
- update French translation 
  ([#107](https://codeberg.org/eduVPN/vpn-user-portal/pulls/107))

## 3.10.3 (2025-11-12)
- add new OAuth redirect URIs for macOS apps 
  ([#106](https://codeberg.org/eduVPN/vpn-user-portal/issues/106))

## 3.10.2 (2025-11-04)
- fix LDAP authentication with PHP >= 8.1
  ([#105](https://codeberg.org/eduVPN/vpn-user-portal/issues/105))

## 3.10.1 (2025-10-30)
- small fix for encoding HTTP POST parameters for communicating with node(s)

## 3.10.0 (2025-10-30)
- do not warn when DNS search domain is specified with "full tunnel" VPN 
  profile
- improve contrast for "warning" colors
- improve OAuth approval page text
- allow setting `FwMark` for WireGuard interface 
  ([#102](https://codeberg.org/eduVPN/vpn-user-portal/issues/102))
- explain what "Disable Account", "Delete Account" and "Delete Account Data" do
- also add `show_all` query parameter to Admin API `/connection_list` and 
  `/connected_users` endpoints 
  ([#64](https://codeberg.org/eduVPN/vpn-user-portal/issues/64))
- improve/modernize code
    - make classes that are never extended `final`
    - require PHP >= 8.0 (drops support for Debian 11)
    - simplify "Config" classes, no longer use `trait`
    - remove `Dt` class, use `DateTimeImmutable` directly
    - strict verify of data coming from `vpn-daemon`
    - modernize code for PHP >= 8.0 using [rector](https://getrector.com),
      [php-cs-fixer](https://cs.symfony.com/), [psalm](https://psalm.dev/) and 
      [phpstan](https://phpstan.org/)

## 3.9.16 (2025-07-11)
- inform admin when CA is about to expire in the next year 
  ([#97](https://codeberg.org/eduVPN/vpn-user-portal/issues/97))
- show `profile_id` in error when a particular node has no configuration for
  that profile
- enforce successful parsing of the CA certificate when reading it
- make the default (OpenVPN) CA expiry 25 years instead of 10 years for new 
  installations

## 3.9.15 (2025-07-04)
- improve "Connections" page 
  ([#54](https://codeberg.org/eduVPN/vpn-user-portal/issues/54))
    - split information between OpenVPN/WireGuard
    - show number of _allocated_ IPs for WireGuard
- update `nl-NL` translation

## 3.9.14 (2025-06-25)
- support specifying expiry through Admin API create call 
  ([#96](https://codeberg.org/eduVPN/vpn-user-portal/issues/96))

## 3.9.13 (2025-05-07)
- improve logging of `ConnectionManager::disconnectByConnectionId` as behavior 
  change from previous release generated additional log messages on every 
  connect

## 3.9.12 (2025-05-06)
- update `fr-FR` translation 
  ([PR#75](https://codeberg.org/eduVPN/vpn-user-portal/pulls/75))
- handle macOS/iOS app reuse of same WireGuard `public_key` 
  ([#82](https://codeberg.org/eduVPN/vpn-user-portal/issues/82))
- give `vpn-user-portal-status` and `--all` flag to also return "offline" 
  WireGuard peers ([#64](https://codeberg.org/eduVPN/vpn-user-portal/issues/64))
- log any WireGuard `public_key` reuse which does not belong to the same user 

## 3.9.11 (2025-01-13)
- make interval for deleting connection log configurable 
  ([#71](https://codeberg.org/eduVPN/vpn-user-portal/issues/71))
- restore QR code margin to fix Android QR scanning in dark mode 
  ([#69](https://codeberg.org/eduVPN/vpn-user-portal/issues/69))
- do not hide "Sign Out" button, just disable it when not available

## 3.9.10 (2024-12-11)
- simplify [php-saml-sp](https://www.php-saml-sp.eu/) code
- implement "Profile Priority" to sort/order profiles in both portal and apps 
  ([PR#65](https://codeberg.org/eduVPN/vpn-user-portal/pulls/65))

## 3.9.9 (2024-11-25)
- catch `Throwable` instead of `Exception` to catch both `Exception` and 
  `Error`
- work around a bug introduced in PHP 8.2.26, 8.3.14 and 8.4.0 regarding the 
  use of `gmp_pow` ([PHP#16870](https://github.com/php/php-src/issues/16870))
- emit warning when `dnsSearchDomainList` is set, but profile has 
  `defaultGateway` set to `true`

## 3.9.8 (2024-11-12)
- add check for duplicate "Profile IDs" in configuration
- remove trailing `/` of default OAuth Issuer (`iss`)
- show a "banner" when displaying potential VPN configuration issues to make it
  more clear there are problems (`vpn-user-portal-config-check`)
- do not visually overflow WireGuard configuration file on portal configuration
  download page ([#61](https://codeberg.org/eduVPN/vpn-user-portal/issues/61))
- better fit (limit size, center) the QR code on WireGuard configuration 
  download page and add a figure caption

## 3.9.7 (2024-11-04)
- fix flashing page in Firefox with new language selector 
  ([#58](https://codeberg.org/eduVPN/vpn-user-portal/issues/58))
- support JSON files for profile configurations 
  ([#60](https://codeberg.org/eduVPN/vpn-user-portal/issues/60))

## 3.9.6 (2024-10-18)
- implement "dark style" in CSS 
  ([#25](https://codeberg.org/eduVPN/vpn-user-portal/issues/25))
- support URL encoded attribute values with Shibboleth authentication module 
  ([#57](https://codeberg.org/eduVPN/vpn-user-portal/pulls/57))
- redesign the language switcher to use a "drop down" `<select>` box

## 3.9.5 (2024-09-25)
- allow configuring "claim delimiter" for OpenID Connect 
  ([#48](https://codeberg.org/eduVPN/vpn-user-portal/pulls/48))
- add more fields to `status` tool output 
  ([#51](https://codeberg.org/eduVPN/vpn-user-portal/issues/51))
- update `nl-NL` translation

## 3.9.4 (2024-08-16)
- consider `oListenOn` for OpenVPN multi node duplicate ports detection 
  ([#46](https://codeberg.org/eduVPN/vpn-user-portal/issues/46))
- implement simple ping/pong endpoint for Node APIs for testing purposes
- also write errors of background "libexec" scripts to _syslog_
- the "Info" page now shows number of connected VPN clients *per node*

## 3.9.3 (2024-08-12)
- make WireGuard configuration expiry extension opt-in for admin
- show unsupported configuration options for WireGuard on "Info" page
- do not show `<optgroup>` label for profiles without any protocols in 
  `<select>` box

## 3.9.2 (2024-08-09)
- fix `nl-NL` translation for WireGuard UDP/TCP
- make it possible to extend lifetime of WireGuard manual configurations
  ([#44](https://codeberg.org/eduVPN/vpn-user-portal/issues/44))
- show warning symbol in "Active Configurations" table if profile is no longer
  available
- add a "Config Check" that warns on the "Info" page when a VPN profile has no
  protocols at all
- fix error pages if template engine output already started before

## 3.9.1 (2024-08-02)
- fix WireGuard keep-alive support for TCP (only worked for UDP until now) 
  ([#43](https://codeberg.org/eduVPN/vpn-user-portal/issues/43))
- update default "form label" when requesting an additional credential and the
  RADIUS server does not provide the `Reply-Message` attribute

## 3.9.0 (2024-08-01)
- update [RADIUS library](https://codeberg.org/fkooman/php-radius) to 
  [2.0.0](https://codeberg.org/fkooman/php-radius/src/tag/2.0.0/CHANGES.md#2-0-0-2024-08-01)
- implement Challenge/Response for RADIUS 
  ([CB#42](https://codeberg.org/eduVPN/vpn-user-portal/issues/42))
- remove RADIUS PHP extension support (was only supported on Debian 11)
- fix `addRealm` RADIUS configuration option
- allow showing custom HTML on authentication page 
  ([#41](https://codeberg.org/eduVPN/vpn-user-portal/issues/41))

## 3.8.0 (2024-07-05)
- update [RADIUS library](https://codeberg.org/fkooman/php-radius) to 
  [1.0.0](https://codeberg.org/fkooman/php-radius/src/tag/1.0.0/CHANGES.md#1-0-0-2024-07-03)
- implement support for WireGuard client `PersistentKeepalive`
- remove unknown WireGuard peers connected to node(s) when there is no open 
  connection
- implement support for "maintenance mode" where node(s) are no longer 
  considered for new VPN client connections 
  ([CB#36](https://codeberg.org/eduVPN/vpn-user-portal/pulls/36))
- show "Nodes" above "Profiles" on "Info" page

## 3.7.3 (2024-06-26)
- additional fix for protocol selection that affected Debian 11 
  ([CB#35](https://codeberg.org/eduVPN/vpn-user-portal/issues/35))

## 3.7.2 (2024-06-25)
- allow for logging/use by connection script of node number, URL and hostname 
  ([CB#32](https://codeberg.org/eduVPN/vpn-user-portal/issues/32))
- improve "Info" page and only show WireGuard / OpenVPN info when respective 
  protocols are enabled
- show more WireGuard details on "Info" page when WireGuard+TCP is enabled
- log the `user_id` as well when no suitable node can be found for VPN client
  connections
- fix two protocol selection issues 
  ([CB#35](https://codeberg.org/eduVPN/vpn-user-portal/issues/35))

## 3.7.1 (2024-06-12)
- make `iss` configurable (OAuth)
- restore `/` behind `iss` as it breaks some versions of the Android app 
  (>= 3.2.0, <= 3.2.2)

## 3.7.0 (2024-06-10)
- add DB index to `connection_log` table 
  ([CB#17](https://codeberg.org/eduVPN/vpn-user-portal/issues/17))
- improve `db` tool for SQLite 
  ([CB#19](https://codeberg.org/eduVPN/vpn-user-portal/issues/19))
- allow `-p` parameter for `generate-prefix` to specify the IPv4 prefix length
- implement support for DB migration rollback in the `db` tool
- show the DB driver that is being used on "Info" page if it is not 
  SQLite
- implement "Last Used" column for OAuth Authorization on "Account" page 
  ([CB#18](https://codeberg.org/eduVPN/vpn-user-portal/issues/18))
- the OAuth server `iss` response parameter no longer ends in `/` to match 
  common format of `iss`
- show empty table for authorizations instead of no table in "Admin" 
  ([CB#22](https://codeberg.org/eduVPN/vpn-user-portal/issues/22))
- Allow configuring server to only support WireGuard+TCP 
  ([CB#23](https://codeberg.org/eduVPN/vpn-user-portal/issues/23))
- update `fr-FR` translation
- improve styling of (generic) error pages instead of text on white 
  background ([CB#24](https://codeberg.org/eduVPN/vpn-user-portal/issues/24))
- update `da-DK` translation
- filter `USER_ID` when logging user authentications 
  ([CB#28](https://codeberg.org/eduVPN/vpn-user-portal/issues/28))
- small fix for `de-DE` translation
- add CLI tool to verify server configuration 
  ([CB#29](https://codeberg.org/eduVPN/vpn-user-portal/issues/29))
- update `ar-MA` translation

## 3.6.4 (2024-05-21)
- improve "Authorizations" on "Account" page 
  ([#195](https://todo.sr.ht/~eduvpn/server/195))
  - order "Authorizations" in descending order ("Authorized On")
  - add hint about order of authorizations
- small `da-DK` translation update
- improve user authentication logging 
  ([CB#14](https://codeberg.org/eduVPN/vpn-user-portal/issues/14))
- show "Authorized Applications" on user admin page 
  ([CB#15](https://codeberg.org/eduVPN/vpn-user-portal/issues/15))

## 3.6.3 (2024-05-13)
- improve performance of WireGuard IP allocation
  ([CB#11](https://codeberg.org/eduVPN/vpn-user-portal/issues/11))
- update `da-DK` translation
- add `es-ES` translation
- update `es-LA` translation

## 3.6.2 (2024-05-01)
- fix issue in determining protocol to use introduced by WireGuard+TCP support
  ([CB#10](https://codeberg.org/eduVPN/vpn-user-portal/issues/10))

## 3.6.1 (2024-04-24)
- implement exposing CC and Geo URI with connection hooks 
  ([CB#6](https://codeberg.org/eduVPN/vpn-user-portal/issues/6))
- add `lt-LT` (Lithuanian) portal translation 
  ([CB#8](https://codeberg.org/eduVPN/vpn-user-portal/pulls/8))

## 3.6.0 (2024-04-02)
- implement support for WireGuard over TCP/HTTP(S) 
  ([#189](https://todo.sr.ht/~eduvpn/server/189))

## 3.5.8 (2024-03-19)
- fix `AccessHook` restricting access to the service 
  ([CB#3](https://codeberg.org/eduVPN/vpn-user-portal/issues/3))
- fix unable to obtain "permissions" when using OIDC after logout 
  ([CB#3](https://codeberg.org/eduVPN/vpn-user-portal/issues/3))
- allow logout for disabled and unauthorized accounts 
  ([CB#4](https://codeberg.org/eduVPN/vpn-user-portal/issues/4))
- fix bug where disabled accounts were not disabled during first HTTP request 
  after logout/login cycle 
  ([CB#5](https://codeberg.org/eduVPN/vpn-user-portal/issues/5))

## 3.5.7 (2024-03-14)
- allow hiding VPN profiles ([#190](https://todo.sr.ht/~eduvpn/server/190))
- add `tr-TR` portal translation
- make table headers "sticky" when scrolling over tables in the portal
- enhance CLI "account" tool to delete "stale" VPN user accounts
- add CLI tool to calculate and optimize IP prefixes based on 
  including/excluding certain other prefixes
- reduce HTML header size of "Home" page

## 3.5.6 (2024-02-12)
- expose `vpn_proto_transport_list` in API `/info` response
- the last octet of generated IPv4 address will be 0 now (`generate-prefix`)
- add support for permissions based on client source IP 
  ([#146](https://todo.sr.ht/~eduvpn/server/146))

## 3.5.5 (2024-01-19)
- solve OpenVPN split tunnel overlap IP prefix issues for Windows 
  ([#185](https://todo.sr.ht/~eduvpn/server/185))

## 3.5.4 (2024-01-04)
- fix database query with PostgreSQL for enabling/disabling accounts 
  ([#PR2](https://codeberg.org/eduVPN/vpn-user-portal/pulls/2))
- implement "/user_configuration_list" Admin API call 
  ([#180](https://todo.sr.ht/~eduvpn/server/180))
- implement "/delete_user_authorizations" Admin API call 
  ([#184](https://todo.sr.ht/~eduvpn/server/184))

## 3.5.3 (2023-12-12)
- implement "Admin API" call `/connection_list` 
  ([#177](https://todo.sr.ht/~eduvpn/server/177))
- implement "Admin API" call `/delete_connection`
  ([#178](https://todo.sr.ht/~eduvpn/server/178))
- add script to generate random IPv4 and IPv6 prefixes

## 3.5.2 (2023-11-29)
- make it possible to restrict allowed OAuth clients 
  ([#170](https://todo.sr.ht/~eduvpn/server/170))
- add govVPN OAuth client registrations (disabled by default)
- implement `/users` and `/disabled_users` "Admin API" calls 
  ([#171](https://todo.sr.ht/~eduvpn/server/171))
- fix support for per user override of the session expiry
  ([#175](https://todo.sr.ht/~eduvpn/server/175))
- fix "Admin API" response of `/connected_users` call

## 3.5.1 (2023-11-13)
- update `cs-CZ` translation
- update `nl-NL` translation
- small fix in `de-DE` translation
- support resource capabilities for expressing session expiry (AARC-G027)
- implement `/connected_users` "Admin API" call
  ([#165](https://todo.sr.ht/~eduvpn/server/165))
- implement `/disable_user` and `/enable_user` "Admin API" calls
  ([#164](https://todo.sr.ht/~eduvpn/server/164))

## 3.5.0 (2023-10-30)
- improve syslog by allowing custom log format and additional variables 
  ([#162](https://todo.sr.ht/~eduvpn/server/162))
- simplify permission checking
- support permissions that have the attribute name in front of them
- modify authentication modules and static permission source to also include 
  the attribute name in their permissions
- add more unit tests
- make it possible to specify the attribute name for static permissions in the
  JSON file
- show the "Profile ID" on the "Info" page
- verify the `profileId` configuration field syntax
- implement support for "Live Permissions" 
  ([#131](https://todo.sr.ht/~eduvpn/server/131))

## 3.4.4 (2023-10-10)
- remove some unused code
- update translations
- make profile download options translatable

## 3.4.3 (2023-10-09)
- implement TLS support for MariaDB/MySQL 
  ([#159](https://todo.sr.ht/~eduvpn/server/159))
- add DNS search domains to API info response 
  ([#158](https://todo.sr.ht/~eduvpn/server/158))
- remove "Advanced" section for downloading VPN configuration files in portal,
  instead show all available options under "Profile"

## 3.4.2 (2023-10-02)
- use `fkooman/radius` for RADIUS support when the PHP radius extension is not 
  available (PHP >= 8) ([#139](https://todo.sr.ht/~eduvpn/server/139))
- add additional `redirect_uri` for the Android eduVPN/Let's Connect! apps 
  correcting earlier inconsistency
- make clear the CA on the "Info" page is for OpenVPN (only)
- update `uk-UA` translation

## 3.4.1 (2023-09-12)
- add `cs-CZ` translation
- add `sk-SK` translation
- fix two LDAP bugs ([#156](https://todo.sr.ht/~eduvpn/server/156))
  - restore support for `bindDnTemplate` format in common use with Active 
    Directory
  - switch to `ldap_read` when obtaining attributes of a DN instead of 
    performing a subtree search
- allow for writing `authData` to syslog 
  ([#140](https://todo.sr.ht/~eduvpn/server/140))

## 3.4.0 (2023-09-06)
- update `fkooman/oauth2-server` dependency (7.7.0, 7.8.0)
- allow search domains for VPN connections where all traffic is sent over the
  VPN ([#152](https://todo.sr.ht/~eduvpn/server/152))
- Added Catalan (`ca-ES`) translation for the user portal
- show unsupported configuration keys used in the configuration file
  ([#147](https://todo.sr.ht/~eduvpn/server/147))
- remove database query from `DisabledUserHook`
- refactor permission sources
- only fetch static permissions once during a session, not on every page load
- limit allowed OAuth scopes for the VPN clients to only `config`
- `ConnectionManager:oDisconnect` does not use `userId` parameter, remove it
- update translations of "Issues" in various languages
- LDAP improvements
  - always require `userIdAttribute` to be set ([announcement](https://lists.geant.org/sympa/arc/eduvpn-deploy/2023-08/msg00004.html))
  - add TLS configuration for the LDAP client, allow specifying CA, client 
    certificate and key ([#154](https://todo.sr.ht/~eduvpn/server/154))
- **EXPERIMENTAL** support to make it possible to configure WireGuard MTU 
  ([#151](https://todo.sr.ht/~eduvpn/server/151), 
  [documentation](https://docs.eduvpn.org/server/v3/wireguard.html#mtu))

## 3.3.6 (2023-05-23)
- consider allocated WireGuard IPs for alerting 
  ([#134](https://todo.sr.ht/~eduvpn/server/134))
- implement freeing WireGuard IPs for VPN clients that are unresponsive 
  ([#4](https://todo.sr.ht/~eduvpn/server/4))

## 3.3.5 (2023-05-11)
- no longer have a minimum value for `sessionExpiry`, previously it was `PT30M`
- move `StaticPermissionHook` functionality to `UpdateUserInfoHook`
- always fetch static permissions
- consolidate various `Storage::user*` methods
- allow node(s) to specify OpenVPN user/group
  ([#133](https://todo.sr.ht/~eduvpn/server/133))
- fix various static code analysis warnings
- `vpn-user-portal-status` now also shows the number of allocated IP addresses
  for WireGuard (and the number of still free addresses) 
  ([#4](https://todo.sr.ht/~eduvpn/server/4))
- do not show empty array when using `--alert` and `--json` with 
  `vpn-user-portal-status` and there is nothing to alert about

## 3.3.4 (2023-04-25)
- implement support for user specific "Session Expiry" 
  ([#88](https://todo.sr.ht/~eduvpn/server/88))

## 3.3.3 (2023-03-28)
- cleanup VPN protocol selection negotiation 
  ([#128](https://todo.sr.ht/~eduvpn/server/128), 
  [#113](https://todo.sr.ht/~eduvpn/server/113))

## 3.3.2 (2023-03-22)
- make `vpn-user-portal-account --list` also show local users when 
  `DbAuthModule` is used ([#125](https://todo.sr.ht/~eduvpn/server/125))

## 3.3.1 (2023-02-09)
- on "Info" page warn when DNS search domain is not set for a profile, while 
  DNS is provided, but not default gateway 
  ([#120](https://todo.sr.ht/~eduvpn/server/120))
- on "Info" page if DNS is not used in split-tunnel scenario do not warn when 
  DNS traffic is not sent over VPN
- switch to [Argon2id](https://en.wikipedia.org/wiki/Argon2) hashes for 
  local account passwords
- switch to new color palette for "App Usage" on "Stats" page
- show number of users on "Users" page
- expose `created_at` from `Storage::oCertList` and `Storage::wPeerList`
  ([#121](https://todo.sr.ht/~eduvpn/server/121))
- expose the max #available connections per profile on "Connections" page 
  ([#122](https://todo.sr.ht/~eduvpn/server/122))
- make it possible to add additional OAuth API clients 
  ([#119](https://todo.sr.ht/~eduvpn/server/119))
- switch session storage to use JSON instead of PHP serialization
  - this will log everyone out of the portal (if they are currently logged in),
    will NOT affect VPN sessions
- various fixes for issues found by security audit
  - DEC-02-004 WP1: Stored XSS via VPN-configuration display-name (High)
  - DEC-02-006 WP1: Stored XSS via null byte truncation in Radius auth (High)
  - DEC-02-007 WP1: Client disconnection via absent access control (Medium)
  - DEC-02-008 WP1: Bypassing connection threshold with race conditions (Low)
  - DEC-02-001 WP1: Trim function does not HTML-escape short strings (Medium)
  - DEC-02-005 WP3: Unnecessary use of unserialize() for cookie storage (Low)

## 3.3.0 (2023-01-20)
- do not write `syslog` output to `stderr` 
  ([#117](https://todo.sr.ht/~eduvpn/server/117))
- add "#Unique Guest Users" to the last week's "Stats"
- add "#Unique Guest Users" to the "Aggregated Stats"
- "Aggregated Stats" will now contain data starting "yesterday" instead of 
  "one week ago"
- Various database fixes
  - Fix long standing issue with MariaDB/MySQL with "Aggregate Stats" ([#53](https://todo.sr.ht/~eduvpn/server/53))
  - Fix PostgreSQL again with "Aggregate Stats" ([#118](https://todo.sr.ht/~eduvpn/server/118))
  - Add index on `connection_log` table to make generating "Aggregate Stats" 
    fast ([#112](https://todo.sr.ht/~eduvpn/server/112))
  - **NOTE**: a database 
    [migration](https://github.com/eduvpn/documentation/blob/v3/DATABASE.md#database-migration) 
    is necessary. This is done automatically with SQLite. If you switched to 
    using MariaDB/MySQL, or PostgreSQL you MUST do this manually! 

## 3.2.2 (2022-12-22)
- fix for [bug](https://github.com/eduvpn/apple/issues/487) in iOS/macOS app 
  regarding OAuth token refreshing after server upgrade from 2.x to 3.x

## 3.2.1 (2022-12-20)
- fix SQL query for exporting "Aggregate Stats"
- make log of adding/removing peers during sync more informative
- add name of server to aggregate/live stats file downloads

## 3.2.0 (2022-12-16)
- (re)implement tool to generate (reverse) DNS zone files
  ([#25](https://todo.sr.ht/~eduvpn/server/25))
- (re)implement "Static Permissions" for cases where your authentication 
  backend does not (adequately)
  ([#18](https://todo.sr.ht/~eduvpn/server/18)) 
- update for vpn-daemon `/w/remove_peer` changes (v3.0.2)
- add some tests to verify `nodeNumber`, `nodeUrl` and `onNode` profile 
  configuration file
- show `nodeNumber` on Info page for the node(s)
- add `LoggerInterface::debug`
- remove `Tpl::profileIdToDisplayName` "cache"
- refactor connect/disconnect event hooks
- write to `connection_log` table from `ConnectionLogHook`
- make `VPN_PROTO` available on connect/disconnect in `ScriptConnectionHook`
- make `VPN_BYTES_IN` and `VPN_BYTES_OUT` available on disconnect  in 
  `ScriptConnectionHook`
- cleanup "daemon-sync" to make sure the correct connect/disconnect events are
  triggered in all cases
- make "daemon-sync" delete certificates/peers that no longer match the 
  configuration on "apply changes" 
  ([#96](https://todo.sr.ht/~eduvpn/server/96))
- try all nodes when attempting to connect with WireGuard and the first node 
  ran out of free IP addresses ([#110](https://todo.sr.ht/~eduvpn/server/110))
- fix "Aggregate Stats" inefficient `LEFT JOIN` query
  ([#112](https://todo.sr.ht/~eduvpn/server/112))
- sort/group "Aggregate Stats"

## 3.1.7 (2022-11-18)
- fix `ConfigCheck` with DNS template variables 
  ([#107](https://todo.sr.ht/~eduvpn/server/107))
- add network prefix to `AllowedIPs` by default for WireGuard client 
  configuration ([#108](https://todo.sr.ht/~eduvpn/server/108)) 

## 3.1.6 (2022-11-17)
- enforce format of remote user IDs for guest users 
  ([#104](https://todo.sr.ht/~eduvpn/server/104))
- restore `@GW4@` and `@GW6@` template variables for `dnsServerList`
  ([#105](https://todo.sr.ht/~eduvpn/server/105))

## 3.1.5 (2022-11-11)
- fix application stats on "Stats" admin page 
  ([#102](https://todo.sr.ht/~eduvpn/server/102))
- prevent *local* revoked clients from using API in "Guest Usage" scenario 
  ([#103](https://todo.sr.ht/~eduvpn/server/103))

## 3.1.4 (2022-11-08)
- fix OpenVPN special port handling 
  ([#101](https://todo.sr.ht/~eduvpn/server/101))

## 3.1.3 (2022-11-07)
- fix (C) year
- cast `ini_get` return value for `mbstring.func_overload` to bool

## 3.1.2 (2022-11-07)
- make sure `mbstring.func_overload` PHP option is not enabled, show on "Info"
  page if it is
- do proper UTF-8 validation and introduce maximum length of some user provided 
  inputs

## 3.1.1 (2022-11-04)
- verify and trim node keys before allowing them 
  ([#100](https://todo.sr.ht/~eduvpn/server/100))
- fix `nb-NO` translation typo

## 3.1.0 (2022-10-24)
- fix warning message for non-https node URL 
  ([#93](https://todo.sr.ht/~eduvpn/server/93))
- update `nl-NL` translation
- update for `fkooman/oauth2-server` 7.1
- introduce `ApiUserInfo` that wraps the OAuth access token
- enable `iss` query parameter support for OAuth callbacks with 
  `fkooman/oauth2-server` 7.2 ([#91](https://todo.sr.ht/~eduvpn/server/91))
- implement 
  [Guest Access](https://github.com/eduvpn/documentation/blob/v3/GUEST_ACCESS.md) 
  support ([#17](https://todo.sr.ht/~eduvpn/server/17))
  - implement `HmacUserIdHook` to obscure user IDs 
    ([#89](https://todo.sr.ht/~eduvpn/server/89))
  - add [minisign](https://jedisct1.github.io/minisign/) compatible 
    signature verifier
  
## 3.0.6 (2022-09-19)
- [PREVIEW](https://github.com/eduvpn/documentation/blob/v3/PREVIEW_FEATURES.md): 
  implement "Admin API" support ([#16](https://todo.sr.ht/~eduvpn/server/16))
- fix multi node deployments when profile is not installed on all nodes 
  ([#90](https://todo.sr.ht/~eduvpn/server/90))
- simplify `.well-known` handling code in development setup
- add additional `ProfileConfig` tests
- add simple shell script client `dev/api_client.sh` for API testing /
  development

## 3.0.5 (2022-08-15)
- [PREVIEW](https://github.com/eduvpn/documentation/blob/v3/PREVIEW_FEATURES.md): 
  add support for deleting authorization on APIv3 disconnect call 
  ([#78](https://todo.sr.ht/~eduvpn/server/78))

## 3.0.4 (2022-08-03)
- fix handling optional `oListenOn` in multi node setups 
  ([#85](https://todo.sr.ht/~eduvpn/server/85))
- implement `ConnectionHookInterface` to allow for plugins to respond to client
  connect/disconnect events ([#82](https://todo.sr.ht/~eduvpn/server/82))
- re-implement the _syslog_ connection logger on top of 
  `ConnectionHookInterface`
- implement `--list` option for `vpn-user-portal-account` to list user accounts
- [PREVIEW](https://github.com/eduvpn/documentation/blob/v3/PREVIEW_FEATURES.md): 
  add support for running script/command on client connect/disconnect 
  ([#84](https://todo.sr.ht/~eduvpn/server/84))
  
## 3.0.3 (2022-07-27)
- proper logging of authentication failures for local database, LDAP and RADIUS

## 3.0.2 (2022-07-25)
- add Portal URL to manually downloaded configuration file ([#81](https://todo.sr.ht/~eduvpn/server/81))
- update `ar-MA` translation
- require userIdAttribute to be set in LDAP response when requesting it to be
  used ([#83](https://todo.sr.ht/~eduvpn/server/83))

## 3.0.1 (2022-06-08)
- make `oListenOn` accept multiple values 
  ([#75](https://todo.sr.ht/~eduvpn/server/75))
- update `pt-PT` translation
- update `ar-MA` translation

## 3.0.0 (2022-05-18)
- initial 3.x release
