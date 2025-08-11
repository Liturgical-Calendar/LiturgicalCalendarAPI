<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\Attributes\Group;

final class EventsTest extends ApiTestCase
{
    private static object $metadata;

    public function testGetEventsReturnsJson(): void
    {
        $response = $this->http->get('/events');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_events', $data, 'Response should have a litcal_events property');
        $this->assertIsArray($data->litcal_events, 'Response litcal_events should be an array');
        $this->assertObjectHasProperty('settings', $data, 'Response should have a settings property');
        $this->assertIsObject($data->settings, 'Response settings should be an object');
        $this->assertObjectHasProperty('locale', $data->settings, 'Response settings should have a locale property');
        $this->assertIsString($data->settings->locale, 'Response settings locale should be a string');
        $this->assertObjectHasProperty('national_calendar', $data->settings, 'Response settings should have a national_calendar property');
        $this->assertNull($data->settings->national_calendar, 'national_calendar should be null');
        $this->assertObjectHasProperty('diocesan_calendar', $data->settings, 'Response settings should have a diocesan_calendar property');
        $this->assertNull($data->settings->diocesan_calendar, 'diocesan_calendar should be null');
    }

    #[Group('slow')]
    public function testGetEventsSampleAllPaths(): void
    {
        foreach(self::$metadata->national_calendars_keys as $national_calendar_key) {
            $national_calendar_metadata = array_find(self::$metadata->national_calendars, function ($item) use ($national_calendar_key) {
                return $item->calendar_id === $national_calendar_key;
            });
            $locales = $national_calendar_metadata->locales;
            foreach($locales as $locale) {
                $response = $this->http->get("/events/nation/{$national_calendar_key}", [
                    'headers' => ['Accept-Language' => $locale]
                ]);
                $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
                $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
                $data = json_decode((string) $response->getBody());
                $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
                $this->assertIsObject($data, 'Response should be a JSON object');
                $this->validateEventsObject($data, 'national_calendar', $national_calendar_key, $locale);
            }
        }

        foreach(self::$metadata->diocesan_calendars_keys as $diocesan_calendar_key) {
            $diocesan_calendar_metadata = array_find(self::$metadata->diocesan_calendars, function ($item) use ($diocesan_calendar_key) {
                return $item->calendar_id === $diocesan_calendar_key;
            });
            $locales = $diocesan_calendar_metadata->locales;
            foreach($locales as $locale) {
                $response = $this->http->get("/events/diocese/{$diocesan_calendar_key}", [
                    'headers' => ['Accept-Language' => $locale]
                ]);
                $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
                $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');
                $data = json_decode((string) $response->getBody());
                $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
                $this->assertIsObject($data, 'Response should be a JSON object');
                $this->validateEventsObject($data, 'diocesan_calendar', $diocesan_calendar_key, $locale);
            }
        }
    }

    public function validateEventsObject(object $data, string $calendar_type, string $calendar_id, string $locale): void
    {
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_events', $data, 'Response should have a litcal_events property');
        $this->assertIsArray($data->litcal_events, 'Response litcal_events should be an array');
        $this->assertObjectHasProperty('settings', $data, 'Response should have a settings property');
        $this->assertIsObject($data->settings, 'Response settings should be an object');
        $this->assertObjectHasProperty('locale', $data->settings, 'Response settings should have a locale property');
        $this->assertIsString($data->settings->locale, 'Response settings locale should be a string');
        $this->assertEquals($data->settings->locale, $locale, 'Response settings locale should be ' . $locale);
        $this->assertObjectHasProperty('national_calendar', $data->settings, 'Response settings should have a national_calendar property');
        $this->assertObjectHasProperty('diocesan_calendar', $data->settings, 'Response settings should have a diocesan_calendar property');
        if ($calendar_type === 'national_calendar') {
            if ($calendar_id === 'VA') {
                $this->assertNull($data->settings->national_calendar, 'national_calendar should be null');
            } else {
                $this->assertEquals($data->settings->national_calendar, $calendar_id, 'national_calendar should be ' . $calendar_id);
            }
            $this->assertNull($data->settings->diocesan_calendar, 'diocesan_calendar should be null');
        } elseif ($calendar_type === 'diocesan_calendar') {
            $diocesan_calendar_metadata = array_find(self::$metadata->diocesan_calendars, function ($item) use ($calendar_id) {
                return $item->calendar_id === $calendar_id;
            });
            $national_calendar = $diocesan_calendar_metadata->nation;
            $this->assertEquals($data->settings->national_calendar, $national_calendar, 'national_calendar should be ' . $national_calendar);
            $this->assertEquals($data->settings->diocesan_calendar, $calendar_id, 'diocesan_calendar should be ' . $calendar_id);
        }
        foreach ($data->litcal_events as $event) {
            $this->assertIsObject($event, 'litcal_events should be an array of objects');
            $this->assertObjectHasProperty('event_key', $event, 'event object should have a event_key property');
            $this->assertIsString($event->event_key, 'event_key should be a string');
            $this->assertObjectHasProperty('event_idx', $event, 'event object should have a event_idx property');
            $this->assertIsInt($event->event_idx, 'event_idx should be an int');
            $this->assertObjectHasProperty('name', $event, 'event object should have a name property');
            $this->assertIsString($event->name, 'name should be a string');
            $this->assertObjectHasProperty('color', $event, 'event object should have a color property');
            $this->assertIsArray($event->color, 'color should be an array');
            $this->assertObjectHasProperty('color_lcl', $event, 'event object should have a color_lcl property');
            $this->assertIsArray($event->color_lcl, 'color_lcl should be an array');
            $this->assertObjectHasProperty('grade', $event, 'event object should have a grade property');
            $this->assertIsInt($event->grade, 'grade should be an int');
            $this->assertObjectHasProperty('grade_lcl', $event, 'event object should have a grade_lcl property');
            $this->assertIsString($event->grade_lcl, 'grade_lcl should be a string');
            $this->assertObjectHasProperty('grade_abbr', $event, 'event object should have a grade_abbr property');
            $this->assertIsString($event->grade_abbr, 'grade_abbr should be a string');
            $this->assertObjectHasProperty('grade_display', $event, 'event object should have a grade_display property');
            $this->assertThat($event->grade_display, $this->logicalOr(
                $this->isString(),
                $this->isNull()
            ), 'grade_display should be a string or null');
            $this->assertObjectHasProperty('common', $event, 'event object should have a common property');
            $this->assertIsArray($event->common, 'common should be an array');
            $this->assertObjectHasProperty('common_lcl', $event, 'event object should have a common_lcl property');
            $this->assertIsString($event->common_lcl, 'common_lcl should be a string');
            $this->assertObjectHasProperty('type', $event, 'event object should have a type property');
            $this->assertContainsEquals($event->type, ['mobile', 'fixed'], 'type should be either "mobile" or "fixed"');
            if ($event->type === 'fixed') {
                $this->assertObjectHasProperty('month', $event, 'event object should have a month property');
                $this->assertIsInt($event->month, 'month should be an int');
                $this->assertObjectHasProperty('day', $event, 'event object should have a day property');
                $this->assertIsInt($event->day, 'day should be an int');
            } elseif ($event->type === 'mobile') {
                $this->assertObjectHasProperty('strtotime', $event, 'event object should have a strtotime property');
                $this->assertThat($event->strtotime, $this->logicalOr(
                    $this->isString(),
                    $this->isObject()
                ));
                if (is_object($event->strtotime)) {
                    $this->assertObjectHasProperty('day_of_the_week', $event->strtotime, 'strtotime object should have a day_of_the_week property');
                    $this->assertIsString($event->strtotime->day_of_the_week, 'day_of_the_week should be a string');
                    $this->assertObjectHasProperty('relative_time', $event->strtotime, 'strtotime object should have a relative_time property');
                    $this->assertContainsEquals($event->strtotime->relative_time, ['before', 'after'], 'relative_time should be either "before" or "after"');
                    $this->assertObjectHasProperty('event_key', $event->strtotime, 'strtotime object should have a event_key property');
                    $this->assertIsString($event->strtotime->event_key, 'event_key should be a string');
                }
            }
        }
    }

    public function setUp(): void
    {
        parent::setUp();

        if (false === isset(self::$metadata)) {
            try {
                $response = $this->http->get('/calendars', [
                    'http_errors'    => true,
                    'connect_errors' => true
                ]);
                $data = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
                if (false === property_exists($data, 'litcal_metadata') || false === is_object($data->litcal_metadata)) {
                    throw new \RuntimeException('Failed to get `litcal_metadata` property from /calendars');
                }
                self::$metadata = $data->litcal_metadata;
            } catch (\JsonException $e) {
                $this->markTestSkipped('Failed to decode calendars metadata JSON: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ServerException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $this->markTestSkipped('Failed to get calendars metadata: ' . $e->getMessage());
            }
        }
    }
}
