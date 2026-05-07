<table class="validations">
    <thead>
        <tr>
            <th colspan="2" scope="col" style="text-align: center; background-color: silver; color: black;">Code quality</th>
            <th scope="col" style="text-align: center; background-color: silver; color: black;">Translation status</th>
            <th scope="col" style="text-align: center; background-color: silver; color: black;">OpenAPI validation</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <th style="text-align: center; background-color: lightgray; color: black;" scope="row">
                <a href="https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/tree/stable">stable branch</a>
            </th>
            <td style="text-align:center; background-color: whitesmoke;">
                <a href="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/overview/stable">
                    <img src="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/badge/stable" title="CodeFactor" alt="CodeFactor" />
                </a>
            </td>
            <td rowspan="2" style="text-align:center; background-color: whitesmoke;">
                <a href="https://translate.johnromanodorazio.com/engage/liturgical-calendar/">
                    <img src="https://translate.johnromanodorazio.com/widgets/liturgical-calendar/-/287x66-white.png" alt="Translation status" />
                </a>
            </td>
            <td style="text-align:center; background-color: whitesmoke;">
                <a href="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/stable/jsondata/schemas/openapi.json">
                    <img src="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/stable/jsondata/schemas/openapi.json"
                         alt="OpenAPI validation result" />
                </a>
            </td>
        </tr>
        <tr>
            <th style="text-align: center; background-color: lightgray; color: black;" scope="row">
                <a href="https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/tree/development">dev branch</a>
            </th>
            <td style="text-align: center; background-color: whitesmoke;">
                <a href="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/overview/development">
                    <img src="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/badge/development" title="CodeFactor" alt="CodeFactor" />
                </a>
            </td>
            <td style="text-align: center; background-color: whitesmoke;">
                <a href="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/development/jsondata/schemas/openapi.json">
                    <img src="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/development/jsondata/schemas/openapi.json"
                         alt="OpenAPI validation result" />
                </a>
            </td>
        </tr>
    </tbody>
</table>

![Codesniffer PHPStan POTs update](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/actions/workflows/main.yml/badge.svg?branch=development)
![PHPStan level](https://img.shields.io/badge/phpstan-level%2010-brightgreen?style=flat-square&logo=php "PHPStan level 10")
![PHPUnit](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/actions/workflows/phpunit.yml/badge.svg?branch=development)
[![codecov](https://codecov.io/gh/Liturgical-Calendar/LiturgicalCalendarAPI/graph/badge.svg?token=I6IUMRJ30M)](https://codecov.io/gh/Liturgical-Calendar/LiturgicalCalendarAPI)
![CodeRabbit Pull Request Reviews](https://img.shields.io/coderabbit/prs/github/Liturgical-Calendar/LiturgicalCalendarAPI?utm_source=oss&utm_medium=github&utm_campaign=Liturgical-Calendar%2FLiturgicalCalendarAPI&labelColor=171717&color=FF570A&link=https%3A%2F%2Fcoderabbit.ai&label=CodeRabbit+Reviews)

# Liturgical Calendar

An API (PSR‑7/15/17 compliant) written in PHP that will generate the liturgical calendar for any given year, based on the General Roman Calendar,
calculating the mobile festivities and the precedence of solemnities, feasts, memorials...
Can also produce calendar data for nations, dioceses, or groups of dioceses.
This calendar data can be served in various formats such as JSON, YAML, XML, or ICS.
More information [on the website](https://litcal.johnromanodorazio.com/).

OpenAPI documentation [can be found here](https://litcal.johnromanodorazio.com/dist/) (kudos to @MichaelRShelton for generating the docs from the Swagger docker image).

The API is packaged as a composer library: run `composer install` to setup the autoload functionality.

Some characteristics of this API:

* **The data is based on official sources**, not copied from random internet sources.
  Sources used are the various editions of the **Roman Missal** in Latin, English, and Italian, **Magisterial documents**,
  and the **Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments**
  * Missale Romanum, Editio typica, 1970
  * Missale Romanum, Reimpressio emendata, 1971
  * Missale Romanum, Editio typica secunda, 1975
  * Missale Romanum, Editio typica tertia, 2002
  * Missale Romanum, Editio typica tertia emendata, 2008
  * [Mysterii Paschalis, PAULUS PP. VI, 1969](http://www.vatican.va/content/paul-vi/la/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html)
  * [Decrees of the Dicastery for Divine Worship](https://www.vatican.va/roman_curia/congregations/ccdds/index_it.htm)
* **The data is historically accurate**, *i.e.* the liturgical calendar produced for the year 1979 will reflect the calendar as it was in that year,
  and not as it would be today (obviously future years will reflect the calendar as it is generated in the current year;
  as new decrees are issued by the Dicastery for Divine Worship and the Discipline of the Sacraments or new editions of the Roman Missal are published,
  the script will need to be updated to account for any new criteria)

# Example applications

There are a few proof of concept example applications for usage of the API at [LitCal Usage](https://litcal.johnromanodorazio.com/usage.php),
which demonstrate generating an HTML representation of the Liturgical Calendar.

* The [first example](https://litcal.johnromanodorazio.com/examples.php?example=PHP) uses cURL in PHP to make a request to the endpoint and handle the results.
* The [second example](https://litcal.johnromanodorazio.com/examples.php?example=JavaScript) uses `fetch` in Javascript to make the request to the endpoint and handle the results.
* The [third example](https://litcal.johnromanodorazio.com/examples.php?example=FullCalendar) makes use of the [FullCalendar javascript framework](https://github.com/fullcalendar/fullcalendar)
  to display the results from the `fetch` request in a nicely formatted calendar view.
* The [fourth example](https://litcal.johnromanodorazio.com/examples.php?example=FullCalendarMessages) is the same as the third
  except that it outputs the Messages first and the [FullCalendar](https://github.com/fullcalendar/fullcalendar) calendar view after.

All of these examples request `JSON` as the data exchange format generated by the endpoint.
Any application could use the endpoint in a similar manner: an Android App, a plugin for a Desktop Publishing App...

## Using the endpoint as a calendar URL for Calendar Apps

*(See [usage.php#calSubscription](https://litcal.johnromanodorazio.com/usage.php#calSubscription "https://litcal.johnromanodorazio.com/usage.php#calSubscription").)*

* **GOOGLE CALENDAR ON A DESKTOP COMPUTER**: you can only *add a calendar by URL* using Google Calendar on a computer, I don't believe it is possible from smartphone / Android devices.
  At the bottom left corner of the screen, next to **`Other calendars`**, click on the **`+`** to add a new calendar and choose **`From URL`**.
  Paste in the URL of the endpoint with the desired parameters, (make sure you use **`ICS`** as value of the *`return_type`* parameter).
  And remember, if you omit the *`year`* parameter, it will use the current year.
  This should mean that as Google Calendar continues to poll the calendar URL (supposedly every 8 hours),
  on the turn of a new year new events should be created automatically for the new year.
  Once the calendar has been added from a computer, it should become available for the same gmail account on the Google Calendar app on a smartphone.
* **CALENDAR APPS ON AN ANDROID DEVICE**: after you have *added a calendar by URL* in your Google Calendar on a Desktop Computer,
  you should then find that calendar synchronized with your Google account,
  so the calendar should become available to any Android Calendar apps that have access to your Google account to synchronize calendars.
* **IPHONE**: go to **`Phone Settings`** -> **`Accounts`** -> **`Add account`** -> **`Other`** -> **`Add Calendar`**, and paste in the endpoint URL with the desired parameters,
  (make sure you use **`ICS`** as value of the *`return_type`* parameter). And remember, if you omit the *`year`* parameter, it will use the current year.
  This should mean that as the iPhone Calendar continues to poll the calendar URL, on the turn of a new year new events should be created automatically for the new year.
* **MICROSOFT OUTLOOK** *(tested with Outlook 2013)*: at the bottom of the screen, switch from **`Email`** view to **`Calendar`** view.
  On the ribbon of the **`Home`** menu item, click on **`Open calendar`** -> **`From the internet`**.
  Paste the endpoint URL with the desired parameters, (make sure you use **`ICS`** as value of the *`return_type`* parameter).
  And remember, if you omit the *`year`* parameter, it will use the current year.
  On the following screen, check the checkbox along the lines of "Poll this calendar in the interval suggested by the creator",
  which would mean that Outlook Calendar should poll the calendar URL once a day.
  This means that without the *`year`* parameter, on the turn of a new year new events should be created automatically for the new year.
  Make sure the Calendar is created in the **`Other calendars`** folder;
  if you find it under the **`Personal calendars`** folder, drag it and drop it onto the **`Other calendars`** folder,
  this should ensure that it is treated as a subscription internet calendar.
  You can manually trigger an update against the calendar URL by clicking on **`Send/receive all`** (from the **`SEND/RECEIVE`** menu item).
  One highlight of the calendar in Outlook is that it supports a minimal amount of HTML in the event description,
  so the event descriptions in the Liturgical Calendar are a little bit more "beautified" for Outlook.

# Testing locally

System requirements:

* PHP >= 8.4 (we make use of more modern PHP functions such as `array_find`)
* PHP modules installed and enabled: `intl` • `zip` • `calendar` • `yaml` • `gettext`
* System package `gettext` and language packs for all the supported languages
* PHP module `apcu` is optional and currently under testing.
  If enabled, it is also possible to test usage for the WebSocket server by setting `apc.enable_cli=1` in `php.ini`.

## Using PHP's built-in server

To test the API locally, you can use PHP's built-in server.
However, you will need to spawn at least a couple of workers, since some routes will make a request internally to another route.
For example, a request to the `/calendar` route will make a request internally to the `/calendars` route.
To be on the safe side, you should spawn up to 6 workers.

```bash
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8000 -t public
```

For convenience when using VSCode, a `tasks.json` has been defined so that you can simply type <kbd>CTRL</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd>
(<kbd>CMD</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd> on macOS) to start the PHP built-in server and open the browser
(`litcal-api-with-browser`, or `api-server-no-browser` to just start the server without opening the browser).

The `composer.json` file also defines a couple scripts to simplify this process:

* `composer start`: spawns six workers via `start-server.sh`
* `composer stop`: stops the server via `stop-server.sh`

You can also use the `start-server.sh` and `stop-server.sh` scripts directly to spawn and stop the server. Please ensure that both scripts are executable (`chmod +x`).
The **start** script writes the server PID to `server.pid` in the current directory,
and the **stop** script terminates the process by its PID and removes `server.pid`.

## Using a docker container

To further simplify your setup, without having to worry about getting all the system requirements in place, you can also launch the API in a docker container using the repo `Dockerfile`:

```bash
# If you haven't cloned the repo locally, you can build directly from the remote repo (replace `{branch}` with the branch or tag from which you want to build):
DOCKER_BUILDKIT=1 docker build -t liturgy-api:{branch} https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI.git#{branch}
# If instead you have cloned the repo locally, you can build from the local repo (replace `{branch}` with the branch or tag that you have checked out locally):
DOCKER_BUILDKIT=1 docker build -t liturgy-api:{branch} .
docker run -p 8000:8000 -d liturgy-api:{branch}
```

This typically results in a Docker image of ~1.1 GB (subject to change). Unfortunately this cannot be reduced by means of an alpine image,
if we want to install system locales in order for `gettext` to work properly with all supported languages.

# Configuration

Environment variables should be set in a `.env` or `.env.local` file.
You can copy the `.env.example` file to `.env` or `.env.local` (or `.env.development`, `.env.test`, `.env.staging`, `.env.production`) and edit it as needed.
The defaults are suitable for development and testing, but may need to be overridden for staging or production environments.

## General Settings

* `APP_ENV`: Application environment (**required in non-localhost environments**). Must be one of: `development`, `test`, `staging`, `production`

## API Server Configuration

* `API_PROTOCOL`: The protocol to use for the API (default: `http`). Example: `API_PROTOCOL=https`
* `API_HOST`: The hostname or IP address to use for the API (default: `localhost`). Example: `API_HOST=mydomain.com`
* `API_PORT`: The port to use for the API (default: `8000`). Example: `API_PORT=8080`
* `API_BASE_PATH`: The base path to use for the API (empty by default for local development). Example: `API_BASE_PATH=/api/v1/` for production

## JWT Authentication Configuration

The API supports JWT authentication for protected write operations. Configure the following environment variables:

* `JWT_SECRET`: Secret key for signing tokens (minimum 32 characters). Generate with: `php -r "echo bin2hex(random_bytes(32));"`
* `JWT_ALGORITHM`: Algorithm for signing tokens (default: `HS256`)
* `JWT_EXPIRY`: Access token expiry in seconds (default: `3600` = 1 hour)
* `JWT_REFRESH_EXPIRY`: Refresh token expiry in seconds (default: `604800` = 7 days)
* `ADMIN_USERNAME`: Admin username for authentication (default: `admin`)
* `ADMIN_PASSWORD_HASH`: Argon2id password hash. Generate with: `php -r "echo password_hash('yourpassword', PASSWORD_ARGON2ID);"`

**Environment-Specific Security Behavior:**

The API implements fail-closed authentication that requires `APP_ENV` to be explicitly set:

* **`development`** and **`test`**: Allow default password (`password`) if `ADMIN_PASSWORD_HASH` is not set (for convenience in testing)
* **`staging`** and **`production`**: Require `ADMIN_PASSWORD_HASH` to be a valid password hash (throws `RuntimeException` if missing)
* **Invalid or unset `APP_ENV`**: Throws `RuntimeException` and denies authentication

This ensures that production environments cannot accidentally use weak default credentials.

## CORS Configuration

* `CORS_ALLOWED_ORIGINS`: Comma-separated list of allowed origins for credentialed CORS requests (auth endpoints).
  Use `*` to allow all origins (not recommended for production with cookie-based auth).
  Example: `CORS_ALLOWED_ORIGINS=https://example.com,https://admin.example.com`

## Example Production Configuration

```bash
APP_ENV=production
API_PROTOCOL=https
API_HOST=mydomain.com
API_PORT=443
API_BASE_PATH=/api/v1/
JWT_ALGORITHM=HS256
JWT_EXPIRY=3600
JWT_REFRESH_EXPIRY=604800
JWT_SECRET=change-this-to-a-secure-random-string-in-production-minimum-32-chars
ADMIN_PASSWORD_HASH=CHANGE_ME_GENERATE_WITH_password_hash
ADMIN_USERNAME=admin
CORS_ALLOWED_ORIGINS=https://mydomain.com,https://admin.mydomain.com
```

## Authentication Endpoints and Protected Routes

**Protected Routes** (require JWT authentication via HttpOnly cookie or `Authorization: Bearer <token>` header):

* `PUT /data/{category}/{calendar}` - Create calendar data
* `PATCH /data/{category}/{calendar}` - Update calendar data
* `DELETE /data/{category}/{calendar}` - Delete calendar data

**Authentication Endpoints:**

* `POST /auth/login` - Authenticate with username/password, returns access and refresh tokens (also sets HttpOnly cookies)
* `POST /auth/refresh` - Refresh access token using refresh token (from cookie or request body)
* `POST /auth/logout` - Logout and clear HttpOnly cookies (stateless; clients should also discard any stored tokens)
* `GET /auth/me` - Get current authenticated user info (requires valid access token)

**Cookie-Based Authentication Details:**

* **Token precedence**: HttpOnly cookies are checked first; the `Authorization` header is used only as a fallback when no cookie is present
* **Cookie handling**: Browsers automatically send cookies with same-site requests when `credentials: 'include'` is set in fetch options.
  For cross-origin requests, the server must also return appropriate CORS headers (`Access-Control-Allow-Credentials: true`)
* **Cookie attributes**:
  * Access token: `SameSite=Lax`, `HttpOnly`, `Secure` (HTTPS only), path `/`
  * Refresh token: `SameSite=Strict`, `HttpOnly`, `Secure` (HTTPS only), path `/auth`
* **CSRF protection**: The `SameSite` attribute provides baseline CSRF protection by restricting when cookies are sent cross-site.
  `Lax` allows same-site requests and top-level cross-site navigations; `Strict` (used for refresh tokens) only allows same-site requests.
  For enhanced security in cross-origin scenarios, consider implementing additional CSRF tokens

For detailed implementation information, see [docs/enhancements/AUTHENTICATION_ROADMAP.md](docs/enhancements/AUTHENTICATION_ROADMAP.md).

# Translations

<a href="https://translate.johnromanodorazio.com/engage/liturgical-calendar/">
    <img src="https://translate.johnromanodorazio.com/widgets/liturgical-calendar/-/open-graph.png" alt="Translation status" />
</a>

# Testing

To test the API locally, first install all package dependencies with `composer install`.

## Static analysis

To run static analysis tests, run `composer analyse`. You can even run this within VSCode's terminal,
and have clickable links to the interested lines in the source code, if you create a `phpstan.neon` file in the root directory
alongside the `phpstan.neon.dist` file. For VSCode running under WSL, `phpstan.neon` should look like this:

```yaml
includes:
    - phpstan.neon.dist

parameters:
    editorUrl: 'vscode://vscode-remote/wsl+Ubuntu-24.04/%%file%%:%%line%%'
```

Replace `Ubuntu-24.04` with the name of your WSL distribution.
For other code editors, see the [PHPStan documentation here](https://phpstan.org/user-guide/output-format#opening-file-in-an-editor).

## Integrity checks web interface

There is a web interface that allows to run a number of integrity checks on the data output by the various routes.
This interface has its own repository [Liturgical-Calendar/UnitTestInterface](https://github.com/Liturgical-Calendar/UnitTestInterface).

You should clone this repository, and run `composer install` within the cloned repository folder.

This web interface communicates with a Web Socket backend included in the API repository.
In order to launch the WebSocket server, you can use <kbd>CTRL</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd> (`litcal-tests-websockets`) from VSCode,
in the Liturgical Calendar API repository.

Then launch the web interface with <kbd>CTRL</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd> (`litcal-tests-webui`) from VSCode,
in the UnitTestInterface repository.

To have all of the launch tasks available without having to open separate instances of VSCode,
it can be convenient to create a `LiturgicalCalendar.code-workspace` file outside of either repository folder,
and add both repository folders to it. For example:

```json
{
        "folders": [
                {
                        "name": "LiturgicalCalendarAPI",
                        "path": "LiturgicalCalendarAPI"
                },
                {
                        "name": "LiturgicalCalendarFrontend",
                        "path": "LiturgicalCalendarFrontend"
                },
                {
                        "name": "UnitTestInterface",
                        "path": "UnitTestInterface"
                }
        ]
}
```

This will include the API repository, the frontend website repository, and the test interface repository all in the same workspace.
If you run `code LiturgicalCalendar.code-workspace` from the command line in WSL, you will open the whole workspace in VSCode,
with all of the folders for each repository and all of the launch tasks available in a single VSCode instance.

## Unit tests

A few Unit Tests are available for testing the various API routes and their available operations and parameters.

To run unit tests, run `composer test`.

# Changelog

See [CHANGELOG.md](CHANGELOG.md).
