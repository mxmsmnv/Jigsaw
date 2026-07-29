<?php namespace ProcessWire;

/**
 * ProcessWarmUp
 *
 * Admin panel for WarmUp: daily stats log, today's status, controls.
 */
class ProcessWarmUp extends Process implements Module {

    public static function getModuleInfo() {
        return [
            'title'    => 'WarmUp (admin)',
            'summary'  => 'Stats and controls for WarmUp.',
            'version'  => 100,
            'requires' => ['WarmUp'],
            'singular' => true,
            'autoload' => false,
            'icon'     => 'fire',
            'page'     => [
                'name'   => 'warmup',
                'parent' => 'setup',
                'title'  => 'WarmUp',
            ],
            'permission'  => 'warmup-admin',
            'permissions' => [
                'warmup-admin' => 'Administer WarmUp',
            ],
        ];
    }

    /** @var WarmUp */
    protected $warmup;

    public function init() {
        parent::init();
        $this->warmup = $this->wire()->modules->get('WarmUp');
    }

    // =========================================================================
    // execute() — main stats page
    // =========================================================================

    protected function renderDashboard(): string {
        $this->headline($this->_('WarmUp — Statistics'));
        $this->browserTitle($this->_('WarmUp Stats'));

        // Handle actions
        $action = $this->wire()->input->post('warmup_action');
        if($action) {
            if(!$this->wire()->session->CSRF->hasValidToken()) {
                throw new WirePermissionException($this->_('Invalid security token.'));
            }

            if($action === 'reset_counter') {
                $this->warmup->resetCounter();
                $this->message($this->_('Today\'s counter has been reset.'));
            } else if($action === 'clear_log') {
                $this->warmup->clearLog();
                $this->message($this->_('Log cleared.'));
            } else if($action === 'start_rampup') {
                $this->warmup->startRampup();
                $this->message($this->_('Ramp-up started from today.'));
            }
        }

        $out = '';
        $out .= $this->renderTodayCard();
        $out .= $this->renderRampupCard();
        $out .= $this->renderChart();
        $out .= $this->renderLogTable();
        $out .= $this->renderActions();

        return $out;
    }

    // =========================================================================
    // Cards
    // =========================================================================

    protected function renderTodayCard(): string {
        $s       = $this->warmup->todayStats();
        $pct     = $s['limit'] > 0 ? min(100, round($s['sent'] / $s['limit'] * 100)) : 0;
	        $nextStr = $s['next_time'] ? date('H:i:s', $s['next_time']) : $this->_('—');
	        $integrations = !empty($s['integrations'])
	            ? $this->e(implode(', ', $s['integrations']))
	            : '<span style="color:#e74c3c">' . $this->_('None') . '</span>';
	        $winStr  = $s['within_window']
            ? '<span style="color:#2ecc71">&#10003; ' . $this->_('Active') . '</span>'
            : '<span style="color:#e74c3c">&#10007; ' . $this->_('Outside window') . '</span>';

        $barColor = $pct >= 100 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : '#2ecc71');

        return "
        <div class='pw-warmup-card'>
	            <h2>" . $this->_('Today') . " &mdash; " . $this->e($s['date']) . "</h2>
	            <table class='AdminDataTable pw-warmup-today'>
	                <tr><th>" . $this->_('Send window') . "</th><td>{$winStr}</td></tr>
	                <tr><th>" . $this->_('Sent today') . "</th><td><strong>" . (int) $s['sent'] . "</strong></td></tr>
	                <tr><th>" . $this->_('Daily limit') . "</th><td><strong>" . (int) $s['limit'] . "</strong></td></tr>
	                <tr><th>" . $this->_('Remaining') . "</th><td>" . (int) $s['remaining'] . "</td></tr>
	                <tr><th>" . $this->_('Next send at') . "</th><td>" . $this->e($nextStr) . "</td></tr>
	                <tr><th>" . $this->_('Integrations') . "</th><td>{$integrations}</td></tr>
	            </table>
            <div class='pw-warmup-bar-wrap'>
                <div class='pw-warmup-bar' style='width:{$pct}%;background:{$barColor}'></div>
                <span class='pw-warmup-bar-label'>{$pct}%</span>
            </div>
        </div>";
    }

    protected function renderRampupCard(): string {
        $rampupDays  = (int) $this->warmup->rampupDays;
        $rampupStart = (int) $this->warmup->rampupStart;

        if(!$rampupDays) {
            return "<div class='pw-warmup-card pw-warmup-card--dim'>
                <h2>" . $this->_('Ramp-up') . "</h2>
                <p>" . $this->_('Ramp-up is disabled. Random limits are applied from the full Min–Max range immediately.') . "</p>
            </div>";
        }

        if(!$rampupStart) {
            return "<div class='pw-warmup-card pw-warmup-card--warn'>
                <h2>" . $this->_('Ramp-up') . "</h2>
                <p>" . sprintf($this->_('Ramp-up is configured for %d days but has not been started yet.'), $rampupDays) . "</p>
                <form method='post'>
                    <input type='hidden' name='warmup_action' value='start_rampup'>
                    " . $this->wire()->session->CSRF->renderInput() . "
                    <button type='submit' class='ui-button ui-state-default'><span class='ui-button-text'>" . $this->_('Start ramp-up now') . "</span></button>
                </form>
            </div>";
        }

        $daysElapsed  = max(0, (int) floor((time() - $rampupStart) / 86400));
        $daysLeft     = max(0, $rampupDays - $daysElapsed);
        $progress     = min(1.0, $daysElapsed / $rampupDays);
        $pct          = round($progress * 100);
        $todayLimit   = $this->warmup->getDailyLimit();

        $statusStr = $daysLeft > 0
            ? sprintf($this->_('Day %d of %d — %d days remaining'), $daysElapsed + 1, $rampupDays, $daysLeft)
            : '<span style="color:#2ecc71"><strong>' . $this->_('Ramp-up complete!') . '</strong></span>';

        return "
        <div class='pw-warmup-card'>
            <h2>" . $this->_('Ramp-up progress') . "</h2>
            <table class='AdminDataTable'>
                <tr><th>" . $this->_('Started') . "</th><td>" . date('Y-m-d', $rampupStart) . "</td></tr>
                <tr><th>" . $this->_('Period') . "</th><td>{$rampupDays} " . $this->_('days') . "</td></tr>
                <tr><th>" . $this->_('Status') . "</th><td>{$statusStr}</td></tr>
                <tr><th>" . $this->_('Today\'s limit') . "</th><td><strong>{$todayLimit}</strong></td></tr>
            </table>
            <div class='pw-warmup-bar-wrap'>
                <div class='pw-warmup-bar' style='width:{$pct}%;background:#3498db'></div>
                <span class='pw-warmup-bar-label'>{$pct}%</span>
            </div>
        </div>";
    }

    // =========================================================================
    // Chart
    // =========================================================================

    protected function renderChart(): string {
        $log     = $this->warmup->getLog(30);
        if(!$log) return '';

        // Reverse for chronological order in chart
        $reversed = array_reverse($log);
        $labels   = [];
        $sent     = [];
        $limits   = [];

        foreach($reversed as $row) {
            $labels[] = $row['date'];
            $sent[]   = (int) $row['count'];
            $limits[] = (int) $row['limit'];
        }

        $labelsJson = json_encode($labels);
        $sentJson   = json_encode($sent);
        $limitsJson = json_encode($limits);

        return "
        <div class='pw-warmup-card'>
            <h2>" . $this->_('Last 30 days') . "</h2>
            <canvas id='pw-warmup-chart' height='80'></canvas>
        </div>
	        <script>
	        (function() {
	            var canvas = document.getElementById('pw-warmup-chart');
	            if(!canvas || !canvas.getContext) return;
	            var labels = {$labelsJson};
	            var sent = {$sentJson};
	            var limits = {$limitsJson};
	            var ctx = canvas.getContext('2d');
	            var ratio = window.devicePixelRatio || 1;
	            var width = canvas.parentNode ? canvas.parentNode.clientWidth : 800;
	            var height = 260;
	            canvas.width = width * ratio;
	            canvas.height = height * ratio;
	            canvas.style.width = width + 'px';
	            canvas.style.height = height + 'px';
	            ctx.scale(ratio, ratio);
	            ctx.clearRect(0, 0, width, height);

	            var pad = { top: 24, right: 18, bottom: 44, left: 38 };
	            var chartW = width - pad.left - pad.right;
	            var chartH = height - pad.top - pad.bottom;
	            var max = Math.max(1, Math.max.apply(null, sent.concat(limits)));
	            var count = Math.max(1, labels.length);
	            var gap = 4;
	            var barW = Math.max(3, (chartW / count) - gap);

	            ctx.strokeStyle = '#e0e0e0';
	            ctx.fillStyle = '#777';
	            ctx.font = '11px sans-serif';
	            ctx.textAlign = 'right';
	            ctx.textBaseline = 'middle';
	            for(var y = 0; y <= 4; y++) {
	                var value = Math.round(max / 4 * y);
	                var py = pad.top + chartH - (chartH * y / 4);
	                ctx.beginPath();
	                ctx.moveTo(pad.left, py);
	                ctx.lineTo(width - pad.right, py);
	                ctx.stroke();
	                ctx.fillText(value, pad.left - 6, py);
	            }

	            ctx.fillStyle = 'rgba(52,152,219,0.75)';
	            for(var i = 0; i < count; i++) {
	                var x = pad.left + i * (chartW / count) + gap / 2;
	                var h = chartH * sent[i] / max;
	                ctx.fillRect(x, pad.top + chartH - h, barW, h);
	            }

	            ctx.strokeStyle = 'rgba(231,76,60,0.9)';
	            ctx.lineWidth = 2;
	            ctx.beginPath();
	            for(var j = 0; j < count; j++) {
	                var px = pad.left + j * (chartW / count) + (chartW / count) / 2;
	                var py2 = pad.top + chartH - (chartH * limits[j] / max);
	                if(j === 0) ctx.moveTo(px, py2);
	                else ctx.lineTo(px, py2);
	            }
	            ctx.stroke();

	            ctx.fillStyle = '#666';
	            ctx.textAlign = 'center';
	            ctx.textBaseline = 'top';
	            for(var k = 0; k < count; k += Math.ceil(count / 8)) {
	                var lx = pad.left + k * (chartW / count) + (chartW / count) / 2;
	                ctx.fillText(labels[k].slice(5), lx, height - pad.bottom + 12);
	            }
	        })();
	        </script>";
    }

    // =========================================================================
    // Log table
    // =========================================================================

    protected function renderLogTable(): string {
        $log = $this->warmup->getLog(30);
        if(!$log) {
            return "<div class='pw-warmup-card pw-warmup-card--dim'><p>" . $this->_('No log data yet.') . "</p></div>";
        }

        $rows = '';
        foreach($log as $row) {
            $pct   = $row['limit'] > 0 ? min(100, round($row['count'] / $row['limit'] * 100)) : 0;
            $color = $pct >= 100 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : ($pct > 0 ? '#2ecc71' : '#aaa'));
            $bar   = "<div style='display:inline-block;width:{$pct}px;max-width:100px;height:8px;background:{$color};border-radius:4px;vertical-align:middle'></div> {$pct}%";

            $rows .= "<tr>
	                <td>" . $this->e($row['date']) . "</td>
	                <td style='text-align:center'><strong>" . (int) $row['count'] . "</strong></td>
	                <td style='text-align:center'>" . (int) $row['limit'] . "</td>
	                <td>{$bar}</td>
	            </tr>";
        }

        return "
        <div class='pw-warmup-card'>
            <h2>" . $this->_('Daily log') . "</h2>
            <table class='AdminDataTable AdminDataList'>
                <thead><tr>
                    <th>" . $this->_('Date') . "</th>
                    <th style='text-align:center'>" . $this->_('Sent') . "</th>
                    <th style='text-align:center'>" . $this->_('Limit') . "</th>
                    <th>" . $this->_('Progress') . "</th>
                </tr></thead>
                <tbody>{$rows}</tbody>
            </table>
        </div>";
    }

    // =========================================================================
    // Actions
    // =========================================================================

    protected function renderActions(): string {
        $csrf = $this->wire()->session->CSRF->renderInput();
        return "
        <div class='pw-warmup-card'>
            <h2>" . $this->_('Actions') . "</h2>
            <form method='post' style='display:inline-block;margin-right:10px'>
                <input type='hidden' name='warmup_action' value='reset_counter'>
                {$csrf}
                <button type='submit' class='ui-button ui-state-default' onclick='return confirm(" . json_encode($this->_('Reset today\'s counter?')) . ")'>
                    <span class='ui-button-text'>" . $this->_('Reset today\'s counter') . "</span>
                </button>
            </form>
            <form method='post' style='display:inline-block;margin-right:10px'>
                <input type='hidden' name='warmup_action' value='clear_log'>
                {$csrf}
                <button type='submit' class='ui-button ui-state-default' onclick='return confirm(" . json_encode($this->_('Clear all log data?')) . ")'>
                    <span class='ui-button-text'>" . $this->_('Clear log') . "</span>
                </button>
            </form>
            <a href='" . $this->wire()->config->urls->admin . "module/edit?name=WarmUp' class='ui-button ui-state-default'>
                <span class='ui-button-text'>&#9881; " . $this->_('Settings') . "</span>
            </a>
        </div>";
    }

    // =========================================================================
    // CSS
    // =========================================================================

    public function ___execute() {
        // Inject styles inline so AdminTheme can't override them
        $css = "
        <style>
        .pw-warmup-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .pw-warmup-card h2 {
            margin: 0 0 14px;
            font-size: 16px;
            font-weight: 600;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .pw-warmup-card--dim { background: #fafafa; }
        .pw-warmup-card--warn { border-color: #f39c12; background: #fffbf0; }
        .pw-warmup-today th { width: 160px; }
        .pw-warmup-bar-wrap {
            margin-top: 12px;
            background: #f0f0f0;
            border-radius: 6px;
            height: 18px;
            position: relative;
            overflow: hidden;
        }
        .pw-warmup-bar {
            height: 100%;
            border-radius: 6px;
            transition: width .4s;
        }
        .pw-warmup-bar-label {
            position: absolute;
            right: 8px;
            top: 0;
            line-height: 18px;
            font-size: 11px;
            font-weight: 600;
            color: #555;
        }
        </style>
        ";

        return $css . $this->renderDashboard();
    }

    protected function e($value): string {
        return $this->wire()->sanitizer->entities((string) $value);
    }
}
