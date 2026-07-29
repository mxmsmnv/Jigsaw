<?php namespace ProcessWire;

/**
 * WarmUp
 *
 * Email inbox warmup coordinator. Implements:
 * - Random daily send limits (with optional linear ramp-up)
 * - Random intervals between sends within a configurable time window
 * - Weekly schedule: enable/disable specific days and per-day send windows
 * - Per-day statistics log with admin Process UI
 *
 * @property int    $dailyMin       Minimum emails per day
 * @property int    $dailyMax       Maximum emails per day
 * @property int    $windowStart    Default send window start hour (0-23)
 * @property int    $windowEnd      Default send window end hour (0-23)
 * @property int    $intervalMin    Minimum seconds between sends
 * @property int    $intervalMax    Maximum seconds between sends
 * @property int    $rampupDays     Linear ramp-up period in days (0 = disabled)
 * @property int    $rampupStart    Unix timestamp when ramp-up started
 * @property string $schedule       JSON: per-weekday overrides {0:{on,start,end}, ...} 0=Sun
 */
class WarmUp extends WireData implements Module, ConfigurableModule {

    const permission = 'warmup-admin';

	    protected $temporaryMessageSendQty = [];
	    protected $temporaryQueueNextTimes = [];
	    protected $restoreRegistered = false;
	    protected $integrations = [];

    public static function getModuleInfo() {
        return [
            'title'    => 'WarmUp',
	            'summary'  => 'Email warmup coordinator: random daily limits, time windows, weekly schedule, integrations, and stats log.',
	            'version'  => 100,
	            'author'   => 'Your Name',
	            'installs' => ['ProcessWarmUp'],
            'singular' => true,
            'autoload' => true,
            'permissions' => [
                self::permission => 'Administer WarmUp',
            ],
        ];
    }

    // Files in site/assets/cache/
    const counterFile = 'WarmUp.counter'; // {date, count, next_time}
    const logFile     = 'WarmUp.log';     // newline-delimited JSON, one record per day
    const lockFile    = 'WarmUp.lock';
    const legacyCounterFile = 'ProMailerWarmup.counter';
    const legacyLogFile     = 'ProMailerWarmup.log';
    const legacyLockFile    = 'ProMailerWarmup.lock';

    public function __construct() {
        $this->set('dailyMin',    5);
        $this->set('dailyMax',    30);
        $this->set('windowStart', 9);
        $this->set('windowEnd',   20);
        $this->set('intervalMin', 120);
        $this->set('intervalMax', 900);
        $this->set('rampupDays',  0);
        $this->set('rampupStart', 0);
        $this->set('schedule',    '{}'); // JSON
        parent::__construct();
    }

	    public function ready() {
	        $this->ensurePermissions();
	        $this->migrateLegacyConfig();
	        $this->registerBuiltInIntegrations();
	        $this->addHookBefore('Modules::saveModuleConfigData', $this, 'normalizeConfigBeforeSave');
	    }

    // =========================================================================
    // Hooks
    // =========================================================================

	    public function hookBeforeIntegrationProcess(HookEvent $event, string $integrationName): void {
	        if(!$this->canSendNow()) {
	            $event->replace = true;
	            $event->return  = 0;
            return;
        }
	
	        $remaining = $this->remainingToday();
	        if($remaining < 1 || !$this->prepareIntegrationRun($integrationName, $remaining, $event)) {
	            $event->replace = true;
	            $event->return = 0;
	        }
	    }
	
	    public function hookAfterIntegrationProcess(HookEvent $event, string $integrationName): void {
	        $this->restoreIntegrationRun($integrationName, $event);
	    }
	
	    public function hookAfterIntegrationSend(HookEvent $event, string $integrationName): void {
	        if(!$this->shouldCountSend($integrationName, $event)) return;
	        $counter = $this->incrementCounter();
	        $this->appendLog((int) $counter['count']);
	    }

    // =========================================================================
    // Daily limit
    // =========================================================================

    /**
     * Get today's send limit.
     * Seeded by date — stable within the day, different each day.
     * With ramp-up: limit grows linearly from dailyMin to dailyMax.
     */
    public function getDailyLimit(): int {
        $min = max(0, (int) $this->dailyMin);
        $max = max($min, (int) $this->dailyMax);

        if($this->rampupDays > 0 && $this->rampupStart > 0) {
            $daysElapsed  = max(0, floor((time() - (int) $this->rampupStart) / 86400));
            $progress     = min(1.0, $daysElapsed / max(1, (int) $this->rampupDays));
            $max          = max($min, (int) round($min + ($max - $min) * $progress));
        }

        if($min === $max) return $min;

        $hash = crc32(date('Y-m-d') . '|' . $min . '|' . $max);
        return $min + ((int) sprintf('%u', $hash) % ($max - $min + 1));
    }

    // =========================================================================
    // Window logic
    // =========================================================================

    /**
     * Is right now within the active send window?
     * Checks weekly schedule override first, falls back to global window.
     */
    public function isWithinWindow(): bool {
        $dow     = (int) date('w'); // 0=Sun
        $hour    = (int) date('G');
        $day     = $this->getScheduleDay($dow);

        if($day !== null) {
            // Day is explicitly configured
            if(!$day['on']) return false;
            return $this->hourInRange($hour, (int) $day['start'], (int) $day['end']);
        }

        // No override — use global window
        return $this->hourInRange($hour, (int) $this->windowStart, (int) $this->windowEnd);
    }

    protected function hourInRange(int $hour, int $start, int $end): bool {
        if($start <= $end) return $hour >= $start && $hour < $end;
        return $hour >= $start || $hour < $end; // overnight
    }

    /**
     * Get schedule entry for a day-of-week (0=Sun .. 6=Sat)
     * Returns array [on, start, end] or null if not configured.
     */
    public function getScheduleDay(int $dow): ?array {
        $schedule = $this->getScheduleArray();
        return $schedule[$dow] ?? null;
    }

    public function getScheduleArray(): array {
        $raw = $this->schedule;
        if(!$raw) return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $this->normalizeSchedule($data) : [];
    }

    // =========================================================================
    // Timing
    // =========================================================================

    protected function randomNextTime(): int {
        $min = max(0, (int) $this->intervalMin);
        $max = max($min, (int) $this->intervalMax);
        $interval  = mt_rand($min, $max);
        $candidate = time() + $interval;

        $candHour = (int) date('G', $candidate);
        $candDow  = (int) date('w', $candidate);
        $candDay  = $this->getScheduleDay($candDow);

        if($candDay !== null) {
            $within = $candDay['on'] && $this->hourInRange($candHour, (int) $candDay['start'], (int) $candDay['end']);
        } else {
            $within = $this->hourInRange($candHour, (int) $this->windowStart, (int) $this->windowEnd);
        }

        if(!$within) {
            // Defer to next active window start
            return $this->nextWindowStartTimestamp($candidate);
        }

        return $candidate;
    }

    protected function nextWindowStartTimestamp(int $from): int {
        // Try up to 7 days ahead
        for($d = 0; $d <= 7; $d++) {
            $ts  = mktime(0, 0, 0, (int) date('n', $from), (int) date('j', $from) + $d);
            $dow = (int) date('w', $ts);
            $day = $this->getScheduleDay($dow);
            if($day !== null) {
                if(!$day['on']) continue;
                $startHour = (int) $day['start'];
            } else {
                $startHour = (int) $this->windowStart;
            }
            $candidate = mktime($startHour, mt_rand(0, 10), mt_rand(0, 59), (int) date('n', $from), (int) date('j', $from) + $d);
            if($candidate > $from) return $candidate;
        }
        return $from + 86400; // fallback
    }

    // =========================================================================
    // Counter
    // =========================================================================

    public function getCounter(): array {
        $file     = $this->cachePath(self::counterFile);
        $today    = date('Y-m-d');
        $defaults = ['date' => $today, 'count' => 0, 'next_time' => 0];
        if(!is_file($file)) return $defaults;
        $data = json_decode(file_get_contents($file), true);
        if(!is_array($data)) return $defaults;
        if(($data['date'] ?? '') !== $today) return $defaults;
        return array_merge($defaults, $data);
    }

    public function saveCounter(array $counter): void {
        $this->withLock(function() use ($counter) {
            $counter['date'] = date('Y-m-d');
            $counter['count'] = max(0, (int) ($counter['count'] ?? 0));
            $counter['next_time'] = max(0, (int) ($counter['next_time'] ?? 0));
            file_put_contents($this->cachePath(self::counterFile), json_encode($counter), LOCK_EX);
        });
    }

    protected function incrementCounter(): array {
        return $this->withLock(function() {
            $counter = $this->getCounter();
            $counter['count'] = max(0, (int) $counter['count']) + 1;
            $counter['next_time'] = $this->randomNextTime();
            file_put_contents($this->cachePath(self::counterFile), json_encode($counter), LOCK_EX);
            return $counter;
        });
    }

    // =========================================================================
    // Log
    // =========================================================================

    /**
     * Append/update today's log entry.
     * Log = one JSON line per day, appended. Last entry for a date wins.
     */
    protected function appendLog(int $count): void {
        $entry = [
            'date'  => date('Y-m-d'),
            'count' => $count,
            'limit' => $this->getDailyLimit(),
            'ts'    => time(),
        ];

        $this->withLock(function() use ($entry) {
            $rows = $this->readLogRows();
            $rows[$entry['date']] = $entry;
            krsort($rows);
            $rows = array_slice($rows, 0, 370, true);
            ksort($rows);

            $lines = [];
            foreach($rows as $row) $lines[] = json_encode($row);
            file_put_contents($this->cachePath(self::logFile), implode("\n", $lines) . "\n", LOCK_EX);
        });
    }

    /**
     * Read log and return array of daily records, most recent first.
     * Returns array of ['date', 'count', 'limit', 'ts']
     */
    public function getLog(int $days = 30): array {
        $file = $this->cachePath(self::logFile);
        if(!is_file($file)) return [];
        $byDate = $this->readLogRows();

        // Sort descending by date
        krsort($byDate);

        // Fill in missing days (no sends) for continuity
        $result = [];
        $today  = date('Y-m-d');
        for($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-$i days"));
            if(isset($byDate[$date])) {
                $result[] = $byDate[$date];
            } else if($date <= $today) {
                $result[] = ['date' => $date, 'count' => 0, 'limit' => 0, 'ts' => 0];
            }
        }

        return $result;
    }

    public function clearLog(): void {
        $this->withLock(function() {
            $file = $this->cachePath(self::logFile);
            if(is_file($file)) @unlink($file);
        });
    }

    // =========================================================================
    // Public API
    // =========================================================================

	    public function todayStats(): array {
	        $counter = $this->getCounter();
	        $limit = $this->getDailyLimit();
	        return [
            'date'          => $counter['date'],
            'sent'          => $counter['count'],
            'limit'         => $limit,
	            'remaining'     => max(0, $limit - $counter['count']),
	            'next_time'     => $counter['next_time'],
	            'within_window' => $this->isWithinWindow(),
	            'integrations'   => array_map(function($integration) {
	                return $integration['title'] ?? $integration['name'];
	            }, $this->integrations),
	        ];
	    }

    public function startRampup(): void {
        $this->wire()->modules->saveConfig($this, 'rampupStart', time());
        $this->set('rampupStart', time());
    }

	    public function resetCounter(): void {
	        $this->withLock(function() {
	            $file = $this->cachePath(self::counterFile);
	            if(is_file($file)) @unlink($file);
	        });
	    }

	    public function recordSend(int $count = 1): void {
	        $count = max(1, $count);
	        for($i = 0; $i < $count; $i++) {
	            $counter = $this->incrementCounter();
	            $this->appendLog((int) $counter['count']);
	        }
	    }

    protected function cachePath(string $name): string {
        $path = $this->wire()->config->paths->cache . $name;
        $legacy = [
            self::counterFile => self::legacyCounterFile,
            self::logFile => self::legacyLogFile,
            self::lockFile => self::legacyLockFile,
        ];

        if(!is_file($path) && isset($legacy[$name])) {
            $legacyPath = $this->wire()->config->paths->cache . $legacy[$name];
            if(is_file($legacyPath)) @rename($legacyPath, $path);
        }

        return $path;
    }

    public function canSendNow(): bool {
        if(!$this->isWithinWindow()) return false;
        $counter = $this->getCounter();
        if((int) $counter['count'] >= $this->getDailyLimit()) return false;
        if(time() < (int) $counter['next_time']) return false;
        return true;
    }

	    public function remainingToday(): int {
	        $counter = $this->getCounter();
	        return max(0, $this->getDailyLimit() - (int) $counter['count']);
	    }

	    /**
	     * Register a mailer integration.
	     *
	     * Expected keys:
	     * - title: Human-readable integration name.
	     * - processHook: Hookable method that processes queued mail.
	     * - sendHook: Optional hookable method that returns a send result.
	     * - prepare: Optional callable(WarmUp $warmup, int $remaining, HookEvent $event, array $integration): bool
	     * - restore: Optional callable(WarmUp $warmup, HookEvent $event, array $integration): void
	     * - countSend: Optional callable(WarmUp $warmup, HookEvent $event, array $integration): bool
	     */
	    public function registerIntegration(string $name, array $integration): bool {
	        $name = trim($name);
	        if($name === '' || empty($integration['processHook'])) return false;

	        $integration['name'] = $name;
	        $integration['title'] = $integration['title'] ?? $name;
	        $this->integrations[$name] = $integration;

	        $processHook = (string) $integration['processHook'];
	        $this->addHookBefore($processHook, function(HookEvent $event) use ($name) {
	            $this->hookBeforeIntegrationProcess($event, $name);
	        });
	        $this->addHookAfter($processHook, function(HookEvent $event) use ($name) {
	            $this->hookAfterIntegrationProcess($event, $name);
	        });

	        if(!empty($integration['sendHook'])) {
	            $sendHook = (string) $integration['sendHook'];
	            $this->addHookAfter($sendHook, function(HookEvent $event) use ($name) {
	                $this->hookAfterIntegrationSend($event, $name);
	            });
	        }

	        return true;
	    }

	    public function getIntegrations(): array {
	        return $this->integrations;
	    }

	    protected function registerBuiltInIntegrations(): void {
	        $modules = $this->wire()->modules;
	        if(method_exists($modules, 'isInstalled') && !$modules->isInstalled('ProMailer')) return;
	        if(!$modules->get('ProMailer')) return;

	        $this->registerIntegration('ProMailer', [
	            'title' => 'ProMailer',
	            'processHook' => 'ProMailerQueues::process',
	            'sendHook' => 'ProMailerQueues::sendMessage',
	            'prepare' => [$this, 'prepareProMailerRun'],
	            'restore' => [$this, 'restoreProMailerRun'],
	            'countSend' => [$this, 'countProMailerSend'],
	        ]);
	    }

	    protected function prepareIntegrationRun(string $name, int $remaining, HookEvent $event): bool {
	        $integration = $this->integrations[$name] ?? null;
	        if(!$integration) return false;
	        if(empty($integration['prepare'])) return true;
	        return (bool) call_user_func($integration['prepare'], $this, $remaining, $event, $integration);
	    }

	    protected function restoreIntegrationRun(string $name, HookEvent $event): void {
	        $integration = $this->integrations[$name] ?? null;
	        if(!$integration || empty($integration['restore'])) return;
	        call_user_func($integration['restore'], $this, $event, $integration);
	    }

	    protected function shouldCountSend(string $name, HookEvent $event): bool {
	        $integration = $this->integrations[$name] ?? null;
	        if(!$integration) return false;
	        if(!empty($integration['countSend'])) {
	            return (bool) call_user_func($integration['countSend'], $this, $event, $integration);
	        }
	        return (bool) $event->return;
	    }
	
	    public function prepareProMailerRun(WarmUp $warmup, int $remaining, HookEvent $event, array $integration): bool {
	        $remaining = max(0, $remaining);
	        if($remaining < 1) return false;
	
	        $this->restoreProMailerRun($warmup, $event, $integration);

        $promailer = $this->wire()->modules->get('ProMailer');
        if(!$promailer) return false;

        $queues = $promailer->queues;
        $messages = $promailer->messages;
        $now = time();
        $budget = $remaining;
        $activeFound = false;

        foreach($queues->getAll() as $queue) {
            if((int) $queue->paused) continue;
            if((int) $queue->next_time > $now) continue;

            if($budget > 0) {
                $message = $messages->get($queue->message_id);
                if(!$message) continue;

                $activeFound = true;
                $originalSendQty = max(1, (int) $message->send_qty);
                $cappedSendQty = max(1, min($originalSendQty, $budget));
                if($cappedSendQty !== $originalSendQty) {
                    $this->temporaryMessageSendQty[(int) $message->id] = $originalSendQty;
                    $message->send_qty = $cappedSendQty;
                    $messages->save($message);
                }
                $budget -= $cappedSendQty;
                continue;
            }

            $messageId = (int) $queue->message_id;
            $this->temporaryQueueNextTimes[$messageId] = (int) $queue->next_time;
            $queue->next_time = $now + max(60, (int) $this->intervalMax);
            $queues->save($queue);
        }

	        if($activeFound && !$this->restoreRegistered) {
	            $this->restoreRegistered = true;
	            register_shutdown_function([$this, 'restoreProMailerRun']);
	        }
	
	        return $activeFound;
	    }
	
	    public function restoreProMailerRun(?WarmUp $warmup = null, ?HookEvent $event = null, array $integration = []): void {
	        if(!$this->temporaryMessageSendQty && !$this->temporaryQueueNextTimes) return;

        $promailer = $this->wire()->modules->get('ProMailer');
        if($promailer) {
            foreach($this->temporaryMessageSendQty as $messageId => $sendQty) {
                $message = $promailer->messages->get((int) $messageId);
                if(!$message) continue;
                $message->send_qty = (int) $sendQty;
                $promailer->messages->save($message);
            }

            foreach($this->temporaryQueueNextTimes as $messageId => $nextTime) {
                $queue = $promailer->queues->get((int) $messageId);
                if(!$queue) continue;
                $queue->next_time = (int) $nextTime;
                $promailer->queues->save($queue);
            }
        }

	        $this->temporaryMessageSendQty = [];
	        $this->temporaryQueueNextTimes = [];
	    }

	    public function countProMailerSend(WarmUp $warmup, HookEvent $event, array $integration): bool {
	        $result = $event->return;
	        return $result && $result !== 'skip';
	    }

	    protected function readLogRows(): array {
        $file = $this->cachePath(self::logFile);
        if(!is_file($file)) return [];

        $contents = file_get_contents($file);
        if(!$contents) return [];

        $rows = [];
        $decoded = json_decode($contents, true);
        if(is_array($decoded)) {
            foreach($decoded as $key => $row) {
                if(!is_array($row)) continue;
                if(empty($row['date']) && is_string($key)) $row['date'] = $key;
                if(empty($row['date'])) continue;
                $rows[$row['date']] = $this->normalizeLogRow($row);
            }
            return $rows;
        }

        foreach(explode("\n", $contents) as $line) {
            $line = trim($line);
            if($line === '') continue;
            $row = json_decode($line, true);
            if(!is_array($row) || empty($row['date'])) continue;
            $rows[$row['date']] = $this->normalizeLogRow($row);
        }
        return $rows;
    }

    protected function normalizeLogRow(array $row): array {
        return [
            'date'  => (string) ($row['date'] ?? ''),
            'count' => max(0, (int) ($row['count'] ?? 0)),
            'limit' => max(0, (int) ($row['limit'] ?? 0)),
            'ts'    => max(0, (int) ($row['ts'] ?? 0)),
        ];
    }

    protected function withLock(callable $callback) {
        $lockFile = $this->cachePath(self::lockFile);
        $handle = fopen($lockFile, 'c');
        if(!$handle) throw new WireException("Unable to open lock file: $lockFile");

        try {
            if(!flock($handle, LOCK_EX)) throw new WireException("Unable to lock file: $lockFile");
            $result = $callback();
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    protected function ensurePermissions(): void {
        $permissions = $this->wire('permissions');
        $permission = $permissions->get(self::permission);
        if(!$permission || !$permission->id) {
            $permission = new Permission();
            $permission->name = self::permission;
            $permission->title = 'Administer WarmUp';
            $permission->save();
        }

        $legacyPermission = $permissions->get('promailer-warmup-admin');
        if(!$legacyPermission || !$legacyPermission->id) return;

        foreach($this->wire('roles') as $role) {
            if(!$role->hasPermission($legacyPermission) || $role->hasPermission($permission)) continue;
            $role->addPermission($permission);
            $role->save();
        }
    }

    protected function migrateLegacyConfig(): void {
        $modules = $this->wire()->modules;
        $current = $modules->getConfig($this);
        if(is_array($current) && count($current)) return;

        $legacy = $modules->getConfig('ProMailerWarmup');
        if(!is_array($legacy) || !count($legacy)) return;

        $allowed = [
            'dailyMin', 'dailyMax', 'windowStart', 'windowEnd',
            'intervalMin', 'intervalMax', 'rampupDays', 'rampupStart', 'schedule',
        ];

        $data = [];
        foreach($allowed as $key) {
            if(array_key_exists($key, $legacy)) $data[$key] = $legacy[$key];
        }

        if(!$data) return;
        $modules->saveConfig($this, $data);
        foreach($data as $key => $value) $this->set($key, $value);
    }

    public function normalizeConfigBeforeSave(HookEvent $event): void {
        $module = $event->arguments(0);
        if($module !== $this && $module !== $this->className() && $module !== 'WarmUp') return;

        $data = $event->arguments(1);
        if(!is_array($data)) return;

        $data['dailyMin'] = max(0, (int) ($data['dailyMin'] ?? $this->dailyMin));
        $data['dailyMax'] = max($data['dailyMin'], (int) ($data['dailyMax'] ?? $this->dailyMax));
        $data['windowStart'] = $this->normalizeHour($data['windowStart'] ?? $this->windowStart);
        $data['windowEnd'] = $this->normalizeHour($data['windowEnd'] ?? $this->windowEnd);
        $data['intervalMin'] = max(0, (int) ($data['intervalMin'] ?? $this->intervalMin));
        $data['intervalMax'] = max($data['intervalMin'], (int) ($data['intervalMax'] ?? $this->intervalMax));
        $data['rampupDays'] = max(0, (int) ($data['rampupDays'] ?? $this->rampupDays));
        $data['rampupStart'] = max(0, (int) ($data['rampupStart'] ?? $this->rampupStart));

        $schedule = [];
        if(!empty($data['schedule'])) {
            $decoded = json_decode((string) $data['schedule'], true);
            if(is_array($decoded)) $schedule = $this->normalizeSchedule($decoded);
        }
        $data['schedule'] = $schedule ? json_encode($schedule) : '{}';

        $event->arguments(1, $data);
    }

    protected function normalizeSchedule(array $schedule): array {
        $normalized = [];
        $hasEnabledDay = false;

        for($dow = 0; $dow <= 6; $dow++) {
            if(!isset($schedule[$dow]) && !isset($schedule[(string) $dow])) continue;
            $day = $schedule[$dow] ?? $schedule[(string) $dow];
            if(!is_array($day)) continue;

            $on = !empty($day['on']) ? 1 : 0;
            if($on) $hasEnabledDay = true;
            $normalized[$dow] = [
                'on'    => $on,
                'start' => $this->normalizeHour($day['start'] ?? $this->windowStart),
                'end'   => $this->normalizeHour($day['end'] ?? $this->windowEnd),
            ];
        }

        return $hasEnabledDay ? $normalized : [];
    }

    protected function normalizeHour($hour): int {
        return max(0, min(23, (int) $hour));
    }

    // =========================================================================
    // Module config
    // =========================================================================

    public function ___getModuleConfigInputfields(InputfieldWrapper $inputfields) {
        $modules = $this->wire()->modules;

        // --- Daily limits ---
        /** @var InputfieldFieldset $fs */
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Daily send limits');
        $fs->description = $this->_('Each day a random number between Min and Max is chosen as today\'s limit. The number is seeded by date, so it stays fixed within the day but changes every day.');

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'dailyMin');
        $f->label = $this->_('Minimum emails/day');
        $f->attr('value', (int) $this->dailyMin);
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'dailyMax');
        $f->label = $this->_('Maximum emails/day');
        $f->attr('value', (int) $this->dailyMax);
        $f->columnWidth = 50;
        $fs->add($f);

        $inputfields->add($fs);

        // --- Ramp-up ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Ramp-up (gradual warmup)');
        $fs->description = $this->_(
            'Ramp-up gradually increases the daily limit from Min to Max over N days. ' .
            'On day 1 the limit equals Min; on the last day it equals Max. ' .
            'Set to 0 to use the full random range immediately (no ramp-up).'
        );

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'rampupDays');
        $f->label = $this->_('Ramp-up period (days, 0 = disabled)');
        $f->attr('value', (int) $this->rampupDays);
        $f->columnWidth = 50;
        $fs->add($f);

        if($this->rampupStart) {
            $daysElapsed = max(0, floor((time() - (int) $this->rampupStart) / 86400));
            $f = $modules->get('InputfieldMarkup');
            $f->label = $this->_('Ramp-up progress');
            $f->columnWidth = 50;
            $f->value = sprintf(
                $this->_('Started: %s — Day %d of %d'),
                date('Y-m-d', (int) $this->rampupStart),
                $daysElapsed + 1,
                (int) $this->rampupDays
            );
            $fs->add($f);
        }

        $inputfields->add($fs);

        // --- Default send window ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Default send window');
        $fs->description = $this->_('Hours during which sending is allowed. Used for days not overridden in the weekly schedule below.');

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'windowStart');
        $f->label = $this->_('Start hour (0–23)');
        $f->attr('value', (int) $this->windowStart);
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'windowEnd');
        $f->label = $this->_('End hour (0–23)');
        $f->attr('value', (int) $this->windowEnd);
        $f->columnWidth = 50;
        $fs->add($f);

        $inputfields->add($fs);

        // --- Intervals ---
        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Send intervals');
        $fs->description = $this->_('Random pause between individual sends. Keep these human-like to aid deliverability.');

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'intervalMin');
        $f->label = $this->_('Min interval (seconds)');
        $f->attr('value', (int) $this->intervalMin);
        $f->columnWidth = 50;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'intervalMax');
        $f->label = $this->_('Max interval (seconds)');
        $f->attr('value', (int) $this->intervalMax);
        $f->columnWidth = 50;
        $fs->add($f);

        $inputfields->add($fs);

        // --- Weekly schedule ---
        $scheduleArray = $this->getScheduleArray();
        $dayNames = [
            0 => $this->_('Sunday'),
            1 => $this->_('Monday'),
            2 => $this->_('Tuesday'),
            3 => $this->_('Wednesday'),
            4 => $this->_('Thursday'),
            5 => $this->_('Friday'),
            6 => $this->_('Saturday'),
        ];

        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Weekly schedule');
        $fs->description = $this->_('Override the send window per day, or disable sending on specific days. Leave all disabled/unchecked to use the default window above.');

        foreach($dayNames as $dow => $name) {
            $day    = $scheduleArray[$dow] ?? null;
            $isOn   = $day ? (bool) $day['on']    : false;
            $start  = $day ? (int)  $day['start']  : (int) $this->windowStart;
            $end    = $day ? (int)  $day['end']    : (int) $this->windowEnd;

            $row = $modules->get('InputfieldFieldset');
            $row->label = $name;
            $row->collapsed = Inputfield::collapsedNo;

            $f = $modules->get('InputfieldCheckbox');
            $f->attr('name', "sched_on_{$dow}");
            $f->label = $this->_('Enable sending on this day');
            $f->attr('checked', $isOn ? 'checked' : '');
            $f->attr('value', 1);
            $f->columnWidth = 33;
            $row->add($f);

            $f = $modules->get('InputfieldInteger');
            $f->attr('name', "sched_start_{$dow}");
            $f->label = $this->_('Start hour');
            $f->attr('value', $start);
            $f->columnWidth = 33;
            $row->add($f);

            $f = $modules->get('InputfieldInteger');
            $f->attr('name', "sched_end_{$dow}");
            $f->label = $this->_('End hour');
            $f->attr('value', $end);
            $f->columnWidth = 34;
            $row->add($f);

            $fs->add($row);
        }

        // Hidden field carries the final JSON (built by JS or on save)
        $f = $modules->get('InputfieldHidden');
        $f->attr('name', 'schedule');
        $f->attr('value', $this->schedule);
        $fs->add($f);

        $inputfields->add($fs);

        // Inline JS to pack schedule fields into the hidden JSON field on form submit
        /** @var InputfieldMarkup $f */
        $f = $modules->get('InputfieldMarkup');
        $f->label = '';
        $f->value = "<script>
document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form.InputfieldForm');
    if(!form) return;
	        form.addEventListener('submit', function() {
	        var schedule = {};
	        var hasEnabledDay = false;
	        for(var d = 0; d <= 6; d++) {
	            var onEl    = form.querySelector('[name=\"sched_on_'+d+'\"]');
	            var startEl = form.querySelector('[name=\"sched_start_'+d+'\"]');
	            var endEl   = form.querySelector('[name=\"sched_end_'+d+'\"]');
	            if(onEl && startEl && endEl) {
	                if(onEl.checked) hasEnabledDay = true;
	                schedule[d] = {
	                    on:    onEl.checked ? 1 : 0,
	                    start: parseInt(startEl.value) || 0,
	                    end:   parseInt(endEl.value)   || 0
	                };
	            }
	        }
	        var hidden = form.querySelector('[name=\"schedule\"]');
	        if(hidden) hidden.value = JSON.stringify(hasEnabledDay ? schedule : {});
	    });
	});
	</script>";
        $inputfields->add($f);
    }
}
