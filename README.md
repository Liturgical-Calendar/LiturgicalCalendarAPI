<table>
    <thead>
        <tr><th colspan="2">Code quality</th><th>Translation status</th><th>OpenAPI validation</th></tr>
        <tr><th style="text-align:center;"><a href="https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/tree/master">main branch</a></th><th style="text-align:center;"><a href="https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/tree/development">development branch</a></th><th></th><th></th></tr>
    </thead>
    <tbody>
        <tr>
            <td style="text-align:center;">
                <a href="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/overview/master"><img src="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/badge/master" title="CodeFactor" /></a>
            </td>
            <td style="text-align:center;">
                <a href="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/overview/development"><img src="https://www.codefactor.io/repository/github/liturgical-calendar/liturgicalcalendarapi/badge/development" title="CodeFactor" /></a>
            </td>
            <td><a href="https://translate.johnromanodorazio.com/engage/liturgical-calendar/">
<img src="https://translate.johnromanodorazio.com/widgets/liturgical-calendar/-/287x66-white.png" alt="Translation status" />
</a></td>
            <td> <a href="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/development/jsondata/schemas/openapi.json"><img src="https://validator.swagger.io/validator?url=https://raw.githubusercontent.com/Liturgical-Calendar/LiturgicalCalendarAPI/development/jsondata/schemas/openapi.json" alt="OpenAPI validation result" /></a></td>
        </tr>
    </tbody>
</table>

# Liturgical Calendar
An API written in PHP that will generate the liturgical calendar for any given year, based on the General Roman Calendar, calculating the mobile festivities and the precedence of solemnities, feasts, memorials... Can also produce calendar data for nations, dioceses, or groups of dioceses. This calendar data can be served in various formats such as JSON, YAML, XML, or ICS. More information on the website https://litcal.johnromanodorazio.com/.

OpenAPI documentation [can be found here](https://litcal.johnromanodorazio.com/dist/) (kudos to @MichaelRShelton for generating the docs from the Swagger docker image).

The API is packaged as a composer library: run `composer install` to setup the autoload functionality.

Some characteristics of this API:
* **The data is based on official sources**, not copied from random internet sources. Sources used are the various editions of the **Roman Missal** in Latin, English, and Italian, **Magisterial documents**, and the **Decrees of the Congregation for Divine Worship**
    - Missale Romanum, Editio typica, 1970
    - Missale Romanum, Reimpressio emendata, 1971
    - Missale Romanum, Editio typica secunda, 1975
    - Missale Romanum, Editio typica tertia, 2002
    - Missale Romanum, Editio typica tertia emendata, 2008
    - [Mysterii Paschalis, PAULUS PP. VI, 1969](http://www.vatican.va/content/paul-vi/la/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html)
    - [Decrees of the Congregation of Divine Worship](https://www.vatican.va/roman_curia/congregations/ccdds/index_it.htm)
* **The data is historically accurate**, *i.e.* the liturgical calendar produced for the year 1979 will reflect the calendar as it was in that year, and not as it would be today (obviously future years will reflect the calendar as it is generated in the current year; as new decrees are issued by the Congregation for Divine Worship or new editions of the Roman Missal are published, the script will need to be updated to account for any new criteria)


# Example applications
There are a few proof of concept example applications for usage of the API at https://litcal.johnromanodorazio.com/usage.php, which demonstrate generating an HTML representation of the Liturgical Calendar.

* The [first example](https://litcal.johnromanodorazio.com/examples.php?example=PHP) uses cURL in PHP to make a request to the endpoint and handle the results.
* The [second example](https://litcal.johnromanodorazio.com/examples.php?example=JavaScript) uses `fetch` in Javascript to make the request to the endpoint and handle the results.
* The [third example](https://litcal.johnromanodorazio.com/examples.php?example=FullCalendar) makes use of the [FullCalendar javascript framework](https://github.com/fullcalendar/fullcalendar) to display the results from the `fetch` request in a nicely formatted calendar view.
* The [fourth example](https://litcal.johnromanodorazio.com/examples.php?example=FullCalendarMessages) is the same as the third except that it outputs the Messages first and the [FullCalendar](https://github.com/fullcalendar/fullcalendar) calendar view after.

All of these examples request `JSON` as the data exchange format generated by the endpoint. Any application could use the endpoint in a similar manner: an Android App, a plugin for a Desktop Publishing App...

## Using the endpoint as a calendar URL for Calendar Apps

_(See [usage.php#calSubscription](https://litcal.johnromanodorazio.com/usage.php#calSubscription "https://litcal.johnromanodorazio.com/usage.php#calSubscription").)_

* **GOOGLE CALENDAR ON A DESKTOP COMPUTER**: you can only *add a calendar by URL* using Google Calendar on a computer, I don't believe it is possible from smartphone / Android devices. At the bottom left corner of the screen, next to **`Other calendars`**, click on the **`+`** to add a new calendar and choose **`From URL`**. Paste in the URL of the endpoint with the desired parameters, (make sure you use **`ICS`** as value of the *`return_type`* parameter). And remember, if you omit the *`year`* parameter, it will use the current year. This should mean that as Google Calendar continues to poll the calendar URL (supposedly every 8 hours), on the turn of a new year new events should be created automatically for the new year. Once the calendar has been added from a computer, it should become available for the same gmail account on the Google Calendar app on a smartphone.
* **CALENDAR APPS ON AN ANDROID DEVICE**: after you have *added a calendar by URL* in your Google Calendar on a Desktop Computer, you should then find that calendar synchronized with your Google account, so the calendar should become available to any Android Calendar apps that have access to your Google account to synchronize calendars.
* **IPHONE**: go to **`Phone Settings`** -> **`Accounts`** -> **`Add account`** -> **`Other`** -> **`Add Calendar`**, and paste in the endpoint URL with the desired parameters, (make sure you use **`ICS`** as value of the *`return_type`* parameter). And remember, if you omit the *`year`* parameter, it will use the current year. This should mean that as the iPhone Calendar continues to poll the calendar URL, on the turn of a new year new events should be created automatically for the new year.
* **MICROSOFT OUTLOOK** *(tested with Outlook 2013)*: at the bottom of the screen, switch from **`Email`** view to **`Calendar`** view. On the ribbon of the **`Home`** menu item, click on **`Open calendar`** -> **`From the internet`**. Paste the endpoint URL with the desired parameters, (make sure you use **`ICS`** as value of the *`return_type`* parameter). And remember, if you omit the *`year`* parameter, it will use the current year. On the following screen, check the checkbox along the lines of "Poll this calendar in the interval suggested by the creator", which would mean that Outlook Calendar should poll the calendar URL once a day. This means that without the *`year`* parameter, on the turn of a new year new events should be created automatically for the new year. Make sure the Calendar is created in the **`Other calendars`** folder; if you find it under the **`Personal calendars`** folder, drag it and drop it onto the **`Other calendars`** folder, this should ensure that it is treated as a subscription internet calendar. You can manually trigger an update against the calendar URL by clicking on **`Send/receive all`** (from the **`SEND/RECEIVE`** menu item). One highlight of the calendar in Outlook is that it supports a minimal amount of HTML in the event description, so the event descriptions in the Liturgical Calendar are a little bit more "beautified" for Outlook.

# Testing locally

System requirements:
* PHP >= 8.4 (we make use of more modern PHP functions such as `array_find`)
* PHP modules installed and enabled: `intl` * `zip` * `gettext` * `calendar` * `yaml`
* System language packs for all the supported languages

## Using PHP's builtin server

To test the API locally, you can use PHP's builtin server. However, you will need to spawn at least a couple of workers, since some routes will make a request internally to another route. For example, a request to the `/calendar` route will make a request internally to the `/calendars` route. To be really safe, you could spawn up to 6 workers.

Spawn at least two workers:
```bash
PHP_CLI_SERVER_WORKERS=2 php -S localhost:8000
```

For convenience when using VSCode, a `tasks.json` has been defined so that you can simply type <kbd>CTRL</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd> (<kbd>CMD</kbd>+<kbd>SHIFT</kbd>+<kbd>B</kbd> on MacOS) to start the PHP builtin server and open the browser.

## Using a docker container

To further simplify your setup, without having to worry about getting all the system requirements in place, you can also launch the API in a docker container using the repo `Dockerfile`:

```bash
# If you haven't cloned the repo locally, you can build directly from the remote repo (replace `{branch}` with the branch or tag from which you want to build):
docker build -t liturgy-api:{branch} https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI.git#{branch}
# If instead you have cloned the repo locally, you can build from the local repo (replace `{branch}` with the branch or tag that you have checked out locally):
docker build -t liturgy-api:{branch} .
docker run -p 8000:8000 -d liturgy-api:{branch}
```

This will result in a docker image of about 1.09GB. Unfortunately this cannot be reduced by means of an alpine image,
if we want to install system locales in order for `gettext` to work properly with all supported languages.

# Translations

<a href="https://translate.johnromanodorazio.com/engage/liturgical-calendar/">
<img src="https://translate.johnromanodorazio.com/widgets/liturgical-calendar/-/open-graph.png" alt="Translation status" />
</a>

# Changelog
See [CHANGELOG.md](CHANGELOG.md).
