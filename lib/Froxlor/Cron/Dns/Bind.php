<?php

namespace Froxlor\Cron\Dns;

use Froxlor\Settings;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2016 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Froxlor team <team@froxlor.org> (2016-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Cron
 *
 */
class Bind extends DnsBase
{

    private $bindconf_file = "";

    public function writeConfigs()
    {
        // tell the world what we are doing
        $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'Task4 started - Rebuilding froxlor_bind.conf');

        // clean up
        $this->cleanZonefiles();

        // check for subfolder in bind-config-directory
        if (!file_exists(\Froxlor\FileDir::makeCorrectDir(Settings::Get('system.bindconf_directory') . '/domains/'))) {
            $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_NOTICE, 'mkdir ' . escapeshellarg(\Froxlor\FileDir::makeCorrectDir(Settings::Get('system.bindconf_directory') . '/domains/')));
            \Froxlor\FileDir::safe_exec('mkdir -p ' . escapeshellarg(\Froxlor\FileDir::makeCorrectDir(Settings::Get('system.bindconf_directory') . '/domains/')));
        }

        $domains = $this->getDomainList();

        if (empty($domains)) {
            $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'No domains found for nameserver-config, skipping...');
            return;
        }

        $this->bindconf_file = '# ' . Settings::Get('system.bindconf_directory') . 'froxlor_bind.conf' . "\n" . '# Created ' . date('d.m.Y H:i') . "\n" . '# Do NOT manually edit this file, all changes will be deleted after the next domain change at the panel.' . "\n\n";

        foreach ($domains as $domain) {
            if ($domain['ismainbutsubto'] > 0) {
                // domains with ismainbutsubto>0 are handled by recursion within walkDomainList()
                continue;
            }
            $this->walkDomainList($domain, $domains);
        }

        $bindconf_file_handler = fopen(\Froxlor\FileDir::makeCorrectFile(Settings::Get('system.bindconf_directory') . '/froxlor_bind.conf'), 'w');
        fwrite($bindconf_file_handler, $this->bindconf_file);
        fclose($bindconf_file_handler);
        $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'froxlor_bind.conf written');
        $this->reloadDaemon();
        $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'Task4 finished');
    }

    private function walkDomainList($domain, $domains)
    {
        $zoneContent = '';
        $subzones = '';

        foreach ($domain['children'] as $child_domain_id) {
            $subzones .= $this->walkDomainList($domains[$child_domain_id], $domains);
        }

        if ($domain['zonefile'] == '') {
            // check for system-hostname
            $isFroxlorHostname = false;
            if (isset($domain['froxlorhost']) && $domain['froxlorhost'] == 1) {
                $isFroxlorHostname = true;
            }

            if ($domain['ismainbutsubto'] == 0) {
                $zoneContent = (string)\Froxlor\Dns\Dns::createDomainZone(($domain['id'] == 'none') ? $domain : $domain['id'], $isFroxlorHostname);
                if ($domain['isbinddnssec'] == '1') {
                    $keys = glob(Settings::Get('system.bindconf_directory') . 'K' . $domain['domain'] . '*.key');
                    if (count($keys) == 0) {
                        $cmdStatus = 0;
                        \Froxlor\FileDir::safe_exec(escapeshellcmd('dnssec-keygen -v 0 -a ECDSAP384SHA384 -b 4096 -n ZONE ' . $domain['domain']));
                        \Froxlor\FileDir::safe_exec(escapeshellcmd('dnssec-keygen -v 0 -a ECDSAP384SHA384 -b 4096 -f KSK -n ZONE ' . $domain['domain']));
                        $keys = glob(Settings::Get('system.bindconf_directory') . 'K' . $domain['domain'] . '*.key');
                    }
                    $zoneContent .= '$INCLUDE ' . $keys[0] . PHP_EOL;
                    $zoneContent .= '$INCLUDE ' . $keys[1] . PHP_EOL;
                }
                $domain['zonefile'] = 'domains/' . $domain['domain'] . '.zone';
                $zonefile_name = \Froxlor\FileDir::makeCorrectFile(Settings::Get('system.bindconf_directory') . '/' . $domain['zonefile']);
                $zonefile_handler = fopen($zonefile_name, 'w');
                fwrite($zonefile_handler, $zoneContent . $subzones);
                fclose($zonefile_handler);
                if ($domain['isbinddnssec'] == '1') {
                    $salt = substr(sha1(mt_srand(10)), 1, 16);
                    \Froxlor\FileDir::safe_exec(escapeshellcmd('dnssec-signzone -v 0 -3 ' . $salt . ' -H 150 -t -o ' . $domain['domain'] . ' domains/' . $domain['domain'] . '.zone '));
                    $domain['zonefile'] = $domain['zonefile'] . '.signed';
                }
                $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, '`' . $zonefile_name . '` written');
                $this->bindconf_file .= $this->generateDomainConfig($domain);
            } else {
                return (string)\Froxlor\Dns\Dns::createDomainZone(($domain['id'] == 'none') ? $domain : $domain['id'], $isFroxlorHostname, true);
            }
        } else {
            $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'Added zonefile ' . $domain['zonefile'] . ' for domain ' . $domain['domain'] . ' - Note that you will also have to handle ALL records for ALL subdomains.');
            $this->bindconf_file .= $this->generateDomainConfig($domain);
        }
    }

    private function generateDomainConfig($domain = array())
    {
        $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_DEBUG, 'Generating dns config for ' . $domain['domain']);

        $bindconf_file = '# Domain ID: ' . $domain['id'] . ' - CustomerID: ' . $domain['customerid'] . ' - CustomerLogin: ' . $domain['loginname'] . "\n";
        $bindconf_file .= 'zone "' . $domain['domain'] . '" in {' . "\n";
        $bindconf_file .= '	type master;' . "\n";
        $bindconf_file .= '	file "' . \Froxlor\FileDir::makeCorrectFile(Settings::Get('system.bindconf_directory') . '/' . $domain['zonefile']) . '";' . "\n";
        $bindconf_file .= '	allow-query { any; };' . "\n";

        if (count($this->ns) > 0 || count($this->axfr) > 0) {
            // open allow-transfer
            $bindconf_file .= '	allow-transfer {' . "\n";
            // put nameservers in allow-transfer
            if (count($this->ns) > 0) {
                foreach ($this->ns as $ns) {
                    foreach ($ns["ips"] as $ip) {
                        $bindconf_file .= '		' . $ip . ";\n";
                    }
                }
            }
            // AXFR server #100
            if (count($this->axfr) > 0) {
                foreach ($this->axfr as $axfrserver) {
                    $bindconf_file .= '		' . $axfrserver . ';' . "\n";
                }
            }
            // close allow-transfer
            $bindconf_file .= '	};' . "\n";
        }

        $bindconf_file .= '};' . "\n";
        $bindconf_file .= "\n";

        return $bindconf_file;
    }

    private function cleanZonefiles()
    {
        $config_dir = \Froxlor\FileDir::makeCorrectFile(Settings::Get('system.bindconf_directory') . '/domains/');

        $this->logger->logAction(\Froxlor\FroxlorLogger::CRON_ACTION, LOG_INFO, 'Cleaning dns zone files from ' . $config_dir);

        // check directory
        if (@is_dir($config_dir)) {

            // create directory iterator
            $its = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($config_dir));

            // iterate through all subdirs, look for zone files and delete them
            foreach ($its as $it) {
                if ($it->isFile()) {
                    // remove file
                    \Froxlor\FileDir::safe_exec('rm -f ' . escapeshellarg(\Froxlor\FileDir::makeCorrectFile($its->getPathname())));
                }
            }
        }
    }
}
