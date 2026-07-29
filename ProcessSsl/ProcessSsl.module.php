<?php namespace ProcessWire;

/**
 * ProcessWire SSL Manager
 *
 * Monitor SSL certificates of your domains (live expiry resolution),
 * add / remove domains, and generate Certificate Signing Requests (CSR).
 *
 * @author Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @license MIT
 *
 * @property int    $warnDays
 * @property int    $cacheTtl
 * @property int    $connectTimeout
 * @property int    $defaultKeyBits
 * @property string $defaultCountry
 * @property string $defaultState
 * @property string $defaultLocality
 * @property string $defaultOrg
 * @property string $defaultOU
 * @property string $defaultEmail
 * @property int    $enableAlerts
 * @property string $alertEmail
 * @property int    $reAlertDays
 * @property string $mailModule
 */

require_once __DIR__ . '/src/SslService.php';

class ProcessSsl extends Process implements ConfigurableModule {

    const TABLE_NAME = 'process_ssl_domains';
    const ADMIN_PAGE = 'ssl-manager';

    /** @var SslService */
    protected $service;

    /**
     * Lazily-built OpenSSL/TLS service.
     */
    protected function service() {
        if($this->service === null) {
            $this->service = new SslService($this->connectTimeout);
        }
        return $this->service;
    }

    /**
     * Register CSS/JS for the admin pages.
     */
    protected function addAssets() {
        $config = $this->wire('config');
        $info = self::getModuleInfo();
        $v = $info['version'];
        $url = $config->urls->$this . 'assets/';
        $config->styles->add($url . 'ssl.css?v=' . $v);
        $config->scripts->add($url . 'ssl.js?v=' . $v);
    }

    public static function getModuleInfo() {
        return [
            'title' => 'SSL Manager',
            'summary' => 'Monitor SSL certificate expiry, manage domains and generate CSRs',
            'version' => '1.1.0',
            'author' => 'Maxim Semenov',
            'href' => 'https://smnv.org',
            'icon' => 'lock',
            'requires' => 'ProcessWire>=3.0.0',
            'autoload' => true, // lightweight init() wires expiry alerts (LazyCron) + admin notice
            'singular' => true,
            'permission' => 'ssl-manager',
            'permissions' => [
                'ssl-manager' => 'Use the SSL Manager',
            ],
            'nav' => [
                ['url' => '', 'label' => 'Certificates', 'icon' => 'list'],
                ['url' => 'add/', 'label' => 'Add domain', 'icon' => 'plus'],
                ['url' => 'csr/', 'label' => 'Generate CSR', 'icon' => 'file-text-o'],
                ['url' => 'check/', 'label' => 'Check CSR', 'icon' => 'search'],
                ['url' => 'cert/', 'label' => 'Decode Cert', 'icon' => 'certificate'],
                ['url' => 'selfsigned/', 'label' => 'Self-signed', 'icon' => 'magic'],
            ],
        ];
    }

    /* ----------------------------------------------------------------------
     * Configuration defaults
     * -------------------------------------------------------------------- */

    public function __construct() {
        parent::__construct();
        $this->set('warnDays', 30);
        $this->set('cacheTtl', 3600);
        $this->set('connectTimeout', 10);
        $this->set('defaultKeyBits', 2048);
        $this->set('defaultCountry', '');
        $this->set('defaultState', '');
        $this->set('defaultLocality', '');
        $this->set('defaultOrg', '');
        $this->set('defaultOU', '');
        $this->set('defaultEmail', '');
        $this->set('enableAlerts', 0);
        $this->set('alertEmail', '');
        $this->set('reAlertDays', 7);
        $this->set('mailModule', '');
    }

    /**
     * Runs on every admin request (autoload=template=admin).
     * Wires up the daily expiry-alert cron and a one-per-session admin notice.
     */
    public function init() {
        parent::init();

        // Daily email alerts via LazyCron (only if alerts enabled and LazyCron present)
        if($this->enableAlerts && $this->wire('modules')->isInstalled('LazyCron')) {
            $this->addHook('LazyCron::everyDay', $this, 'cronCheckExpiry');
        }

        // Admin notice once per session if something needs attention
        if($this->wire('page') && $this->wire('page')->template == 'admin') {
            $this->addHookAfter('ProcessController::execute', $this, 'maybeNotice');
        }
    }

    /* ----------------------------------------------------------------------
     * Pages
     * -------------------------------------------------------------------- */

    /**
     * Main view: list of monitored domains + live certificate status
     */
    public function execute() {
        $this->headline($this->_('SSL Certificates'));
        $this->addAssets();

        $refresh = (bool) $this->input->get('refresh');
        if($refresh) {
            $this->wire('cache')->deleteFor($this);
            $this->message($this->_('Certificate cache cleared, statuses re-checked.'));
            $this->wire('session')->redirect('./');
        }

        $domains = $this->getDomains();

        $out  = '<div class="ssl-manager">';
        $out .= $this->renderToolbar();
        $out .= $this->renderSummary($domains);
        $out .= $this->renderTable($domains);
        $out .= $this->renderFooter();
        $out .= '</div>';

        return $out;
    }

    /**
     * Add a domain to monitor
     */
    public function executeAdd() {
        $this->headline($this->_('Add domain'));
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        // Import all hosts of this site
        if($this->input->get('import')) {
            $session = $this->wire('session');
            $tokenName = $session->CSRF->getTokenName();
            if((string) $this->input->get($tokenName) !== $session->CSRF->getTokenValue()) {
                $this->error($this->_('Security token mismatch.'));
                $session->redirect('./');
                return '';
            }
            $hosts = (array) $this->wire('config')->httpHosts;
            $hosts = array_map(function($h) { return preg_replace('/:\d+$/', '', $h); }, $hosts);
            $this->addDomainsBulk($hosts);
            return '';
        }

        /** @var InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->action = './';
        $form->method = 'post';

        $f = $this->modules->get('InputfieldText');
        $f->attr('name', 'host');
        $f->label = $this->_('Domain / host name');
        $f->description = $this->_('Example: example.com (no scheme, no path).');
        $f->columnWidth = 70;
        $form->add($f);

        $f = $this->modules->get('InputfieldInteger');
        $f->attr('name', 'port');
        $f->label = $this->_('Port');
        $f->attr('value', 443);
        $f->columnWidth = 30;
        $form->add($f);

        $f = $this->modules->get('InputfieldText');
        $f->attr('name', 'note');
        $f->label = $this->_('Note');
        $f->description = $this->_('Optional label, e.g. who owns this or where it is hosted.');
        $form->add($f);

        // Action buttons: real submit for single add + a toggle for the bulk block
        $addLabel = $this->_('Add domain');
        $multiLabel = $this->_('Add multiple');
        $m = $this->modules->get('InputfieldMarkup');
        $m->skipLabel = Inputfield::skipLabelBlank;
        $m->value = '<div class="ssl-toolbar">'
            . '<button type="submit" name="submit_add" value="1" class="uk-button uk-button-primary">'
            . '<i class="fa fa-plus"></i> ' . $addLabel . '</button>'
            . '<button type="button" class="uk-button uk-button-default" onclick="sslToggleBulk()">'
            . '<i class="fa fa-list"></i> ' . $multiLabel . '</button>'
            . '</div>';
        $form->add($m);

        // Bulk add
        $fs = $this->modules->get('InputfieldFieldset');
        $fs->attr('name', 'bulkadd');
        $fs->label = $this->_('Add multiple');
        $fs->icon = 'list';
        $fs->collapsed = Inputfield::collapsedYes;

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'hosts');
        $f->label = $this->_('Hosts (one per line)');
        $f->description = $this->_('Each host is added on port 443.');
        $f->rows = 6;
        $fs->add($f);

        $submit = $this->modules->get('InputfieldSubmit');
        $submit->attr('name', 'submit_bulk');
        $submit->value = $this->_('Add all');
        $fs->add($submit);
        $form->add($fs);

        if($this->input->post('submit_add')) {
            $form->processInput($this->input->post);
            $host = trim((string) $form->get('host')->value);
            if($host === '') {
                $this->error($this->_('Please enter a host name.'));
            } elseif(!$form->getErrors()) {
                $this->saveDomain($host, (int) $form->get('port')->value, $form->get('note')->value);
            }
        } elseif($this->input->post('submit_bulk')) {
            $form->processInput($this->input->post);
            $lines = preg_split('/[\r\n]+/', (string) $form->get('hosts')->value);
            $lines = array_filter(array_map('trim', $lines));
            if($lines) $this->addDomainsBulk($lines);
            else $this->error($this->_('Please paste at least one host.'));
        }

        // Top toolbar: back to list + import this site's hosts
        $csrf = $this->wire('session')->CSRF;
        $importUrl = './?import=1&' . $csrf->getTokenName() . '=' . $csrf->getTokenValue();
        $hostCount = count((array) $this->wire('config')->httpHosts);
        $top = '<div class="ssl-manager"><div class="ssl-toolbar">'
            . '<a href="../" class="uk-button uk-button-default"><i class="fa fa-chevron-left"></i> ' . $this->_('Back') . '</a>'
            . '<a href="' . $importUrl . '" class="uk-button uk-button-default"><i class="fa fa-download"></i> '
            . sprintf($this->_('Import %d host(s) from this site'), $hostCount) . '</a>'
            . '</div></div>';

        return $top . $form->render();
    }

    /**
     * Delete a monitored domain (CSRF-protected link from the table)
     */
    public function executeDelete() {
        $session = $this->wire('session');

        // CSRF for a GET action link: validate the token by hand (validate() only checks POST)
        $tokenName = $session->CSRF->getTokenName();
        $tokenValue = $session->CSRF->getTokenValue();
        if((string) $this->input->get($tokenName) !== $tokenValue) {
            $this->error($this->_('Security token mismatch, nothing was deleted.'));
            $session->redirect('../');
            return;
        }

        $id = (int) $this->input->get('id');
        $domain = $this->getDomain($id);
        if($domain) {
            $stmt = $this->wire('database')->prepare(
                "DELETE FROM `" . self::TABLE_NAME . "` WHERE id = :id"
            );
            $stmt->execute([':id' => $id]);
            $this->wire('cache')->deleteFor($this);
            $this->message(sprintf($this->_('Removed domain: %s'), $domain['host']));
        } else {
            $this->error($this->_('Domain not found.'));
        }

        $this->wire('session')->redirect('../');
    }

    /**
     * Generate a Certificate Signing Request
     */
    public function executeCsr() {
        $this->headline($this->_('Generate CSR'));
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        $form = $this->buildCsrForm();

        if($this->input->post('submit_csr')) {
            $form->processInput($this->input->post);
            if(!$form->getErrors()) {
                $result = $this->generateCsr($form);
                if($result) {
                    return $this->renderCsrResult($result);
                }
            }
        }

        return $form->render();
    }

    /**
     * Generate a self-signed certificate (dev / local use)
     */
    public function executeSelfsigned() {
        $this->headline($this->_('Self-signed certificate'));
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        /** @var InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->method = 'post';
        $form->action = './';
        $form->description = $this->_('Generates a self-signed certificate and key for local development or testing. Browsers will not trust it.');

        $f = $this->modules->get('InputfieldText');
        $f->attr('name', 'cn');
        $f->label = $this->_('Common Name (CN)');
        $f->description = $this->_('e.g. localhost or dev.example.com');
        $f->required = true;
        $f->columnWidth = 50;
        $form->add($f);

        $f = $this->modules->get('InputfieldInteger');
        $f->attr('name', 'days');
        $f->label = $this->_('Valid for (days)');
        $f->attr('value', 365);
        $f->columnWidth = 25;
        $form->add($f);

        $f = $this->modules->get('InputfieldSelect');
        $f->attr('name', 'bits');
        $f->label = $this->_('Key size');
        $f->addOption(2048, '2048 bit');
        $f->addOption(4096, '4096 bit');
        $f->attr('value', (int) $this->defaultKeyBits);
        $f->columnWidth = 25;
        $form->add($f);

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'san');
        $f->label = $this->_('Subject Alternative Names (SAN)');
        $f->description = $this->_('Optional. One host per line.');
        $f->rows = 3;
        $form->add($f);

        $submit = $this->modules->get('InputfieldSubmit');
        $submit->attr('name', 'submit_selfsigned');
        $submit->value = $this->_('Generate');
        $form->add($submit);

        if($this->input->post('submit_selfsigned')) {
            $form->processInput($this->input->post);
            if(!$form->getErrors()) {
                $result = $this->generateSelfSigned($form);
                if($result) return $this->renderSelfSignedResult($result);
            }
        }

        return $form->render();
    }

    /**
     * Decode / verify an existing CSR
     */
    public function executeCheck() {
        $this->headline($this->_('Check CSR'));
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        /** @var InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->method = 'post';
        $form->action = './';

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'csr');
        $f->label = $this->_('Paste a CSR');
        $f->description = $this->_('The PEM block starting with -----BEGIN CERTIFICATE REQUEST-----');
        $f->rows = 8;
        $f->required = true;
        $form->add($f);

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'key');
        $f->label = $this->_('Private key (optional)');
        $f->description = $this->_('Provide it to verify that the key matches this CSR. It is only used in memory, never stored.');
        $f->rows = 6;
        $f->collapsed = Inputfield::collapsedYes;
        $form->add($f);

        $submit = $this->modules->get('InputfieldSubmit');
        $submit->attr('name', 'submit_check');
        $submit->value = $this->_('Check CSR');
        $form->add($submit);

        $out = '';
        if($this->input->post('submit_check')) {
            $form->processInput($this->input->post);
            if(!$form->getErrors()) {
                $out .= $this->renderCheckResult(
                    (string) $form->get('csr')->value,
                    (string) $form->get('key')->value
                );
            }
        }

        return $form->render() . $out;
    }

    /**
     * Decode a certificate (paste a .crt / PEM block)
     */
    public function executeCert() {
        $this->headline($this->_('Decode Certificate'));
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        /** @var InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->method = 'post';
        $form->action = './';

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'cert');
        $f->label = $this->_('Paste a certificate');
        $f->description = $this->_('The PEM block starting with -----BEGIN CERTIFICATE-----');
        $f->rows = 10;
        $f->required = true;
        $form->add($f);

        $submit = $this->modules->get('InputfieldSubmit');
        $submit->attr('name', 'submit_cert');
        $submit->value = $this->_('Decode');
        $form->add($submit);

        $out = '';
        if($this->input->post('submit_cert')) {
            $form->processInput($this->input->post);
            if(!$form->getErrors()) {
                $out .= $this->renderCertResult((string) $form->get('cert')->value);
            }
        }

        return $form->render() . $out;
    }

    protected function renderCertResult($pem) {
        $c = $this->service()->inspectCertificate($pem);
        if(!empty($c['error'])) {
            $this->error($this->_($c['error']));
            return '';
        }

        $sanitizer = $this->wire('sanitizer');
        $daysLeft = (int) $c['daysLeft'];

        if($daysLeft < 0) $badge = '<span class="uk-label uk-label-danger">' . $this->_('Expired') . '</span>';
        elseif($daysLeft <= $this->warnDays) $badge = '<span class="uk-label uk-label-warning">' . $this->_('Expiring') . '</span>';
        else $badge = '<span class="uk-label uk-label-success">' . $this->_('Valid') . '</span>';

        $rows = [
            $this->_('Status') => $badge . ' &nbsp; ' . ($daysLeft < 0
                ? sprintf($this->_('%d days ago'), abs($daysLeft))
                : sprintf($this->_('%d days left'), $daysLeft)),
            $this->_('Common Name') => $sanitizer->entities($c['cn'] ?: '—'),
            $this->_('Alt names (SAN)') => $this->formatSan($c['san']) ?: '&mdash;',
            $this->_('Organization') => $sanitizer->entities($c['org'] ?: '—'),
            $this->_('Issuer') => $sanitizer->entities($c['issuer'] ?: '—'),
            $this->_('Valid from') => $c['validFrom'] ? date('Y-m-d H:i', $c['validFrom']) : '—',
            $this->_('Valid to') => $c['validTo'] ? date('Y-m-d H:i', $c['validTo']) : '—',
            $this->_('Self-signed') => $c['selfSigned'] ? $this->_('Yes') : $this->_('No'),
            $this->_('Signature') => $sanitizer->entities($c['sigType'] ?: '—'),
            $this->_('Serial') => $sanitizer->entities((string) ($c['serial'] ?: '—')),
            $this->_('SHA-256 fingerprint') => $c['fingerprint']
                ? '<code>' . $sanitizer->entities($this->formatFingerprint($c['fingerprint'])) . '</code>' : '&mdash;',
        ];

        $body = '<div class="ssl-manager"><h2>' . $this->_('Certificate details') . '</h2>'
            . '<table class="uk-table uk-table-divider uk-table-small ssl-check-table"><tbody>';
        foreach($rows as $label => $value) {
            $body .= '<tr><td>' . $sanitizer->entities($label) . '</td><td>' . $value . '</td></tr>';
        }
        return $body . '</tbody></table></div>';
    }

    /**
     * Turn a raw subjectAltName string ("DNS:a, DNS:b") into a clean, escaped list.
     */
    protected function formatSan($san) {
        if(empty($san)) return '';
        $sanitizer = $this->wire('sanitizer');
        $parts = array_map('trim', explode(',', $san));
        $parts = array_map(function($p) { return preg_replace('/^DNS:/i', '', $p); }, $parts);
        return implode(', ', array_map([$sanitizer, 'entities'], $parts));
    }

    /**
     * Parse a CSR and (optionally) verify a private key against it.
     */
    protected function renderCheckResult($csrPem, $keyPem) {
        $r = $this->service()->inspectCsr($csrPem, $keyPem);
        if(!empty($r['error'])) {
            $this->error($this->_($r['error']));
            return '';
        }

        $subject = $r['subject'];
        $dnLabels = [
            'CN' => $this->_('Common Name'),
            'O'  => $this->_('Organization'),
            'OU' => $this->_('Organizational Unit'),
            'C'  => $this->_('Country'),
            'ST' => $this->_('State / Province'),
            'L'  => $this->_('Locality'),
            'emailAddress' => $this->_('Email'),
        ];

        $sanitizer = $this->wire('sanitizer');
        $rows = '';
        foreach($dnLabels as $key => $label) {
            if(empty($subject[$key])) continue;
            $val = is_array($subject[$key]) ? implode(', ', $subject[$key]) : $subject[$key];
            $rows .= '<tr><td>' . $sanitizer->entities($label) . '</td><td><strong>'
                . $sanitizer->entities($val) . '</strong></td></tr>';
        }
        $rows .= '<tr><td>' . $this->_('Key') . '</td><td><strong>'
            . $sanitizer->entities($r['keyType'] . ', ' . $r['keyBits'] . ' bit') . '</strong></td></tr>';

        // Optional key match verification
        $matchRow = '';
        if($r['matchError']) {
            $matchRow = '<div class="ssl-info ssl-info-warn">'
                . $this->_('The provided private key could not be read.') . '</div>';
        } elseif($r['match'] === true) {
            $matchRow = '<div class="ssl-info ssl-match-ok"><i class="fa fa-check"></i> '
                . $this->_('The private key matches this CSR.') . '</div>';
        } elseif($r['match'] === false) {
            $matchRow = '<div class="ssl-info ssl-match-bad"><i class="fa fa-times"></i> '
                . $this->_('The private key does NOT match this CSR.') . '</div>';
        }

        return '<div class="ssl-manager"><h2>' . $this->_('CSR details') . '</h2>'
            . $matchRow
            . '<table class="uk-table uk-table-divider uk-table-small ssl-check-table"><tbody>'
            . $rows . '</tbody></table></div>';
    }

    /**
     * Export the monitored domains and their current status as CSV.
     */
    public function executeExport() {
        $domains = $this->getDomains();

        $rows = [];
        $rows[] = ['host', 'port', 'status', 'expires', 'days_left', 'issuer', 'note'];
        foreach($domains as $d) {
            $info = $this->getCertInfo($d['host'], (int) $d['port']);
            if(!empty($info['error'])) {
                $status = 'error'; $expires = ''; $days = ''; $issuer = $info['error'];
            } else {
                $days = (int) $info['daysLeft'];
                $status = $days < 0 ? 'expired' : ($days <= $this->warnDays ? 'expiring' : 'valid');
                $expires = date('Y-m-d H:i', $info['validTo']);
                $issuer = $info['issuer'];
            }
            $rows[] = [$d['host'], (int) $d['port'], $status, $expires, $days, $issuer, $d['note']];
        }

        $fh = fopen('php://temp', 'r+');
        foreach($rows as $r) fputcsv($fh, $r);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ssl-domains-' . date('Y-m-d') . '.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    /**
     * Domain detail page: full live certificate information.
     */
    public function executeView() {
        $id = (int) $this->input->get('id');
        $domain = $this->getDomain($id);
        if(!$domain) {
            $this->error($this->_('Domain not found.'));
            $this->wire('session')->redirect('../');
            return;
        }

        $host = $domain['host'];
        $port = (int) $domain['port'];

        $this->headline($host);
        $this->breadcrumbs->add(new Breadcrumb('../', $this->_('SSL Certificates')));
        $this->addAssets();

        if($this->input->get('refresh')) {
            $this->wire('cache')->deleteFor($this);
            $this->wire('session')->redirect('./?id=' . $id);
        }

        $info = $this->getCertInfo($host, $port);
        $sanitizer = $this->wire('sanitizer');

        $out = '<div class="ssl-manager">';
        $out .= '<div class="ssl-toolbar">';
        $out .= '<a href="../" class="uk-button uk-button-default"><i class="fa fa-chevron-left"></i> Back</a>';
        $out .= '<a href="./?id=' . $id . '&refresh=1" class="uk-button uk-button-default"><i class="fa fa-refresh"></i> Re-check</a>';
        $out .= '</div>';

        if(!empty($info['error'])) {
            $out .= '<div class="ssl-info ssl-match-bad"><i class="fa fa-times"></i> '
                . $sanitizer->entities($info['error']) . '</div></div>';
            return $out;
        }

        $days = (int) $info['daysLeft'];
        if($days < 0) $badge = '<span class="uk-label uk-label-danger">' . $this->_('Expired') . '</span>';
        elseif($days <= $this->warnDays) $badge = '<span class="uk-label uk-label-warning">' . $this->_('Expiring') . '</span>';
        else $badge = '<span class="uk-label uk-label-success">' . $this->_('Valid') . '</span>';

        $sanList = $this->formatSan($info['san']);
        $chain = !empty($info['chain'])
            ? implode(' &rarr; ', array_map([$sanitizer, 'entities'], $info['chain'])) : '';

        $rows = [
            $this->_('Host') => $sanitizer->entities($host . ':' . $port),
            $this->_('Status') => $badge . ' &nbsp; ' . ($days < 0
                ? sprintf($this->_('%d days ago'), abs($days))
                : sprintf($this->_('%d days left'), $days)),
            $this->_('Common Name') => $sanitizer->entities($info['cn']),
            $this->_('Alt names (SAN)') => $sanList ?: '&mdash;',
            $this->_('Issuer') => $sanitizer->entities($info['issuer']),
            $this->_('Valid from') => date('Y-m-d H:i', $info['validFrom']),
            $this->_('Valid to') => date('Y-m-d H:i', $info['validTo']),
            $this->_('Self-signed') => !empty($info['selfSigned']) ? $this->_('Yes') : $this->_('No'),
            $this->_('Signature') => $sanitizer->entities($info['sigType'] ?: '—'),
            $this->_('Serial') => $sanitizer->entities((string) ($info['serial'] ?: '—')),
            $this->_('SHA-256 fingerprint') => $info['fingerprint']
                ? '<code>' . $sanitizer->entities($this->formatFingerprint($info['fingerprint'])) . '</code>' : '&mdash;',
            $this->_('TLS') => trim($sanitizer->entities($info['protocol'] . ' ' . $info['cipher'])) ?: '&mdash;',
            $this->_('Checked') => $this->relativeTime($info['checkedAt']),
        ];

        $out .= '<table class="uk-table uk-table-divider uk-table-small ssl-check-table"><tbody>';
        foreach($rows as $label => $value) {
            $out .= '<tr><td>' . $sanitizer->entities($label) . '</td><td>' . $value . '</td></tr>';
        }
        $out .= '</tbody></table>';

        if($chain) {
            $out .= '<h3>' . $this->_('Certificate chain') . '</h3><p>' . $chain . '</p>';
        }

        $out .= '</div>';
        return $out;
    }

    protected function formatFingerprint($hex) {
        return implode(':', str_split(strtoupper($hex), 2));
    }

    /* ----------------------------------------------------------------------
     * Rendering
     * -------------------------------------------------------------------- */

    protected function renderToolbar() {
        return <<<HTML
<div class="ssl-toolbar">
    <a href="./add/" class="uk-button uk-button-primary"><i class="fa fa-plus"></i> Add domain</a>
    <a href="./csr/" class="uk-button uk-button-default"><i class="fa fa-file-text-o"></i> Generate CSR</a>
    <a href="./check/" class="uk-button uk-button-default"><i class="fa fa-search"></i> Check CSR</a>
    <a href="./?refresh=1" class="uk-button uk-button-default"><i class="fa fa-refresh"></i> Re-check now</a>
    <a href="./export/" class="uk-button uk-button-default"><i class="fa fa-table"></i> Export CSV</a>
</div>
HTML;
    }

    protected function renderSummary(array $domains) {
        $total = count($domains);
        $valid = $expiring = $problem = 0;

        foreach($domains as $d) {
            $info = $this->getCertInfo($d['host'], (int) $d['port']);
            if(!empty($info['error'])) { $problem++; continue; }
            $days = $info['daysLeft'];
            if($days < 0) $problem++;
            elseif($days <= $this->warnDays) $expiring++;
            else $valid++;
        }

        return <<<HTML
<div class="ssl-stats-grid">
    <div class="uk-card uk-card-default uk-card-body uk-card-small ssl-stat-card">
        <div class="ssl-stat-label">Monitored</div>
        <div class="ssl-stat-value">{$total}</div>
    </div>
    <div class="uk-card uk-card-default uk-card-body uk-card-small ssl-stat-card">
        <div class="ssl-stat-label">Valid</div>
        <div class="ssl-stat-value ssl-ok">{$valid}</div>
    </div>
    <div class="uk-card uk-card-default uk-card-body uk-card-small ssl-stat-card">
        <div class="ssl-stat-label">Expiring soon</div>
        <div class="ssl-stat-value ssl-warn">{$expiring}</div>
    </div>
    <div class="uk-card uk-card-default uk-card-body uk-card-small ssl-stat-card">
        <div class="ssl-stat-label">Problem / expired</div>
        <div class="ssl-stat-value ssl-danger">{$problem}</div>
    </div>
</div>
HTML;
    }

    protected function renderTable(array $domains) {
        if(empty($domains)) {
            return '<div class="ssl-info">' .
                $this->_('No domains yet. Click "Add domain" to start monitoring an SSL certificate.') .
                '</div>';
        }

        $sanitizer = $this->wire('sanitizer');
        $csrf = $this->wire('session')->CSRF;

        $out  = '<div class="uk-overflow-auto ssl-table-panel">';
        $out .= '<table id="ssl-table" class="uk-table uk-table-divider uk-table-hover uk-table-small uk-table-middle ssl-table">';
        $out .= '<thead><tr>';
        foreach(['Domain', 'Port', 'Status', 'Expires', 'Days left', 'Issuer', 'Checked', 'Note'] as $label) {
            $out .= '<th class="ssl-sortable">' . $label . '<span class="ssl-arrow"></span></th>';
        }
        $out .= '<th></th>';
        $out .= '</tr></thead><tbody>';

        foreach($domains as $d) {
            $host = $sanitizer->entities($d['host']);
            $port = (int) $d['port'];
            $note = $sanitizer->entities($d['note']);
            $info = $this->getCertInfo($d['host'], $port);

            if(!empty($info['error'])) {
                $statusBadge = '<span class="uk-label uk-label-danger">Error</span>';
                $expires = '&mdash;';
                $expiresSort = 0;
                $daysCell = $sanitizer->entities($info['error']);
                $daysSort = -100000; // sort errors/expired to the top
                $issuer = '&mdash;';
            } else {
                $days = $info['daysLeft'];
                if($days < 0) {
                    $statusBadge = '<span class="uk-label uk-label-danger">Expired</span>';
                } elseif($days <= $this->warnDays) {
                    $statusBadge = '<span class="uk-label uk-label-warning">Expiring</span>';
                } else {
                    $statusBadge = '<span class="uk-label uk-label-success">Valid</span>';
                }
                $expires = date('Y-m-d H:i', $info['validTo']);
                $expiresSort = (int) $info['validTo'];
                $daysCell = $days < 0
                    ? '<strong class="ssl-danger">' . $days . '</strong>'
                    : $days;
                $daysSort = (int) $days;
                $issuer = $sanitizer->entities($info['issuer']);
            }

            $checkedAt = (int) ($info['checkedAt'] ?? 0);
            $checkedCell = $checkedAt ? $this->relativeTime($checkedAt) : '&mdash;';

            $deleteUrl = './delete/?id=' . (int) $d['id'] .
                '&' . $csrf->getTokenName() . '=' . $csrf->getTokenValue();
            $viewUrl = './view/?id=' . (int) $d['id'];

            $out .= '<tr>';
            $out .= '<td><a href="' . $viewUrl . '"><strong>' . $host . '</strong></a></td>';
            $out .= '<td data-sort="' . $port . '">' . $port . '</td>';
            $out .= '<td data-sort="' . $daysSort . '">' . $statusBadge . '</td>';
            $out .= '<td data-sort="' . $expiresSort . '">' . $expires . '</td>';
            $out .= '<td data-sort="' . $daysSort . '">' . $daysCell . '</td>';
            $out .= '<td>' . $issuer . '</td>';
            $out .= '<td data-sort="' . $checkedAt . '">' . $checkedCell . '</td>';
            $out .= '<td>' . $note . '</td>';
            $out .= '<td class="uk-text-right"><a href="' . $deleteUrl . '" class="ssl-delete" ' .
                'onclick="return confirm(\'Remove ' . $host . '?\')" ' .
                'title="Remove"><i class="fa fa-trash"></i></a></td>';
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div>';
        return $out;
    }

    /**
     * Footer with a quick link to module settings.
     */
    protected function renderFooter() {
        $editUrl = $this->wire('config')->urls->admin . 'module/edit?name=' . $this->className();
        $label = $this->_('Settings');
        return <<<HTML
<div class="ssl-footer">
    <a href="{$editUrl}" class="ssl-settings-link">{$label}</a>
</div>
HTML;
    }

    protected function buildCsrForm() {
        /** @var InputfieldForm $form */
        $form = $this->modules->get('InputfieldForm');
        $form->method = 'post';
        $form->action = './';

        $f = $this->modules->get('InputfieldText');
        $f->attr('name', 'cn');
        $f->label = $this->_('Common Name (CN)');
        $f->description = $this->_('The primary domain, e.g. example.com or *.example.com');
        $f->required = true;
        $f->columnWidth = 60;
        $form->add($f);

        $f = $this->modules->get('InputfieldSelect');
        $f->attr('name', 'bits');
        $f->label = $this->_('Key size');
        $f->addOption(2048, '2048 bit');
        $f->addOption(4096, '4096 bit');
        $f->attr('value', (int) $this->defaultKeyBits);
        $f->columnWidth = 40;
        $form->add($f);

        $f = $this->modules->get('InputfieldTextarea');
        $f->attr('name', 'san');
        $f->label = $this->_('Subject Alternative Names (SAN)');
        $f->description = $this->_('Optional. One host per line, e.g. www.example.com');
        $f->rows = 4;
        $form->add($f);

        $fields = [
            'country'  => [$this->_('Country (2-letter code)'), $this->defaultCountry, 33],
            'state'    => [$this->_('State / Province'), $this->defaultState, 33],
            'locality' => [$this->_('Locality / City'), $this->defaultLocality, 34],
            'org'      => [$this->_('Organization'), $this->defaultOrg, 50],
            'ou'       => [$this->_('Organizational Unit'), $this->defaultOU, 50],
            'email'    => [$this->_('Email address'), $this->defaultEmail, 100],
        ];
        foreach($fields as $name => $cfg) {
            $f = $this->modules->get('InputfieldText');
            $f->attr('name', $name);
            $f->label = $cfg[0];
            $f->attr('value', $cfg[1]);
            $f->columnWidth = $cfg[2];
            $form->add($f);
        }

        $submit = $this->modules->get('InputfieldSubmit');
        $submit->attr('name', 'submit_csr');
        $submit->value = $this->_('Generate CSR');
        $form->add($submit);

        return $form;
    }

    protected function renderCsrResult(array $result) {
        $sanitizer = $this->wire('sanitizer');
        $csr = $sanitizer->entities($result['csr']);
        $key = $sanitizer->entities($result['key']);

        // Filename base from CN: wildcard -> "wildcard", keep host-safe chars
        $base = str_replace('*', 'wildcard', strtolower($result['cn']));
        $base = preg_replace('/[^a-z0-9._-]/', '_', $base);
        if($base === '') $base = 'certificate';
        $csrFile = $sanitizer->entities($base . '.csr');
        $keyFile = $sanitizer->entities($base . '.key');

        return <<<HTML
<div class="ssl-toolbar">
    <a href="../csr/" class="uk-button uk-button-default"><i class="fa fa-chevron-left"></i> Back</a>
</div>

<h2>Certificate Signing Request (CSR)</h2>
<div class="ssl-toolbar">
    <button type="button" class="uk-button uk-button-default" onclick="sslCopy('ssl-csr', this)"><i class="fa fa-copy"></i> Copy CSR</button>
    <button type="button" class="uk-button uk-button-default" onclick="sslDownload('ssl-csr','{$csrFile}')"><i class="fa fa-download"></i> Download CSR</button>
</div>
<textarea id="ssl-csr" class="ssl-pem" readonly onclick="this.select()">{$csr}</textarea>

<h2>Private Key</h2>
<div class="ssl-info ssl-info-warn">
    <strong>Save your private key now.</strong> It is shown only once and is <em>not</em> stored by this module.
    Keep it secret &mdash; you will need it to install the certificate (e.g. in VestaCP / CloudPanel).
</div>
<div class="ssl-toolbar">
    <button type="button" class="uk-button uk-button-default" onclick="sslCopy('ssl-key', this)"><i class="fa fa-copy"></i> Copy private key</button>
    <button type="button" class="uk-button uk-button-default" onclick="sslDownload('ssl-key','{$keyFile}')"><i class="fa fa-download"></i> Download private key</button>
</div>
<textarea id="ssl-key" class="ssl-pem" readonly onclick="this.select()">{$key}</textarea>
HTML;
    }

    /* ----------------------------------------------------------------------
     * SSL / CSR logic
     * -------------------------------------------------------------------- */

    /**
     * Resolve live certificate info for a host, cached per cacheTtl.
     *
     * @return array {validFrom,validTo,daysLeft,issuer,cn,san} or {error}
     */
    protected function getCertInfo($host, $port = 443) {
        $port = $port ?: 443;
        $cacheKey = 'cert_' . md5($host . ':' . $port);
        $ttl = (int) $this->cacheTtl;

        $self = $this;
        return $this->wire('cache')->getFor($this, $cacheKey, $ttl, function() use ($self, $host, $port) {
            return $self->service()->fetchCertInfo($host, $port);
        });
    }

    /**
     * Build the CSR + private key from submitted form values.
     *
     * @return array|null {csr,key} on success
     */
    protected function generateCsr(InputfieldForm $form) {
        $result = $this->service()->createCsr([
            'cn'       => $form->get('cn')->value,
            'bits'     => $form->get('bits')->value,
            'country'  => $form->get('country')->value,
            'state'    => $form->get('state')->value,
            'locality' => $form->get('locality')->value,
            'org'      => $form->get('org')->value,
            'ou'       => $form->get('ou')->value,
            'email'    => $form->get('email')->value,
            'san'      => $form->get('san')->value,
        ]);

        if(!empty($result['error'])) {
            $this->error($this->_($result['error']));
            return null;
        }
        $this->message($this->_('CSR generated successfully.'));
        return $result;
    }

    /**
     * Build a self-signed certificate + key.
     *
     * @return array|null {cert,key,cn}
     */
    protected function generateSelfSigned(InputfieldForm $form) {
        $result = $this->service()->createSelfSigned([
            'cn'   => $form->get('cn')->value,
            'bits' => $form->get('bits')->value,
            'days' => $form->get('days')->value,
            'san'  => $form->get('san')->value,
        ]);

        if(!empty($result['error'])) {
            $this->error($this->_($result['error']));
            return null;
        }
        $this->message($this->_('Self-signed certificate generated.'));
        return $result;
    }

    protected function renderSelfSignedResult(array $result) {
        $sanitizer = $this->wire('sanitizer');
        $cert = $sanitizer->entities($result['cert']);
        $key = $sanitizer->entities($result['key']);

        $base = str_replace('*', 'wildcard', strtolower($result['cn']));
        $base = preg_replace('/[^a-z0-9._-]/', '_', $base);
        if($base === '') $base = 'certificate';
        $crtFile = $sanitizer->entities($base . '.crt');
        $keyFile = $sanitizer->entities($base . '.key');

        return <<<HTML
<div class="ssl-toolbar">
    <a href="../selfsigned/" class="uk-button uk-button-default"><i class="fa fa-chevron-left"></i> Back</a>
</div>

<h2>Certificate</h2>
<div class="ssl-toolbar">
    <button type="button" class="uk-button uk-button-default" onclick="sslCopy('ssl-crt', this)"><i class="fa fa-copy"></i> Copy certificate</button>
    <button type="button" class="uk-button uk-button-default" onclick="sslDownload('ssl-crt','{$crtFile}')"><i class="fa fa-download"></i> Download certificate</button>
</div>
<textarea id="ssl-crt" class="ssl-pem" readonly onclick="this.select()">{$cert}</textarea>

<h2>Private Key</h2>
<div class="ssl-toolbar">
    <button type="button" class="uk-button uk-button-default" onclick="sslCopy('ssl-ssk', this)"><i class="fa fa-copy"></i> Copy private key</button>
    <button type="button" class="uk-button uk-button-default" onclick="sslDownload('ssl-ssk','{$keyFile}')"><i class="fa fa-download"></i> Download private key</button>
</div>
<textarea id="ssl-ssk" class="ssl-pem" readonly onclick="this.select()">{$key}</textarea>
HTML;
    }

    /**
     * Human-friendly "x ago" string.
     */
    protected function relativeTime($ts) {
        $diff = time() - (int) $ts;
        if($diff < 60) return $this->_('just now');
        if($diff < 3600) return sprintf($this->_('%dm ago'), (int) floor($diff / 60));
        if($diff < 86400) return sprintf($this->_('%dh ago'), (int) floor($diff / 3600));
        return sprintf($this->_('%dd ago'), (int) floor($diff / 86400));
    }

    /* ----------------------------------------------------------------------
     * Alerts & notices
     * -------------------------------------------------------------------- */

    /**
     * Domains that need attention: expired, error, or within warnDays.
     * Each entry: row data merged with the resolved cert info.
     */
    protected function getAttentionDomains() {
        $due = [];
        foreach($this->getDomains() as $d) {
            $info = $this->getCertInfo($d['host'], (int) $d['port']);
            $needs = !empty($info['error'])
                || (isset($info['daysLeft']) && $info['daysLeft'] <= (int) $this->warnDays);
            if($needs) $due[] = $d + ['info' => $info];
        }
        return $due;
    }

    /**
     * LazyCron handler: email about expiring/expired certs, throttled per domain.
     */
    public function cronCheckExpiry(HookEvent $event) {
        if(!$this->enableAlerts) return;

        $due = $this->getAttentionDomains();
        if(!$due) return;

        $reAlert = max(1, (int) $this->reAlertDays) * 86400;
        $now = time();
        $toReport = [];
        $ids = [];

        foreach($due as $d) {
            if(($now - (int) $d['last_notified']) < $reAlert) continue; // already alerted recently
            $toReport[] = $d;
            $ids[] = (int) $d['id'];
        }
        if(!$toReport) return;

        if($this->sendAlertEmail($toReport)) {
            $in = implode(',', array_map('intval', $ids));
            $this->wire('database')->exec(
                "UPDATE `" . self::TABLE_NAME . "` SET last_notified = " . $now . " WHERE id IN ($in)"
            );
            $this->wire('log')->save('ssl-manager', 'Sent expiry alert for ' . count($toReport) . ' domain(s).');
        }
    }

    protected function sendAlertEmail(array $domains) {
        $to = $this->alertRecipient();
        if(!$to) return false;

        $lines = [];
        foreach($domains as $d) {
            $info = $d['info'];
            if(!empty($info['error'])) {
                $state = 'ERROR: ' . $info['error'];
            } elseif($info['daysLeft'] < 0) {
                $state = 'EXPIRED ' . abs($info['daysLeft']) . ' day(s) ago';
            } else {
                $state = 'expires in ' . $info['daysLeft'] . ' day(s) (' . date('Y-m-d', $info['validTo']) . ')';
            }
            $lines[] = $d['host'] . ':' . $d['port'] . ' — ' . $state;
        }

        $body = $this->_('The following SSL certificates need attention:') . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . sprintf($this->_('Managed at: %s'), $this->wire('config')->urls->httpAdmin . self::ADMIN_PAGE . '/');

        $mail = $this->mailModule
            ? $this->wire('mail')->new($this->mailModule)
            : $this->wire('mail')->new();
        $mail->to($to);
        $mail->subject(sprintf($this->_('[SSL] %d certificate(s) need attention'), count($domains)));
        $mail->body($body);
        return (bool) $mail->send();
    }

    protected function alertRecipient() {
        if($this->alertEmail) return $this->alertEmail;
        $adminEmail = $this->wire('config')->adminEmail;
        if($adminEmail) return $adminEmail;
        $email = $this->wire('user')->email;
        return $email ?: '';
    }

    /**
     * Show a one-per-session admin warning when certs need attention.
     */
    public function maybeNotice(HookEvent $event) {
        $session = $this->wire('session');
        if($session->get('sslNoticeShown')) return;
        if(!$this->wire('user')->hasPermission('ssl-manager')) return;

        $due = $this->getAttentionDomains();
        if(!$due) { $session->set('sslNoticeShown', 1); return; }

        $hosts = array_slice(array_map(function($d) { return $d['host']; }, $due), 0, 5);
        $more = count($due) > 5 ? ' (+' . (count($due) - 5) . ')' : '';
        $url = $this->wire('config')->urls->admin . 'setup/' . self::ADMIN_PAGE . '/';
        $this->warning(
            sprintf($this->_('SSL: %d certificate(s) need attention: '), count($due))
            . implode(', ', $hosts) . $more
            . ' — <a href="' . $url . '">' . $this->_('review') . '</a>',
            Notice::allowMarkup
        );
        $session->set('sslNoticeShown', 1);
    }

    /* ----------------------------------------------------------------------
     * Data access
     * -------------------------------------------------------------------- */

    protected function getDomains() {
        $this->ensureSchema();
        $stmt = $this->wire('database')->query(
            "SELECT id, host, port, note, created, last_notified FROM `" . self::TABLE_NAME . "` ORDER BY host ASC"
        );
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function getDomain($id) {
        $this->ensureSchema();
        $stmt = $this->wire('database')->prepare(
            "SELECT id, host, port, note, created, last_notified FROM `" . self::TABLE_NAME . "` WHERE id = :id"
        );
        $stmt->execute([':id' => (int) $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Add the last_notified column on installs that predate it. Runs once per request.
     */
    protected function ensureSchema() {
        static $checked = false;
        if($checked) return;
        $checked = true;

        $db = $this->wire('database');
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `" . self::TABLE_NAME . "` LIKE 'last_notified'");
            if(!$stmt->fetch()) {
                $db->exec("ALTER TABLE `" . self::TABLE_NAME . "` ADD `last_notified` int(10) unsigned NOT NULL DEFAULT 0");
            }
        } catch(\Exception $e) {
            // table may not exist yet; ignore
        }
    }

    /**
     * Insert one domain. Returns 'added', 'dup' or 'invalid'. No redirect/messages.
     */
    protected function addDomain($host, $port, $note = '') {
        $host = $this->normalizeHost($host);
        if($host === '') return 'invalid';
        $port = (int) $port ?: 443;

        $db = $this->wire('database');
        $stmt = $db->prepare("SELECT id FROM `" . self::TABLE_NAME . "` WHERE host = :host AND port = :port");
        $stmt->execute([':host' => $host, ':port' => $port]);
        if($stmt->fetch()) return 'dup';

        $stmt = $db->prepare(
            "INSERT INTO `" . self::TABLE_NAME . "` (host, port, note, created)
             VALUES (:host, :port, :note, :created)"
        );
        $stmt->execute([':host' => $host, ':port' => $port, ':note' => $note, ':created' => time()]);
        return 'added';
    }

    protected function saveDomain($host, $port, $note) {
        switch($this->addDomain($host, $port, $note)) {
            case 'invalid':
                $this->error($this->_('Please enter a valid host name.'));
                return;
            case 'dup':
                $this->error(sprintf($this->_('%s is already being monitored.'), $this->normalizeHost($host)));
                break;
            default:
                $this->message(sprintf($this->_('Added domain: %s'), $this->normalizeHost($host)));
        }
        $this->wire('session')->redirect('../');
    }

    /**
     * Add many hosts at once (port 443). Reports a tally.
     */
    protected function addDomainsBulk(array $hosts) {
        $added = $dup = $invalid = 0;
        foreach($hosts as $h) {
            switch($this->addDomain($h, 443)) {
                case 'added': $added++; break;
                case 'dup': $dup++; break;
                default: $invalid++;
            }
        }
        $this->message(sprintf($this->_('Added %1$d, skipped %2$d duplicate(s), %3$d invalid.'), $added, $dup, $invalid));
        if($added) $this->wire('cache')->deleteFor($this);
        $this->wire('session')->redirect('../');
    }

    /**
     * Strip scheme / path / port so we keep a bare host name.
     */
    protected function normalizeHost($host) {
        $host = trim($host);
        if($host === '') return '';
        if(strpos($host, '://') !== false) {
            $parts = parse_url($host);
            $host = $parts['host'] ?? $host;
        }
        $host = preg_replace('#[/:].*$#', '', $host); // drop any path or :port leftover
        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.\-*]/', '', $host); // keep host chars + wildcard
        return substr($host, 0, 253);
    }

    /* ----------------------------------------------------------------------
     * Module configuration
     * -------------------------------------------------------------------- */

    public function getModuleConfigInputfields(InputfieldWrapper $inputfields) {
        $modules = $this->wire('modules');

        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Monitoring');
        $fs->icon = 'eye';

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'warnDays');
        $f->label = $this->_('Warn this many days before expiry');
        $f->attr('value', $this->warnDays);
        $f->columnWidth = 34;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'cacheTtl');
        $f->label = $this->_('Cache lifetime (seconds)');
        $f->description = $this->_('How long to keep a checked certificate result before re-checking.');
        $f->attr('value', $this->cacheTtl);
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'connectTimeout');
        $f->label = $this->_('Connection timeout (seconds)');
        $f->attr('value', $this->connectTimeout);
        $f->columnWidth = 33;
        $fs->add($f);

        $inputfields->add($fs);

        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('Email alerts');
        $fs->icon = 'envelope';
        $fs->description = $this->_('Requires the core LazyCron module. A daily check emails you about certificates within the warning window or already expired.');

        $f = $modules->get('InputfieldToggle');
        $f->attr('name', 'enableAlerts');
        $f->label = $this->_('Enable email alerts');
        $f->attr('value', (int) $this->enableAlerts);
        $f->columnWidth = 34;
        if(!$this->wire('modules')->isInstalled('LazyCron')) {
            $f->notes = $this->_('LazyCron is not installed — install it from Modules for alerts to run.');
        }
        $fs->add($f);

        $f = $modules->get('InputfieldEmail');
        $f->attr('name', 'alertEmail');
        $f->label = $this->_('Recipient email');
        $f->description = $this->_('Leave empty to use the site admin email.');
        $f->attr('value', $this->alertEmail);
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'reAlertDays');
        $f->label = $this->_('Re-alert interval (days)');
        $f->description = $this->_('Minimum days between repeated alerts for the same domain.');
        $f->attr('value', $this->reAlertDays);
        $f->columnWidth = 33;
        $fs->add($f);

        $f = $modules->get('InputfieldRadios');
        $f->attr('name', 'mailModule');
        $f->label = $this->_('Mailer');
        $f->description = $this->_('Which mailer to use for sending alerts.');
        $f->notes = $this->_('Install a WireMail module (SMTP, etc.) for more sending options.');
        $f->addOption('', $this->_('Default (site WireMail setting)'));
        foreach($this->wire('modules')->find('className^=WireMail') as $m) {
            $name = $m->className();
            if($name === 'WireMail') continue;
            $f->addOption($name, $name);
        }
        $f->attr('value', $this->mailModule);
        $fs->add($f);

        $inputfields->add($fs);

        $fs = $modules->get('InputfieldFieldset');
        $fs->label = $this->_('CSR defaults');
        $fs->description = $this->_('Pre-filled values for the "Generate CSR" form.');
        $fs->icon = 'file-text-o';
        $fs->collapsed = Inputfield::collapsedYes;

        $f = $modules->get('InputfieldSelect');
        $f->attr('name', 'defaultKeyBits');
        $f->label = $this->_('Default key size');
        $f->addOption(2048, '2048 bit');
        $f->addOption(4096, '4096 bit');
        $f->attr('value', $this->defaultKeyBits);
        $f->columnWidth = 100;
        $fs->add($f);

        $textDefaults = [
            'defaultCountry'  => [$this->_('Country (2-letter code)'), $this->defaultCountry, 33],
            'defaultState'    => [$this->_('State / Province'), $this->defaultState, 33],
            'defaultLocality' => [$this->_('Locality / City'), $this->defaultLocality, 34],
            'defaultOrg'      => [$this->_('Organization'), $this->defaultOrg, 50],
            'defaultOU'       => [$this->_('Organizational Unit'), $this->defaultOU, 50],
            'defaultEmail'    => [$this->_('Email address'), $this->defaultEmail, 100],
        ];
        foreach($textDefaults as $name => $cfg) {
            $f = $modules->get('InputfieldText');
            $f->attr('name', $name);
            $f->label = $cfg[0];
            $f->attr('value', $cfg[1]);
            $f->columnWidth = $cfg[2];
            $fs->add($f);
        }

        $inputfields->add($fs);
    }

    /* ----------------------------------------------------------------------
     * Install / Uninstall
     * -------------------------------------------------------------------- */

    public function ___install() {
        $sql = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_NAME . "` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `host` varchar(253) NOT NULL,
            `port` int(10) unsigned NOT NULL DEFAULT 443,
            `note` varchar(255) NOT NULL DEFAULT '',
            `created` int(10) unsigned NOT NULL,
            `last_notified` int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `host_port` (`host`,`port`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->wire('database')->exec($sql);
        $this->message($this->_('Created SSL Manager table.'));

        // Create the admin page under Setup
        $pages = $this->wire('pages');
        $parent = $pages->get('template=admin, name=setup, include=all');
        if(!$parent->id) $parent = $pages->get(2); // fall back to admin root

        $existing = $pages->get('name=' . self::ADMIN_PAGE . ', has_parent=' . $parent->id . ', include=all');
        if($existing->id) {
            $this->message($this->_('Admin page already exists.'));
            return;
        }

        $page = new Page();
        $page->template = 'admin';
        $page->parent = $parent;
        $page->name = self::ADMIN_PAGE;
        $page->title = 'SSL';
        $page->process = $this;
        $page->save();

        $this->message(sprintf($this->_('Created admin page: %s'), $page->path));
    }

    public function ___uninstall() {
        $this->wire('database')->exec("DROP TABLE IF EXISTS `" . self::TABLE_NAME . "`");
        $this->message($this->_('Removed SSL Manager table.'));

        $page = $this->wire('pages')->get('name=' . self::ADMIN_PAGE . ', template=admin, include=all');
        if($page->id) {
            $this->wire('pages')->delete($page, true);
            $this->message($this->_('Removed admin page.'));
        }
    }

}
