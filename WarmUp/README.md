# WarmUp

WarmUp coordinates email inbox warmup for ProcessWire mailer modules. It limits daily send volume, spaces sends across realistic intervals, respects weekly send windows and records daily delivery progress.

![WarmUp](assets/WarmUp.png)

It includes a built-in ProMailer integration and an extension API so other queue-based mailer modules can connect to the same warmup rules without modifying WarmUp itself.

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

## What WarmUp Does

- Chooses a stable random daily limit between configured minimum and maximum values.
- Supports gradual ramp-up from the minimum daily limit to the maximum daily limit.
- Enforces a default hourly send window.
- Supports weekly schedule overrides for individual days.
- Adds randomized intervals between counted sends.
- Tracks today's sent count, remaining allowance, next send time and active integrations.
- Keeps a compact daily log for recent send history.
- Provides a ProcessWire admin screen with status, chart, log and maintenance actions.
- Includes a built-in ProMailer integration when ProMailer is installed.
- Provides an integration API for other mailer modules.

## Admin Area

WarmUp adds an admin page under Setup where site editors can:

- view today's warmup status;
- see the active send window and next send time;
- review the last 30 days of send activity;
- reset today's counter;
- clear the warmup log;
- start ramp-up;
- see which integrations are currently active.

## Integrations

WarmUp automatically registers ProMailer if the `ProMailer` module is installed.

Other modules can connect by registering an integration in their `ready()` method:

```php
$warmup = $this->wire()->modules->get('WarmUp');

if($warmup) {
    $warmup->registerIntegration('MyMailer', [
        'title' => 'My Mailer',
        'processHook' => 'MyMailerQueue::process',
        'sendHook' => 'MyMailerQueue::sendMessage',
        'countSend' => function(WarmUp $warmup, HookEvent $event, array $integration) {
            return (bool) $event->return;
        },
    ]);
}
```

Integrations with queue-specific batching can also provide `prepare` and `restore` callbacks. WarmUp uses this for ProMailer to temporarily cap queue batch sizes without editing ProMailer files.

Modules that handle their own sending flow can call:

```php
$warmup->recordSend();
```

after a successful send.

## Installation

1. Copy the `WarmUp` folder into `/site/modules/`.
2. In ProcessWire Admin, refresh modules.
3. Install `WarmUp`.
4. Open Setup > WarmUp and adjust the settings.
5. If ProMailer is installed, WarmUp will connect to it automatically.

## Configuration

WarmUp configuration includes:

- minimum and maximum emails per day;
- ramp-up period and start date;
- default send window;
- minimum and maximum send interval;
- weekly day-by-day schedule.

If no weekly day is enabled, WarmUp falls back to the default send window.

## Documentation

See [CHANGELOG.md](CHANGELOG.md) for the release notes.

## Author

Maxim Semenov  
[smnv.org](https://smnv.org)  
[maxim@smnv.org](mailto:maxim@smnv.org)

## License

MIT
