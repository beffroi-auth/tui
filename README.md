# beffroi/tui

The terminal client of the administration of a Beffroi instance, built on [symfony/tui](https://symfony.com/doc/current/tui.html).
The administration of a tenant is an HTTP API of the instance, served apart from its endpoints and its pages and
guarded like them, and a screen or a TUI is one of its clients (design's decision 0038, epic
[beffroi#194](https://github.com/beffroi-auth/beffroi/issues/194)). This is the TUI.

It is the `apps/tui` directory of the monorepo, [beffroi-auth/beffroi](https://github.com/beffroi-auth/beffroi), and
`beffroi-auth/tui` is a mirror of that directory, read only, pushed by git split. It installs none of the packages of the
provider and reads none of its code: it talks to an instance over HTTP, as any client of its provider does, and Deptrac
holds it to that.

## What it is to the provider

A **confidential client of one installation**. `register` draws an ES256 key on the machine it runs on and
registers the client with the public half (RFC 7591), which is how RFC 8252 §8.4 lets a native application be a
confidential client; the private half is kept in a profile, `$XDG_CONFIG_HOME/beffroi/tui/<profile>.json`
(`~/.config/beffroi/tui/` when the variable is not set), in a directory only its owner may open, and a profile the
group or anyone else may read is refused, as ssh refuses a private key.

Every sign-in is held to the client side of FAPI 2.0 Security Profile §5.3.3:

- the metadata of the provider are read from its well-known document, its issuer must be the one registered, and
  a provider that does not announce pushed requests, S256, `private_key_jwt` and DPoP under ES256, the `iss` of RFC
  9207 and the `acr` `phr` is not talked to;
- the request is pushed (RFC 9126), authenticated by `private_key_jwt` with the issuer as its audience, carries a
  PKCE S256 challenge, a state and a nonce drawn for it, `prompt=login`, the `acr` `phr` as an essential claim, and
  the administration API of the instance as its `resource` (RFC 8707 §2);
- the browser of the machine signs the administrator in at the provider, and comes back to the loopback interface,
  `http://127.0.0.1:<port>/callback`, at the port the client registered (RFC 8252 §7.3), where only the response
  carrying the state of the request is taken;
- the response is read issuer first, then state; the code is exchanged with a DPoP proof, and a token that is not
  bound is refused; the ID token is validated (OIDC Core §3.1.3.7), its `acr` included;
- the tokens are bound to a key drawn when the program starts and held in memory only, are never written, and are
  handed back to the provider when the administrator quits or when a step after the exchange fails (RFC 7009);
- the access token is refreshed with the refresh token of the sign-in, by a request that proves the same key, before
  it expires and once more when the administration API refuses it (RFC 6749 §6); the tokens handed back when the
  administrator quits are the last ones held. A deployment that hands its confidential clients no refresh token
  without `offline_access` leaves the session to the lifetime of its access token.

There is no device grant, no `client_credentials` and no shared secret: decision 0038 excludes all three for an
administrator.

## Who may use it

The provider says it, never this program. The audience of the administration API is reserved to the administrators
of the tenant (decision 0038): the provider addresses a token to it only for a member of one of the groups the
deployment names, signed in with a passkey, by a token bound to a key. A person who does not administer the tenant
signs in and is answered `access_denied`, and the screen says what makes an administrator. The deployment of the
instance names the API and the groups:

```bash
BEFFROI_TENANT_ADMINISTRATION_RESOURCE=https://admin.example.com
BEFFROI_TENANT_ADMINISTRATORS='["administrators"]'
```

A local group is created and filled from the console of the instance; a group an upstream provider or SCIM holds
the members of works the same way:

```bash
bin/console beffroi:group:create administrators
bin/console beffroi:group:add-member administrators alice
bin/console beffroi:group:remove-member administrators alice
```

## What it shows

The program opens on a home page and signs the administrator in when asked. The screen is then exactly as tall and as
wide as the terminal, and nothing on it ever pushes it up: a header line names the instance and who signed in, the
last line says the keys that answer where the focus is, and what lies between adapts to the terminal.

- From 90 columns, the sections are a column on the left; under 90, they are tabs above the section shown.
- From 130 columns, the parts of a section stand side by side (a table and what it opens, a person and their
  access, the health and the acts); under 130, one above the other.
- A table shows the rows that fit and says which ones (`1-12 / 40`), keeps the selected row in sight, drops the
  columns that matter least when the width does not hold them and cuts an identifier in its middle; a list of
  definitions folds a long value onto the lines it can spare, lays itself in two columns or says how many it left
  out; the figures of the monitoring are boxed when a third of the height holds them, and written on one line
  otherwise. Under 70 columns the home page drops the symbol.

| Section | What it shows | Read from |
|---|---|---|
| Instance | the issuer, the administration API, the client, its redirect URI and its key, the key the tokens are bound to | the profile and the session |
| Session | who signed in, by which proof (`acr`, `amr`), the scope and the expiry of the access token held | the session |
| Tenant | its name, issuer, login floor, groups of administrators, administration API and how long the journal keeps an act, beside the classes of authentication | `GET /tenant` |
| Clients | a table of the clients, by name, identifier, source and dates; Enter on one opens what it registered beside or under it, with what the registration policy assigned it and what its initial access token gave it; `x` on a client asks to delete it; `i` shows the initial access tokens of the tenant in its place, each with its state, the registrations it served of those it allows, when it expires and whether its clients are clients of the administration, and `c` the clients again; `n` opens the form of a new token, which `s` issues, and `x` on a token asks to revoke it | `GET /clients`, `GET /clients/{client_id}`, `DELETE /clients/{client_id}`, `GET /initial-access-tokens`, `POST /initial-access-tokens`, `DELETE /initial-access-tokens/{id}` |
| Signing keys | a table of the keys, each state marked and worded: `■` active, `□` staged, `▨` retiring, `┄` retired | `GET /keys` |
| Users | a person looked up by username, their claims and groups, beside their credentials; `s` reads their open sessions, `j` their last acts, `a` the applications they agreed to, `c` goes back to the credentials; `x` on a credential asks to revoke it, `x` on an open session asks to end it, `x` on an application asks to take its consent back; a person created from a field of its own; `t` asks to issue the link that lets the person pose a passkey, `p` the one that lets them pose a new password, `o` the one that lets them set up an authenticator app, each shown as a QR code; `e` opens the form of their account, whose changes `s` saves; `d` asks to disable them, which ends every session of theirs, or to enable them again | `GET /users?username=`, `GET /users/{id}/sessions`, `GET /users/{id}/journal`, `GET /users/{id}/consents`, `DELETE /users/{id}/credentials/{credential}`, `DELETE /users/{id}/consents/{consent}`, `DELETE /users/{id}/sessions/{session}`, `POST /users`, `POST /users/{id}/enrolment-ticket`, `POST /users/{id}/password-ticket`, `POST /users/{id}/totp-ticket`, `PATCH /users/{id}`, `PUT /users/{id}/disabled`, `DELETE /users/{id}/disabled` |
| Groups | a table of the groups, by name, source, how many members each holds and whether they administer the tenant; Enter on one reads its members beside or under it; `n` creates a group from a field, `a` adds a member to the group chosen by their username, `x` on a member asks to take them out | `GET /groups`, `GET /groups/{id}/members`, `POST /groups`, `POST /groups/{id}/members`, `DELETE /groups/{id}/members/{person}` |
| Monitoring | figures (people, clients, open sessions, keys, messages waiting), the points `beffroi:doctor` looks at, each `■ ok` or `■ failed` with what it found, and the acts over what the journal keeps as bars, the failures in red | `GET /health`, `GET /statistics` |

The administration API is the one the profile names, read and written with the access token under the DPoP scheme
and a proof made for each request (RFC 9449 §7.1); a nonce it asks for is sent once (§9). A section is read the first time it is
shown, and again when the administrator presses `r`; what it read stays shown while another section is, and its
heading says when it was read. Monitoring is what the instance knows of itself at the moment it is asked (decision
0040): nothing is kept between two reads, nothing is watched, and nothing is exported.

Reading a person, their sessions, their journal or the applications they agreed to delivers that person: each read is an act of their journal which
names the administrator (decisions 0022 and 0038). The Users section says so before anything is read, reads nothing
until the administrator types a username, and reads the sessions, the journal and the applications only when asked. Listing the groups
delivers nobody; reading the members of a group delivers each of them, so the Groups section reads them only when
Enter asks, and says so before.

Revoking a credential or ending an open session is an act of the person too, which names the administrator, and the
applications the session signed in to are told by the back-channel. Each is asked first, with what it would change:
`y` makes the change, and any other key forgets the question. The row then says what the API answered, the moment of
the revocation or the session gone, without reading the person again. A password is replaced, never revoked, and the
section says so rather than asking.

A person is created from the field under the one that looks a person up: Enter creates the username typed, with no
credential, and the section shows the person the API answered, which no read of them follows. `t` asks to issue the
link that lets the person shown pose a passkey, `p` the one that lets them pose a new password without the one in
use, and `o` the one that lets them set up an authenticator app in place of the one they hold (decision 0026): whoever
holds it acts on their account once, until it expires, and a link of the same kind issued for them before opens
nothing any more; spending a password or OTP link ends every session of theirs. Once `y` confirmed it, the link takes the
place of the person, as its text and as a QR code the device that will hold the passkey scans, black on white, or in
reverse video without colours, inside its quiet zone, or, in a terminal too small for it, as the room the code
needs. It leaves the screen with the focus,
and Enter gives the person back. Creating the person and issuing the link are acts of theirs that name the
administrator.

Deleting a client, issuing an initial access token and revoking one are acts of the tenant that name the
administrator. A deletion and a revocation are asked first, like the changes of a person; the row then says what the
API answered. A client the deployment declares, and the one the program acts through, are not deleted, and the section
says why in the words of the instance. `n` on the initial access tokens opens a form beside them: its name, how many
clients it may register, for how many days, whether they are clients of the administration, and the APIs it gives
them, separated by commas. Enter on a row types its value or turns the yes over, and `s` issues the token: its value
then takes the place of the tokens and of the form, once, as wide as the section, to be copied for whoever registers
the client. It leaves the screen when Enter closes it or the focus leaves it, and neither the program nor the instance
can show it again.

`e` opens the form of the account of the person shown in place of their access: a row for each member the API changes,
the username, the email address and the phone number with whether each was verified, the profile of OIDC Core §5.1 and
the members of the postal address, each with the value the person holds. Enter on a row types its value in the field
under the table, an empty one taking the member away, and turns a verification over; the row then says the change is
to save. `s` writes every change at once, as one merge patch of the person (RFC 7396): the person is shown again as the
API answered them, and a refusal is said while the changes stay in the form. The change is an act of the person that
names the administrator, and each value a line of their history. The form leaves the screen with the focus, and what it
had not saved with it.

The colours are the brand's: ultramarine for the header, the focus and the bars, the signal for a point that holds,
and the accent text in the colour the brand gives the background the terminal reports (OSC 11). A terminal that does
not report it keeps plain bold accents and the red of its own palette; `NO_COLOR` draws everything in reverse video
and bold. A state is never said by a colour alone.

| Key | Does |
|---|---|
| ↑ ↓ (← → on tabs) | choose a section, or move in a table |
| Enter | go into the section shown; open the row chosen, look the username up, or create the person typed; close the code of a link or the value of a token; on a group, read its members; in a field of the groups, create the group or add the member typed; in the form of an account or of a token, change the member chosen, then keep the value typed |
| Tab, Shift+Tab | move between the widgets of the section and the navigation |
| Page Up, Page Down, Home, End | move in a table by a screen, or to its ends |
| Esc | go back to the navigation; on the navigation, quit |
| r | read the section shown again (typed as a letter in the username field) |
| x | on a credential, ask to revoke it; on an open session, ask to end it; on an application, ask to take its consent back; on a member of a group, ask to take them out; on a client, ask to delete it; on an initial access token, ask to revoke it |
| t | on a person, ask to issue the link that lets them pose a passkey |
| p | on a person, ask to issue the link that lets them pose a new password |
| o | on a person, ask to issue the link that lets them set up an authenticator app |
| e | on a person, open the form of their account |
| d | on a person, ask to disable them, or to enable them again when they are disabled |
| s | in the form of an account, save what was changed; in the form of a token, issue it |
| n | on the groups, ask for the name of a group to create; on the initial access tokens, open the form of a new one |
| i | on the clients, show the initial access tokens of the tenant in their place |
| c | on a person, show their credentials again; on the initial access tokens, show the clients again |
| a | on a person, read the applications they agreed to; on a group or its members, ask for the username of a member to add |
| y | make the change just asked for; any other key forgets it |
| q | quit (typed as a letter in the username field); Ctrl+C quits from anywhere |

Everything it shows that it did not write, a claim, a member of the metadata, a name a third party registered, what
the administration API answers, reaches the terminal through `TerminalText`, which leaves no escape sequence and no
bidirectional control in it, and a line of the console through `ConsoleText`, which escapes its markup too.

## Getting it

Each release of [beffroi-auth/beffroi](https://github.com/beffroi-auth/beffroi/releases) carries the client among its
assets, built by `.github/workflows/tui.yml` from this directory and its `composer.lock`:

| Asset | Runs on |
|---|---|
| `beffroi-tui.phar` | PHP 8.4 or later, with `dom`, `filter`, `iconv`, `intl`, `libxml`, `mbstring`, `openssl`, `simplexml`, `sodium` and `zlib`; a PHP that lacks one is refused before the client starts, with its name |
| `beffroi-tui-linux-x86_64` | Linux on x86_64, without PHP |
| `beffroi-tui-linux-aarch64` | Linux on arm64, without PHP |
| `beffroi-tui-macos-aarch64` | macOS on Apple silicon, without PHP |

An executable is a static PHP 8.5 followed by that same PHAR. It carries `pcntl` and `posix` as well, through which the
client follows the size of the terminal and opens the browser; the PHAR runs without them, the address of the sign-in
being on the screen. Its OpenSSL trusts the certificate authorities the system keeps under `/etc/ssl` (`cert.pem` or
`certs/`), or the file `SSL_CERT_FILE` names, and `register --cafile` names another for one instance. There is no
Windows build: symfony/tui drives the terminal through `stty`.

`SHA256SUMS` holds the digests of the four, and `SHA256SUMS.asc` is its signature by the key that signs the release
tags:

```bash
gpg --verify SHA256SUMS.asc SHA256SUMS
sha256sum --check --ignore-missing SHA256SUMS            # shasum -a 256 --check --ignore-missing SHA256SUMS on macOS
chmod +x beffroi-tui-linux-x86_64
./beffroi-tui-linux-x86_64 register https://op.example https://admin.example
```

The PHAR is built from a checkout with [Box](https://github.com/box-project/box), whose `box.json` is here:
`composer install --no-dev --classmap-authoritative`, then `box compile`.

## Running it

```bash
php apps/tui/bin/beffroi-tui register https://op.example https://admin.example     # once per machine and instance
php apps/tui/bin/beffroi-tui                                                       # the administration, profile "default"
php apps/tui/bin/beffroi-tui administer --profile staging                          # another profile
```

`register` takes the issuer of the tenant and the identifier of its administration API, the value the deployment
gives `BEFFROI_TENANT_ADMINISTRATION_RESOURCE`: the provider announces the API nowhere, so whoever registers the
program names it, and asks for it when an administrator signs in; the client never registers it among its resources.

An option of `administer` given without the name of the command is written with its value attached,
`--profile=staging`: the console reads the first word that is not an option as the name of a command.

`register` asks for the initial access token the tenant issued for it, or reads it from
`BEFFROI_TUI_INITIAL_ACCESS_TOKEN`, never from the command line. The token is one issued for the administration
(`beffroi:client:token:issue <name> --administration`, decisions 0009 and 0038): the client it registers is given the
administration API, which a client never registers itself. `--port` fixes the port
of the loopback interface, which a free one is taken for otherwise; `--cafile` names a file of certificate
authorities the instance is trusted under, which nobody else may write, and which the development stack needs:

```bash
php apps/tui/bin/beffroi-tui register https://op.localhost https://admin.op.localhost --profile dev --cafile var/caddy-root.crt
php apps/tui/bin/beffroi-tui administer --profile dev
```

The home page shows the symbol, the instance and the profile; Enter opens the sign-in in the browser, and starts it
again after a failure. The administrator signs in with a passkey: a password alone is answered
`unmet_authentication_requirements`. On a machine without a browser, the address of the sign-in is shown, and the loopback port can be forwarded from the
machine where the browser is (`ssh -L <port>:127.0.0.1:<port>`).

The screen speaks English or French, from `LC_ALL`, `LC_MESSAGES` or `LANG`, and draws without colours when
`NO_COLOR` is set (https://no-color.org).
