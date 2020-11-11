<?php declare(strict_types=1);
        /**
             * Copyright (C) SympleeHosting - All Rights Reserved.
             *
             * MIT License
             *
             * Written by Thomas Hobson <thomas@sympleehosting.co.nz>, November 2020
             */


    namespace Opcenter\Dns\Providers\Metaname;

	use GuzzleHttp\Psr7\Response;

class Api
	{
        protected const METANAME_ENDPOINT = 'https://metaname.co.nz/api/1.1';

        /**
		 * @var \GuzzleHttp\Client
		 */
        protected $client;
        
        /**
		 * @var string
         * API Key
		 */
        protected $api_key;

        /**
		 * @var string
         * User ID
		 */
        protected $api_uid;
        
        /**
		 * @var Response
		 */
        protected $lastResponse;
        
        public function __construct(string $key)
		{
            $parts = explode(',', $key);

            $this->api_uid = $parts[0];
            $this->api_key = $parts[1];
            
			$this->client = new \GuzzleHttp\Client([
				'base_uri' => static::METANAME_ENDPOINT,
			]);
        }
        
        public function do(string $method, array $params = []): array
		{
            $method = strtolower($method);
            $id = "myid"; //metaname doesnt care!
			
            $this->lastResponse = $this->client->request("GET", "/", [
                'headers' => [
                    'User-Agent'    => PANEL_BRAND . ' ' . APNSCP_VERSION,
                    'Accept'        => 'application/json',
                ],
                'json' => [
                    'jsonrpc'       =>  '2.0',
                    'id'            =>  $id,
                    'method'        =>  $method,
                    'params'        =>  array_merge([$this->api_uid,$this->api_key], $params),
                ]
            ]);
            
            $r = \json_decode($this->lastResponse->getBody()->getContents(), true);

            if($r->id != $id) return [];
			return $r->result ?? [];
        }
        
        public function getResponse(): Response
		{
			return $this->lastResponse;
		}
    }