<?php
    namespace OpenEMR\Modules\ClaimRevConnector;
    class AuthoParam
    {

        public $audience = "https://api.claimrev.com";
        public $client_id = "";
        public $client_secret = "";
        public $grant_type = "client_credentials";


        public function __construct($clientId, $client_secret,$aud) {
             $this->client_id = $clientId;
             $this->client_secret = $client_secret;
             $this->audience = $aud;
    }
}