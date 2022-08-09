<?php
    namespace OpenEMR\Modules\ClaimRevConnector;
    class AuthoParam
    {

        public $audience = "https://api.claimrev.com";
        public $client_id = "";
        public $client_secret = "";
        public $grant_type = "password";
        public $username = "";
        public $password = "";

        public function __construct($clientId, $client_secret,$userName,$password) {
             $this->client_id = $clientId;
             $this->client_secret = $client_secret;
             $this->username = $userName;
             $this->password = $password;
    }
}