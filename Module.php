<?php declare(strict_types=1);

	/**
	 * Copyright (C) SympleeHosting - All Rights Reserved.
	 *
	 * MIT License
	 *
	 * Written by Thomas Hobson <thomas@sympleehosting.co.nz>, November 2020
	 */

	namespace Opcenter\Dns\Providers\Metaname;

	use GuzzleHttp\Exception\ClientException;
	use Module\Provider\Contracts\ProviderInterface;
    use Opcenter\Dns\Record as RecordBase;

	class Module extends \Dns_Module implements ProviderInterface
	{
		use \NamespaceUtilitiesTrait;

		const DNS_TTL = 1800;
		/**
		 * apex markers are marked with @
		 */
		protected const HAS_ORIGIN_MARKER = true;
		protected static $permitted_records = [
			'A',
			'AAAA',
			'CNAME',
            'MX',
			'SRV',
			'TXT',
		];
		// @var array API credentials
		private $key;

		public function __construct()
		{
			parent::__construct();
			$this->key = $this->getServiceValue('dns', 'key', DNS_PROVIDER_KEY);
		}

		/**
		 * Add a DNS record
		 *
		 * @param string $zone
		 * @param string $subdomain
		 * @param string $rr
		 * @param string $param
		 * @param int    $ttl
		 * @return bool
		 */
		public function add_record(
			string $zone,
			string $subdomain,
			string $rr,
			string $param,
			int $ttl = self::DNS_TTL
		): bool {
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();
			$record = new RecordBase($zone, [
				'name'      => $subdomain,
				'rr'        => $rr,
				'parameter' => $param,
				'ttl'       => $ttl
			]);
			if ($record['name'] === '') {
				$record['name'] = '@';
            }
            
			try {
				$this->formatRecord($record);
                $ret = $api->do('create_dns_record', [$zone, $this->formatRecord($record)]);

                if(empty($ret)) return error("Failed to create record `%s' type %s: Response was empty", $fqdn, $rr); 

                $record->setMeta('id', $ret['id']);
                $this->addCache($record);
			} catch (ClientException $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');
				return error("Failed to create record `%s' type %s: %s", $fqdn, $rr, $e->getMessage());
			}

			return (bool)$ret;
		}

		/**
		 * @inheritDoc
		 */
		public function remove_record(string $zone, string $subdomain, string $rr, string $param = ''): bool
		{
			if (!$this->canonicalizeRecord($zone, $subdomain, $rr, $param, $ttl)) {
				return false;
			}
			$api = $this->makeApi();

			$id = $this->getRecordId($r = new RecordBase($zone,
				['name' => $subdomain, 'rr' => $rr, 'parameter' => $param, 'ttl' => null]));

			if (!$id) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');
				return error("Record `%s' (rr: `%s', param: `%s')  does not exist", $fqdn, $rr, $param);
			}

			try {
				$api->do('delete_dns_record', [$zone, $id]);
			} catch (ClientException $e) {
				$fqdn = ltrim(implode('.', [$subdomain, $zone]), '.');

				return error("Failed to delete record `%s' type %s", $fqdn, $rr);
			}
			array_forget($this->zoneCache[$r->getZone()], $this->getCacheKey($r));

			return true;
        }
        
        /**
         * Add DNS zone to service
         *
         * @param string $domain
         * @param string $ip
         * @return bool
         */
        public function add_zone_backend(string $domain, string $ip): bool
        {
                //We can't actually add/remove with the API unless the domain is with Metaname, which is not always the case.
                // For now stub the methods
                return true;
        }

        /**
         * Remove DNS zone from nameserver
         *
         * @param string $domain
         * @return bool
         */
        public function remove_zone_backend(string $domain): bool
        {
                return true;
        }


		/**
		 * Get hosting nameservers
		 *
		 * @param string|null $domain
		 * @return array
		 */
		public function get_hosting_nameservers(string $domain = null): array
		{
			return ['ns1.metaname.net', 'ns2.metaname.net', 'ns3.metaname.net'];
		}


		/**
		 * Get raw zone data
		 *
		 * @param string $domain
		 * @return null|string
		 */
		protected function zoneAxfr(string $domain): ?string
		{
            $client = $this->makeApi();
            $preamble = [];
			try {
                $records = $client->do('dns_zone', [$domain]);
                
                $soa = array_get($this->get_records_external('', 'soa', $domain,
					$this->get_hosting_nameservers($domain)), 0, []);

				$ttldef = (int)array_get(preg_split('/\s+/', $soa['parameter'] ?? ''), 6, static::DNS_TTL);
				if ($soa) {
					$preamble = [
						"${domain}.\t${ttldef}\tIN\tSOA\t${soa['parameter']}",
					];
				}
				foreach ($this->get_hosting_nameservers($domain) as $ns) {
					$preamble[] = "${domain}.\t${ttldef}\tIN\tNS\t${ns}.";
				}

			} catch (ClientException $e) {
				return null;
			}
			

			$this->zoneCache[$domain] = [];
			foreach ($records as $r) {
				switch ($r['type']) {
                    case 'SRV':
					case 'MX':
						$parameter = $r['aux'] . ' ' . $r['data'];
						break;
					default:
						$parameter = $r['data'];
                }
                
                $hostname = ltrim($r['name'] . '.' . $domain, '.') . '.';
				$preamble[] = $hostname . "\t" . $r['ttl'] . "\tIN\t" .
                    $r['type'] . "\t" . $parameter;
                    
				$this->addCache(new RecordBase($domain,
					[
						'name'      => $r['name'],
						'rr'        => $r['type'],
						'ttl'       => $r['ttl'] ?? static::DNS_TTL,
						'parameter' => $parameter,
						'meta'      => [
							'id'    => $r['reference']
						]
					]
				));
            }
            
            $axfrrec = implode("\n", $preamble);
            $this->zoneCache[$domain]['text'] = $axfrrec;
            

			return $axfrrec;
		}

		private function makeApi(): Api
		{
			return new Api($this->key);
		}

		/**
		 * Modify a DNS record
		 *
		 * @param string $zone
		 * @param Record $old
		 * @param Record $new
		 * @return bool
		 */
		protected function atomicUpdate(string $zone, RecordBase $old, RecordBase $new): bool
		{
			// @var \Cloudflare\Api\Endpoints\DNS @api
			if (!$this->canonicalizeRecord($zone, $old['name'], $old['rr'], $old['parameter'], $old['ttl'])) {
				return false;
			}
			$old['ttl'] = null;
			if (!($id = $this->getRecordId($old))) {
				return error("failed to find record ID in Metaname zone `%s' - does `%s' (rr: `%s', parameter: `%s') exist?",
					$zone, $old['name'], $old['rr'], $old['parameter']);
			}
			if (!$this->canonicalizeRecord($zone, $new['name'], $new['rr'], $new['parameter'], $new['ttl'])) {
				return false;
			}
			$api = $this->makeApi();
			try {
				$merged = clone $old;
				$new = $merged->merge($new);
				$ret = $api->do('update_dns_record',[$zone,$id, $this->formatRecord($new)]);
				
			} catch (ClientException $e) {
				$reason = \json_decode($e->getResponse()->getBody()->getContents());
				$msg = $reason->errors[0]->message ?? $reason->message;
				return error("Failed to update record `%s' on zone `%s' (old - rr: `%s', param: `%s'; new - rr: `%s', param: `%s'): %s",
					$old['name'],
					$zone,
					$old['rr'],
					$old['parameter'], $new['name'] ?? $old['name'], $new['parameter'] ?? $old['parameter'],
					$msg
				);
			}
			array_forget($this->zoneCache[$old->getZone()], $this->getCacheKey($old));
			$this->addCache($new);

			return true;
		}

		protected function formatRecord(Record $r)
		{
			$args = [
				'type' => strtoupper($r['rr']),
				'ttl'  => (int)($r['ttl'] ?? static::DNS_TTL),
                'name' => $r['name'],
                'data' => $r['parameter']
			];
			switch ($args['type']) {
				case 'CNAME':
					$r['parameter'] = rtrim($r['parameter'], '.');
				case 'A':
				case 'AAAA':
				case 'TXT':
                    return $args;
                
                case 'SRV':
                case 'MX':
                    $args['aux'] = $r->getMeta('priority');
					return $args;
				
				default:
					fatal("Unsupported DNS RR type `%s'", $r['type']);
            }
            
		}

		protected function hasCnameApexRestriction(): bool
		{
			return true;
        }
    }
