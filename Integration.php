<?php
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class CRMIntegration_ExternalCrm_Integration extends CRMIntegration_Base {
    protected $restRedirectURL;
    protected $config;
    protected $crmName = 'externalcrm';
    protected $crmRefPrefix = 'EX';
    protected $logger;
    protected $clientId;
    protected $clientSecret;
    protected $accessToken;
    protected $refreshToken;
    protected $dynamicDomain;
    protected $scope = 'openid profile api email offline_access';
    protected $ownerClientID;
    protected $employmentTypeMap = [
        'Permanent' => 1,
        'Interim' => 3,
    ];
    
    
    protected static $CLIENT_ID;
    protected static $CLIENT_SECRET;
        
    protected $externalcrmToGenericCategoryMap = [
        'Aerospace & Defense' => 2776,
        'Agriculture' => 2780,
        'Automotive & Transport' => 4,
        'Business Services' => 2784, 
        'Charitable Organisations' => 6,
        'Chemicals' => 2808, 
        'Construction' => 7,
        'Education' => 9,
        'Electronics' => 2790,
        'Energy & Utilities' => 2812,
        'Financial Services' => 11,
        'FMCG' => 12,
        'Government' => 23,
        'Health Care' => 2796,
        'Information Technology' => 18,
        'Insurance' => 13,
        'Leisure' => 20,
        'Manufacturing' => 2808,
        'Media' => 22,
        'Pharmaceuticals' => 2804,
        'Property' => 2806,
        'Retail' => 26,
        'Telecommunications' => 2798,
        'Travel & Transport' => 2810,
    ];
    protected $ext2GenericPeriod = [
        0 => 5, // per annum
        1 => 4, // per month
        2 => 3, // per week
        3 => 2, // per day
        4 => 1, // per hour
    ];
    
    
    public function __construct($actionController, $helper) {
        parent::__construct($actionController, $helper);
        $this->initializeLogger();
        $this->initializeConfig();
        self::$CLIENT_ID = $this->config->externalcrm->api_client_id;
        self::$CLIENT_SECRET = $this->config->externalcrm->api_client_secret;
        
    }
    
    protected function initializeLogger() {
        if (!$this->logger) {
            $this->logger = new \Generic_FileLogger_Manager('crm_externalcrm');
        }
    }
    
    public function init() {
        parent::init();

    }
    
    public function mainAction() {
        $this->logger->log('=========================================================');
        $this->logger->log('--- START CRMIntegration_ExternalCrm_Integration::mainAction');
        $this->logger->log('=========================================================');
    
        
        $this->initializeClientCredentials();
        $this->fakeLogin();
    
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
    
    
        
        
        $this->dynamicDomain = $this->_getParam('domain');
    
        $this->logger->log('Checking session in mainAction');
        $session = $this->getHelper('CRM')->getSession($this->crmName);
    
        if (!isset($session->data)) {
            $session->data = new stdClass();
        }
        $sessionData = $session->data;
    
        if (empty($sessionData->id) || empty($sessionData->secret)) {
            $this->logger->log("Session not initialized, trying to initialize.");
            $sessionData->id = $this->_getParam('id');
            $sessionData->secret = $this->_getParam('secret');
            $sessionData->vacancy = $this->_getParam('vacancy');
            $sessionData->userId = $this->_getParam('userId');
            $sessionData->domain = $this->_getParam('domain');
            $sessionData->tab = $this->_getParam('tab', 'overview');
            $sessionData->loggedIn = false;
    
            // Ensure compatibility with pluginCheck by duplicating session data at root level
            $session->id = $sessionData->id;
            $session->secret = $sessionData->secret;
    
            $this->logger->log("Session initialized: " . json_encode($sessionData));
        } else {
            $this->logger->log("Session is already initialized: " . json_encode($sessionData));
        }
    
        $pluginCheckResult = $this->pluginCheck($this->_getParam('userId'));
        $this->logger->log('pluginCheckResult: ' . json_encode($pluginCheckResult));
    
        if ($pluginCheckResult['status'] === 'error') {
            $this->logger->log('Error from pluginCheck: ' . $pluginCheckResult['error']);
            return;
        }
    
        $this->logger->log('Valid pluginCheckResult data, continuing session initialization.');
    
        $sessionData->ownerClientID = $pluginCheckResult['app']['owner'];
        $this->logger->log('Owner client ID in mainAction: ' . $sessionData->ownerClientID);
    
        if (!isset($pluginCheckResult['genericAcc']) || empty($pluginCheckResult['genericAcc'])) {
            $this->logger->log('No genericAcc data in pluginCheckResult.');
        } else {
            $sessionData->accessToken = $pluginCheckResult['genericAcc']['accessToken'];
            $sessionData->accessTokenExpireTS = $pluginCheckResult['genericAcc']['accessTokenExpireTS'];
            $sessionData->refreshToken = $pluginCheckResult['genericAcc']['refreshToken'];
            $sessionData->_settings_ClientSettingsID = $pluginCheckResult['genericAcc']['_settings_ClientSettingsID'];
        }
    
        $this->logger->log('Session updated: ' . json_encode($sessionData));
    
        if (!$this->checkAndRefreshToken($session)) {
            $this->logger->log('Token check failed, redirecting to OAuth');
            $this->doExternalCrmRedirect($sessionData->ownerClientID);
            return;
        }
    
        $userData = $this->getCRMUserDataById($sessionData->userId, $sessionData->accessToken);
        if (!$userData) {
            $this->logger->log('Error: Failed to retrieve user data.');
            return;
        }
    
        $this->logger->log('User data retrieved: ' . json_encode($userData));
        $sessionData->userData = $userData;
        $sessionData->loggedIn = true;
        $this->logger->log('User logged in successfully. Syncing...');
        $this->syncAction();
    
        $this->logger->log('Redirecting...');
        $redirectUrl = $this->getHelper('CRM')->getIframeRedirectUrl($sessionData->genericProjectID, $this->crmName, session_id());
        header('Location: ' . $redirectUrl['URL']);
        exit;
    }



    private function initializeConfig() {
        $this->config = Zend_Registry::get('config');
    
        // Ensure callback_url exists in configuration
        if (!isset($this->config->externalcrm->callback_url)) {
            $this->logger->log('Error: Missing callback_url in configuration.');
            throw new Exception('Missing callback_url in configuration.');
        }
    
        // Build the full redirect URL
        $this->restRedirectURL = App_Util::getFullDomain() . $this->config->externalcrm->callback_url;
    
        // Log the resulting URL
        $this->logger->log('Redirect URL set in initializeConfig: ' . $this->restRedirectURL);
    }





    
    private function initializeCRMUserID($userId, $session) {
        if (!isset($session->data)) {
            $session->data = new stdClass();
        }
        $sessionData = $session->data;
    
        $this->logger->log('Initializing CRM user ID with user ID: ' . $userId);
        $this->logger->log('Cred in initializeCRMUserID: ' . json_encode($sessionData));
        $crmClient = new Generic_Model_CRM2Client([]);
        $settings = $crmClient->getSettings();
    
        $this->logger->log('Settings in initializeCRMUserID: ' . json_encode($settings));
    
        if (isset($settings['crmUserID'])) {
            $this->crmUserID = $settings['crmUserID'];
        } else {
            $ownerClientID = Zend_Registry::get('login')->id;
            $subloginId = Zend_Registry::get('sublogin')->id;
    
            if (!$ownerClientID || !$subloginId) {
                throw new Exception('Owner client ID or sublogin ID not found.');
            }
    
            $this->crmUserID = $userId;
    
            $data = [
                'crmName' => $this->crmName,
                'crmUserID' => $this->crmUserID,
                '_owner_ClientID' => $sessionData->ownerClientID,
                '_settings_ClientSettingsID' => $sessionData->_settings_ClientSettingsID,
                'accessToken' => $sessionData->accessToken,
                'refreshToken' => $sessionData->refreshToken,
                'accessTokenExpireTS' => $sessionData->accessTokenExpireTS,
            ];
    
            $this->logger->log('Attempting to save CRM user with data: ' . json_encode($data));
    
            try {
                $crmClient = new Generic_Model_CRM2Client();
                $save = $crmClient->saveGenericAccount($this->crmName, $CRMUserid, null, null, [
                    'accessToken' => $sessionData->accessToken,
                    'refreshToken' => $sessionData->refreshToken,
                    'accessTokenExpireTS' => $sessionData->accessTokenExpireTS,
                ]);
                if ($save) {
                    $this->logger->log('New CRM user entry created successfully.' . json_encode($data));
                } else {
                    throw new Exception('Failed to save CRM user entry.');
                }
            } catch (Exception $e) {
                throw new Exception('Unable to create new CRM user entry: ' . $e->getMessage());
            }
        }
    }


    
    private function initializeClientCredentials() {
        $this->clientId = self::$CLIENT_ID;
        $this->clientSecret = self::$CLIENT_SECRET;
        $this->logger->log('Client credentials initialized: Client ID: ' . $this->clientId);
    }


    
    private function setupSession($params) {
        $this->logger->log('State in setupSession: ' . $params['state']);
        $session = $this->getHelper('CRM')->getSession($this->crmName, $params['state']);
    
        // Ensure session->data exists
        if (!isset($session->data)) {
            $session->data = new stdClass();
        }
    
        $sessionData = $session->data;
        $sessionData->id = isset($params['id']) ? $params['id'] : null;
        $sessionData->secret = isset($params['secret']) ? $params['secret'] : null;
        $sessionData->userId = isset($params['userId']) ? $params['userId'] : null;
        $sessionData->vacancyId = isset($params['vacancy']) ? $params['vacancy'] : null;
        $sessionData->tab = isset($params['tab']) ? $params['tab'] : 'default_tab';
        $sessionData->loggedIn = false;
    
        // Populate additional expected properties with defaults
        $sessionData->user = isset($sessionData->user) ? $sessionData->user : null;
        $sessionData->firstName = isset($sessionData->firstName) ? $sessionData->firstName : '';
        $sessionData->lastName = isset($sessionData->lastName) ? $sessionData->lastName : '';
        $sessionData->email = isset($sessionData->email) ? $sessionData->email : '';
    
        // Save back to session
        $session->data = $sessionData;
    
        // Log and return
        $this->logger->log('Session initialized with params: ' . json_encode($params));
        $this->logger->log('Session after setup: ' . print_r($session->data, true));
        return $session;
    }



    
    private function isValidUserData($userData) {
        return $userData && isset($userData['email']);
    }
    
    private function setLocalUser(&$session, $user) {
        $localUserId = $user['id'];
        $this->logger->log('Local user ID found: ' . $localUserId);
        $session->localUserId = $localUserId;
        $session->userData = $user;

    }  

    protected function checkAndRefreshToken($session) {
        if (!isset($session->data)) {
            $this->logger->log('Session data not found.');
            return false;
        }
        $sessionData = $session->data;
        $this->logger->log('Session data in checkAndRefreshToken: ' . json_encode($sessionData));
        if (isset($sessionData->accessToken)) {
            $currentTime = time();
            if ($currentTime >= $sessionData->accessTokenExpireTS) {
                $this->logger->log('Access token expired, trying to refresh');
                return $this->tryRefreshToken($session);
            } else {
                $this->logger->log('Access token is valid');
                return true;
            }
        } else {
            $this->logger->log('Access token or expiration timestamp missing');
            return false;
        }
    }


    protected function crmInitialCheck($additionalRequiredParams = []) {
        $result = ['status' => 'error'];
        
        $session = $this->getHelper('CRM')->getSession($this->crmName, $this->_getParam('state'));
        
        if (empty($session->vacancyId)) {
           $result['error'] = 'Missing required parameter: vacancy';
           $this->logger->log('Missing required parameter: vacancy');
           return $result;
        }
        
        $result['status'] = 'success';
        return $result;
    }

    protected function doExternalCrmRedirect($ownerClientID) {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $redirectUri = $this->getOAuthRedirectUrl($ownerClientID);
        $this->logger->log('Redirecting to OAuth URL: ' . $redirectUri);
        header('Location: ' . $redirectUri);
        exit;
    }

    private function getOAuthRedirectUrl($ownerClientID) {
        $this->logger->log('Generating ExternalCrm OAuth URL');
        $state = session_id();
    
        $this->logger->log('Session ID in getOAuthRedirectUrl: ' . session_id());
    
        $userId = $this->_getParam('userId');
        $vacancyID = $this->_getParam('vacancy');
        $domain = $this->_getParam('domain');
    
        if (empty(self::$CLIENT_ID) || empty(self::$CLIENT_SECRET) || empty($userId) || empty($vacancyID)) {
            $this->logger->log('Missing required parameters for generating OAuth URL');
            throw new Exception('Missing required parameters for generating OAuth URL');
        }
    
        $session = $this->getHelper('CRM')->getSession($this->crmName, $state);
        
        $this->logger->log('Session data in getOAuthRedirectUrl: ' . print_r($session->data, true));
    
        $session->ownerClientID = $ownerClientID;
        $session->userId = $userId;
        $session->vacancy = $vacancyID;
        $session->client_id = self::$CLIENT_ID;
        $session->client_secret = self::$CLIENT_SECRET;
        $session->domain = $domain;
        $session->callbackUrl = App_Util::getFullDomain() . $this->config->externalcrm->redirect_url;
    
        $encodedRedirectUri = urlencode($this->restRedirectURL);
        $encodedScope = urlencode($this->scope);
    
        $externalcrmAuthUrl = 'https://' . $this->dynamicDomain
            . "/identity/connect/authorize?response_type=code&client_id=" . self::$CLIENT_ID
            . "&redirect_uri={$encodedRedirectUri}&state={$state}&scope={$encodedScope}";
    
        $externalUrl = App_Util::getFullDomain() . $this->config->externalcrm->external_url;
        $finalUrl = "{$externalUrl}?redirect=" . urlencode($externalcrmAuthUrl);
    
        $this->logger->log('ExternalCrm OAuth URL: ' . $finalUrl);
        return $finalUrl;
    }

    
    public function redirectAction() {
        $this->logger->log('=========================================================');
        $this->logger->log('--- FUNCTION --- redirectAction');
        $this->logger->log('=========================================================');
        $this->logger->log('Owner client ID in redirectAction: ' . $this->_getParam('owner'));
        try {
            $this->initializeConfig();
    
            $session = $this->getHelper('CRM')->getSession($this->crmName, $this->_getParam('state'));
            if (empty($session) || !is_object($session)) {
                $this->logger->log('Session is empty or invalid. Initializing new session.');
                $session = new Ext_Redis_Storage();
            }
    
            if (!isset($session->data)) {
                $session->data = new stdClass();
            }
            $sessionData = $session->data;
    
            $sessionData->dynamicDomain = $this->_getParam('domain');
            $sessionData->userId = $this->_getParam('userId');
            $sessionData->vacancyId = $this->_getParam('vacancy');
            $sessionData->ownerClientID = $this->_getParam('owner');
            $session->data = $sessionData;
    
            $this->logger->log('Updated session data: ' . json_encode($sessionData));
    
            $code = $this->_getParam('code');
            if (empty($code)) {
                throw new Exception('Authorization code is missing from the redirect parameters.');
            }
            $this->logger->log('Starting processAuthorizationCode with authCode: ' . $code);
    
            $accessTokenData = $this->processAuthorizationCode($code);
            if ($accessTokenData && $accessTokenData->getToken()) {
                $this->updateSessionWithTokenData($session, $accessTokenData);
            } else {
                throw new Exception('Access token retrieval failed.');
            }
    
            $this->logger->log('Access token updated in session, proceeding to syncAction.');
    
            $_GET = [
                'vacancy' => $sessionData->vacancyId,
                'userId' => $sessionData->userId,
                'domain' => $sessionData->dynamicDomain,
                'state' => $this->_getParam('state'),
            ];
            $this->syncAction();
    
            $this->logger->log('redirectAction completed successfully.');
    
        } catch (Exception $e) {
            $this->logger->log('Exception caught in redirectAction: ' . $e->getMessage());
            echo "Error during redirect processing: " . $e->getMessage();
            http_response_code(500);
        }
    }

    private function updateSessionWithTokenData($session, $accessToken) {
        $sessionData = $session->data;
    
        $sessionData->accessToken = $accessToken->getToken();
        $sessionData->refreshToken = $accessToken->getRefreshToken();
        $sessionData->expiresAt = $accessToken->getExpires();
    
        $session->data = $sessionData;
        $this->logger->log('Session updated with token data: ' . json_encode([
            'accessToken' => $sessionData->accessToken,
            'expiresAt' => $sessionData->expiresAt,
        ]));
    }

    private function setLocalUserAndSaveTokens(&$session, $user) {
        $localUserId = $user['id'];
        $this->logger->log('Local user ID found: ' . $localUserId);
        $session->localUserId = $localUserId;
        $session->userData = $user;
    
        $this->logger->log('Login: ' . json_encode(Zend_Registry::get('login')));
        $this->logger->log('Sublogin: ' . json_encode(Zend_Registry::get('sublogin')));
    
        $CRMUserid=$this->getParam('userId');
        $this->logger->log('Saving settings with CRM user id: ' . $CRMUserid);
        $this->logger->log('Session refresh token: ' . $session->refreshToken);
        $this->logger->log('Session access token expire timestamp: ' . $session->accessTokenExpireTS);
    
        $crmClient = new Generic_Model_CRM2Client();
        $save = $crmClient->saveGenericAccount($this->crmName, $CRMUserid, null, null, [
            'accessToken' => $session->accessToken,
            'refreshToken' => $session->refreshToken,
            'accessTokenExpireTS' => $session->accessTokenExpireTS
        ]);
    
        $this->logger->log('Save result: ' . json_encode($save));
        $this->syncAction();
        $this->logger->log('Session genericProjectID after sync: ' . $session->genericProjectID);
        
        (new Generic_Model_ProjectMember())->updateRelation($session->genericProjectID, Zend_Registry::get('sublogin')->id);
         
        $redirectUrl = $this->getHelper('CRM')->getIframeRedirectUrl($session->genericProjectID, $this->crmName, $sessionName);
        $this->logger->log('Redirecting to: ' . $redirectUrl['URL']);
        $redirectUrl['URL'] = str_replace('/c', '', $redirectUrl['URL']);
        $this->_helper->redirector->gotoUrl($redirectUrl['URL']);
    }
    
    private function prepareRedirectView($session) {
        $this->logger->log('Preparing redirect view for synchronization');
    
        if (Zend_Registry::isRegistered('sublogin')) {
            $this->logger->log('Sublogin retrieved: ' . json_encode(Zend_Registry::get('sublogin')));
        } else {
            $this->logger->log('Sublogin is not registered in Zend_Registry.');
        }
    
        $sessionData = isset($session->data) ? $session->data : new stdClass();
        $this->logger->log('Session data in prepareRedirectView: ' . print_r($sessionData, true));
    
        if (!isset($sessionData->vacancyId) || empty($sessionData->vacancyId)) {
            $this->logger->log('Error: vacancyId is missing from session data');
            return;
        }
    
        $sessionName = $this->getHelper('CRM')->getCRMcookieSessionName($this->crmName, $sessionData->vacancyId);
        $syncAction = 'externalcrm-sync';
        $loginAction = 'externalcrm-login';
    
        $this->logger->log("Session Name: $sessionName");
        $this->logger->log("Sync Action: $syncAction");
        $this->logger->log("Login Action: $loginAction");
    
        return;
    }

    public function processAuthorizationCode($authCode) {
        // Ensure client credentials and config are initialized
        $this->initializeClientCredentials();
        $this->initializeConfig();
    
        // Ensure dynamic domain is set
        $this->dynamicDomain = isset($this->dynamicDomain) ? $this->dynamicDomain : $this->_getParam('domain');
        if (empty($this->dynamicDomain)) {
            throw new Exception('Dynamic domain is missing.');
        }
        $this->logger->log('Dynamic domain in processAuthorizationCode: ' . $this->dynamicDomain);
    
        // Ensure redirectUri is set
        if (empty($this->restRedirectURL)) {
            throw new Exception('Redirect URI is missing.');
        }
        $this->logger->log('Redirect URI in processAuthorizationCode: ' . $this->restRedirectURL);
    
        // Initialize OAuth provider
        $provider = $this->initializeOAuthProvider($this->clientId, $this->clientSecret, $this->restRedirectURL);
    
        try {
            // Retrieve access token using authorization code
            $accessToken = $provider->getAccessToken('authorization_code', ['code' => $authCode]);
            $this->logger->log('Access token retrieved successfully.');
    
            return $accessToken;
    
        } catch (IdentityProviderException $e) {
            $this->logger->log('OAuth error during processAuthorizationCode: ' . $e->getMessage());
            throw new Exception('Error retrieving access token: ' . $e->getMessage());
        }
    }
        
    protected function tryRefreshToken(&$session) {
        if (!isset($session->data)) {
            $this->logger->log('No session data found');
            return false;
        }
    
        $sessionData = $session->data;
        if (empty($sessionData->refreshToken)) {
            $this->logger->log('No refresh token found');
            return false;
        }
    
        $this->logger->log('Trying to refresh access token using static credentials');
        $provider = $this->initializeOAuthProvider(self::$CLIENT_ID, self::$CLIENT_SECRET, $this->restRedirectURL);
    
        try {
            $accessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' => $sessionData->refreshToken
            ]);
    
            $sessionData->accessToken = $accessToken->getToken();
            $sessionData->refreshToken = $accessToken->getRefreshToken();
            $sessionData->accessTokenExpireTS = $accessToken->getExpires();
    
            $session->data = $sessionData;
    
            $this->logger->log('Access token refreshed successfully');
            return true;
    
        } catch (IdentityProviderException $e) {
            $this->logger->log('Failed to refresh access token: ' . $e->getMessage());
            return false;
        }
    }

    
    private function initializeOAuthProvider($clientId, $clientSecret, $redirectUri) {
       $this->logger->log('Initializing OAuth Provider with params:');
       $this->logger->log('Client ID: ' . $clientId);
       $this->logger->log('Client Secret: [HIDDEN]');
       $this->logger->log('Redirect URI: ' . $redirectUri);
    
       return new GenericProvider([
           'clientId'                => $clientId,
           'clientSecret'            => $clientSecret,
           'redirectUri'             => $redirectUri,
           'urlAuthorize'            => 'https://' . $this->dynamicDomain . '/identity/connect/authorize',
           'urlAccessToken'          => 'https://' . $this->dynamicDomain . '/identity/connect/token',
           'urlResourceOwnerDetails' => 'https://' . $this->dynamicDomain . '/api/v1/People',
       ]);
    }

    private function getOAuthProvider() {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->redirectUri)) {
            throw new Exception('OAuth Provider configuration is incomplete.');
        }
    
        return new GenericProvider([
            'clientId'                => $this->clientId,
            'clientSecret'            => $this->clientSecret,
            'redirectUri'             => $this->redirectUri,
            'urlAuthorize'            => 'https://' . $this->dynamicDomain . '/identity/connect/authorize',
            'urlAccessToken'          => 'https://' . $this->dynamicDomain . '/identity/connect/token',
            'urlResourceOwnerDetails' => 'https://' . $this->dynamicDomain . '/api/v1/People',
        ]);
    }

    public function getCRMUserDataById($userId, $accessToken) {
        $url = 'https://' . $this->dynamicDomain . '/api/v1/People/' . $userId;
        $data = $this->requestExternalCrmData($url, $accessToken);
        return $this->formatUserData($data);
    }
        
    private function getExternalCrmVacancyExternalBrief($vacancyID, $accessToken) {
        $url = 'https://' . $this->dynamicDomain . '/api/v1/assignments/' . $vacancyID . '/brief?external=true';
        return $this->requestExternalCrmData($url, $accessToken);
    }
    
    private function getExternalCrmVacancy($vacancyID, $accessToken) {
        $url = 'https://' . $this->dynamicDomain . '/api/v1/assignments/' . $vacancyID;
        return $this->requestExternalCrmData($url, $accessToken);
    }
    
    private function makeGuzzleRequest($url, $headers, $postData = null) {
        $client = new Client();
        
        try {
            $formattedHeaders = [];
            foreach ($headers as $header) {
                $headerParts = explode(':', $header, 2);
                if (count($headerParts) === 2) {
                    $formattedHeaders[trim($headerParts[0])] = trim($headerParts[1]);
                }
            }
    
            $options = [
                'headers' => $formattedHeaders,
                'timeout' => 30,
                'verify' => true,
            ];
            
            if ($postData) {
                $options['json'] = $postData;
            }
            
            $response = $client->request($postData ? 'POST' : 'GET', $url, $options);
            return $response->getBody()->getContents();
    
        } catch (RequestException $e) {
            $this->logger->log('Guzzle Error: ' . $e->getMessage());
            throw new Exception('Guzzle Error: ' . $e->getMessage());
        }
    }

    private function requestExternalCrmData($url, $accessToken) {
        $headers = [
            "Authorization: Bearer " . $accessToken,
            "Content-Type: application/json",
        ];
    
        $this->logger->log('Requesting data from ExternalCrm with URL: ' . $url);
        $this->logger->log('Using access token: ' . ($accessToken ? 'present' : 'missing'));
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers);
            $data = json_decode($response, true);
    
            if (!$data) {
                $this->logger->log('No data retrieved from API response.');
                throw new Exception('Failed to retrieve data.');
            }
    
            return $data;
    
        } catch (RequestException $e) {
            $this->logger->log('Guzzle Error: ' . $e->getMessage());
            throw new Exception('Guzzle Error: ' . $e->getMessage());
        }
    }                                           

    
    private function formatUserData($data) {
        return [
            'userID' => isset($data['Id']) ? $data['Id'] : 'No user ID available',
            'email' => isset($data['EmailAddresses'][0]['ItemValue']) ? $data['EmailAddresses'][0]['ItemValue'] : 'No email available',
            'firstName' => isset($data['NameComponents']['FirstName']) ? $data['NameComponents']['FirstName'] : 'No first name available',
            'lastName' => isset($data['NameComponents']['FamilyName']) ? $data['NameComponents']['FamilyName'] : 'No last name available',
            'userName' => isset($data['DefaultPosition']['EntityDetails']['ItemDisplayText']) ? $data['DefaultPosition']['EntityDetails']['ItemDisplayText'] : 'No username available',
            'Company' => isset($data['DefaultPosition']['Company']['ItemDisplayText']) ? $data['DefaultPosition']['Company']['ItemDisplayText'] : 'No company available',
            'Position Status' => isset($data['DefaultPosition']['PositionStatus']) ? $data['DefaultPosition']['PositionStatus'] : 'No position status available',
            'Mobile Phone' => isset($data['PhoneNumbers'][0]['ItemValue']) ? $data['PhoneNumbers'][0]['ItemValue'] : 'No mobile phone available'
        ];
    }


    public function syncAction()
    {
        $this->logger->log('=========================================================');
        $this->logger->log('--- FUNCTION --- syncAction');
        $this->logger->log('=========================================================');
        $this->logger->log('[run] syncAction');
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $this->logger->log('Starting synchronization process');
    
        $params = $_GET;
        $session = $this->getHelper('CRM')->getSession($this->crmName, $this->_getParam('state'));
        $this->logger->log('Session details: ' . print_r($session->data, true));
        $sessionData = $session->data;
    
    
        if (empty($sessionData->accessToken) || !$this->checkAndRefreshToken($session)) {
            $this->logger->log('Access token is invalid or expired. Redirecting to OAuth.');
            $this->doExternalCrmRedirect($sessionData->ownerClientID);
            return;
        }
    
        try {
            
            if(!$sessionData->loggedIn){
                $userData = $this->getCRMUserDataById($sessionData->userId, $sessionData->accessToken);
                if (!$userData || empty($userData['email'])) {
                    throw new Exception('Failed to retrieve valid user data from CRM.');
                }
        
                $this->logger->log('User data retrieved: ' . json_encode($userData));
                $this->logger->log('Owner client ID: ' . $sessionData->ownerClientID);
        
                $userResp = $this->getUserByEmail(
                    $sessionData->ownerClientID,
                    isset($userData['email']) ? $userData['email'] : '',
                    isset($userData['firstName']) ? $userData['firstName'] : '',
                    isset($userData['lastName']) ? $userData['lastName'] : '',
                    isset($userData['userName']) ? $userData['userName'] : ''
                );
                
                if ($userResp['status'] === 'success' && isset($userResp['user'])) {
                    $this->assignAdditionalPermissions($userResp['user']['id']);
                }
                
                if ($userResp['status'] === 'choose_user') {
                    $sessionData->userIDs = array_map(function ($user) {
                        return $user['id'];
                    }, $userResp['users']);
                    $this->logger->log('Multiple users found. Prompting user selection.');
                    return App_Util::showResponse($userResp);
                }
        
                if ($userResp['status'] === 'error') {
                    throw new Exception('Error finding or creating user: ' . $userResp['error']);
                }
        
                Zend_Registry::set('crm_skip_linking_account', false);
                
                $this->logger->log('User successfully verified or created: ' . json_encode($userResp['user']));
        
                $sessionData->userData = $userResp['user'];
        
                if (empty($sessionData->vacancyId)) {
                    $this->logger->log('Error: vacancyId is missing in session.');
                    return;
                }
            
                $this->logger->log('User is logged in');
                $crmClient = new Generic_Model_CRM2Client();
                $save = $crmClient->saveGenericAccount($this->crmName, $sessionData->userId, null, null, [
                    'accessToken' => $sessionData->accessToken,
                    'refreshToken' => $sessionData->refreshToken,
                    'accessTokenExpireTS' => $sessionData->accessTokenExpireTS
                ]);
            }
            
            $vacancyID = $this->getVacancyID();
            $this->logger->log('qvacancyID: ' . $vacancyID);
            
            $genericProjectInfo = $this->getGenericProjectInfo($vacancyID);
            $this->logger->log('genericProjectInfo: ' . json_encode($genericProjectInfo));
            
            $genericProjectID = $this->getGenericProjectID($genericProjectInfo);
            $this->logger->log('genericProjectID: ' . $genericProjectID);
            
            $continuousSync = $this->getContinuousSync($genericProjectInfo);
            $this->logger->log('continuousSync: ' . $continuousSync);
    
            $this->logger->log('updateRelation...'.Zend_Registry::get('sublogin')->id);
            (new Generic_Model_ProjectMember())->updateRelation($genericProjectID, Zend_Registry::get('sublogin')->id);
            
            $vacancyData = $this->fetchVacancyData($vacancyID, $sessionData->accessToken);
            $translatedVacancyData = $this->translateAssignmentParams($vacancyData);
    
            $this->logger->log('Translated vacancy data: ' . json_encode($translatedVacancyData));
    
            $genericProject = $this->syncVacancyWithGeneric($vacancyID, $translatedVacancyData, $genericProjectID, $continuousSync);
            $this->finalizeSynchronization($genericProject, $genericProjectID, $vacancyID, $session);
    
        } catch (Exception $e) {
            $this->logger->log('Synchronization failed: ' . $e->getMessage());
        }
    }

    protected function assignAdditionalPermissions($userId)
    {
        $this->logger->log('Assigning additional permissions to user ID: ' . $userId);
    
        $pluginEnabledDefaultModel = new Generic_Model_UserManagement_PluginEnabledDefault();
        $permissionsHelper = Zend_Controller_Action_HelperBroker::getStaticHelper('Permissions');
    
        $permissions = $permissionsHelper->getPermissions();
    
        foreach ($permissions as $permissionKey => $permission) {
            $this->logger->log('Processing permission: ' . $permissionKey . ' (' . $permission['type'] . ')');
    
            if ($permission['id'] == 3 && $permission['type'] === 'core') {
                $this->logger->log('Assigning mandatory permission "Post as any user" (id=3).');
                $pluginEnabledDefaultModel->setState($permission['id'], true);
            }
    
            $pluginEnabledDefaultModel->setState($permission['id'], true);
        }
    
        $this->logger->log('All permissions assigned: ' . json_encode($permissions));
    }

    private function getVacancyID() {
        $vacancyID = $_GET['vacancy'];
        $this->logger->log('Using vacancy ID: ' . $vacancyID);
        return $vacancyID;
    }
    
    private function getGenericProjectInfo($vacancyID) {
        $this->logger->log('vacancyID: ' . $vacancyID);
        $genericProjectInfo = (new Generic_Model_CRMvacancy2Project())->getGenericProjectID('externalcrm', $vacancyID);

        $this->logger->log('genericProject Info: ' . json_encode($genericProjectInfo));
        return $genericProjectInfo;
    }
    
    private function getGenericProjectID($genericProjectInfo) {
        if ($genericProjectInfo && isset($genericProjectInfo['projectID'])) {
            return $genericProjectInfo['projectID'];
        }
        return false;
    }
    
    private function getContinuousSync($genericProjectInfo) {
        if ($genericProjectInfo && isset($genericProjectInfo['continuous_sync'])) {
            return !!$genericProjectInfo['continuous_sync'];
        }
        return true;
    }
    
    private function fetchVacancyData($vacancyID, $accessToken) {
        $this->logger->log('Fetching vacancy data from ExternalCrm API');
        $vacancyData = $this->getExternalCrmVacancy($vacancyID, $accessToken);
        $vacancyData['vacancyBrief'] = $this->getExternalCrmVacancyExternalBrief($vacancyID, $accessToken);
        return $vacancyData;
    }
    
    private function syncVacancyWithGeneric($vacancyID, $translatedVacancyData, $genericProjectID, $continuousSync) {
        $this->logger->log('Syncing vacancy with generic');
        $genericProject = $this->createOrUpdateVacancy($vacancyID, $translatedVacancyData, $genericProjectID, $continuousSync);
        $params = array($vacancyID, $genericProjectID, $continuousSync);
        $this->logger->log('createOrUpdateVacancy params ' . json_encode($params));
        $this->logger->log('genericProject: ' . json_encode($genericProject));
        return $genericProject;
    }
    
    private function finalizeSynchronization($genericProject, $genericProjectID, $vacancyID, $session) {
        if (!isset($session->data)) {
            $session->data = new stdClass();
        }
    
        $sessionData = $session->data;
    
        if ($genericProject['status'] == 'success') {
            if (!$genericProjectID) {
                $genericProjectID = $genericProject['projectID'];
                $this->logger->log('New project created with ID: ' . $genericProjectID);
    
                (new Generic_Model_CRMvacancy2Project())->saveGenericProjectID('externalcrm', $vacancyID, $genericProjectID);
                (new Generic_Model_ProjectMember())->updateRelation($genericProjectID, Zend_Registry::get('sublogin')->id);
            }
    
            $sessionData->genericProjectID = $genericProjectID;
            $session->data = $sessionData;
    
            $this->logger->log('Vacancy synced successfully with generic. Project ID: ' . $genericProjectID);
        } else {
            $this->logger->logError($genericProject['error']);
            return App_Util::showResponse($genericProject);
        }
    
        $this->logger->log('Synchronization successful. Redirecting...');
        $this->logger->log('redirecting to genericProjectID: ' . $sessionData->genericProjectID);
        $redirectUrl = $this->getHelper('CRM')->getIframeRedirectUrl($genericProjectID, $this->crmName, null);
        header('Location: ' . $redirectUrl['URL']);
        exit;
    }


    protected function translateAssignmentParams($assignmentData) {

        $result = [
            'crm_reference' => $assignmentData['AssignmentNumber'],
            'title' => isset($assignmentData['AssignmentName']) ? $assignmentData['AssignmentName'] : 'No title',
            'description' => nl2br($assignmentData['vacancyBrief']['Text']),
        ];
            
        
        $this->logger->log('Assignment Data: ' . print_r($assignmentData['vacancyBrief']['Text'], true));
        $this->logger->log('Assignment Number: ' . $result['crm_reference']);
    
        // Employment type mapping
        $employmentTypeMap = $this->employmentTypeMap;
        
        $result['type'] = isset($employmentTypeMap[$assignmentData['EmploymentType']]) ? $employmentTypeMap[$assignmentData['EmploymentType']] : 1;
    
        // Category mapping
        $externalcrmToGenericCategoryMap = $this->externalcrmToGenericCategoryMap;
        
        $externalcrmCategoryName = $assignmentData['Categories']['Lists'][0]['Categories'][0]['CategoryName'];
        $result['sector'] = isset($externalcrmToGenericCategoryMap[$externalcrmCategoryName]) ? $externalcrmToGenericCategoryMap[$externalcrmCategoryName] : null;
    
        // Determine salary and period based on the type
        $packageKey = $result['type'] == 3 ? 'InterimRates' : 'PermanentPackages';
        $result['minsalary'] = $assignmentData[$packageKey][0]['AmountFrom'];
        $result['maxsalary'] = $assignmentData[$packageKey][0]['AmountTo'];
        $periodValue = $assignmentData[$packageKey][0]['Period']['Value'];
        $result['currency'] = $assignmentData[$packageKey][0]['Currency']['ItemDisplayText'];
    
        // Benefits
        $result['benefits'] = $assignmentData['Benefits'];
        
        // Map period value to the appropriate type
        $ext2GenericPeriod = $this->ext2GenericPeriod;
        
        $result['per'] = isset($ext2GenericPeriod[$periodValue]) ? $ext2GenericPeriod[$periodValue] : 0;
    
        // Location details
        $location = $assignmentData['DefaultLocation']['AddressComponents'];
        $result['lat'] = $location['Latitude'];
        $result['lng'] = $location['Longitude'];
        $result['mapPostcode'] = $location['Postcode'];
        $result['city'] = $location['TownCity'];
        $result['country'] = $location['Country'];

        return $result;
    }

    protected function initialCheck() {
        $result = ['status' => 'error'];
    
        
        $session = $this->getHelper('CRM')->getSession($this->crmName);
    
     
        if (!isset($session->data)) {
            $session->data = new stdClass();
        }
    
        $sessionData = $session->data;
    

        if (empty($sessionData->id) || empty($sessionData->secret)) {
            $result['error'] = 'Auth params (id & secret) are missing';
            $this->logger->log('$session->data->id or $session->data->secret is missing');
            return $result;
        }
    
        $this->logger->log('initialCheck - Session: ' . json_encode($sessionData));
        $result['status'] = 'success';
        return $result;
    }

    
    public function ExternalAction() {

        $redirect = $this->_getParam('redirect');
    
        if (!$redirect) {
            http_response_code(400);
            echo "Bad request: Missing redirect parameter";
            return;
        }
    
        $parsedUrl = parse_url($redirect);
        parse_str($parsedUrl['query'], $queryParams);
        if (!isset($queryParams['state'])) {
            http_response_code(400);
            echo "Bad request: Missing state parameter in redirect URL";
            return;
        }
        $state = $queryParams['state'];
    
        $this->logger->log('ExternalAction - State: ' . $state);
        $this->logger->log("Redirecting to OAuth URL: $redirect");
    
        header("Location: " . $redirect);
        exit;
    }
    
    public function CallbackAction()
    {
        $this->_helper->layout()->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
    
        $state = $this->_getParam('state');
        $this->logger->log('CallbackAction - State: ' . $state);
    
        if (empty($state)) {
            $this->logger->log('Missing state parameter.');
            http_response_code(400);
            echo "Bad request: Missing state parameter";
            return;
        }
    
        $session = $this->getHelper('CRM')->getSession($this->crmName, $state);
    
        if (!isset($session->callbackUrl) || !isset($session->userId) || !isset($session->domain)) {
            $this->logger->log('Session data not found for state: ' . $state);
            http_response_code(400);
            echo "Error: Session data not found";
            return;
        }
    
        // Log session details
        $owner = $session->ownerClientID;
        $this->logger->log('CallbackAction - Owner: ' . $owner);
        $userId = $session->userId;
        $client_id = $session->client_id;
        $client_secret = $session->client_secret;
        $callback = $session->callbackUrl;
        $vacancy = $session->vacancy;
        $domain = $session->domain;
    
        $this->logger->log("Session data: owner: $owner, userId: $userId, client_id: $client_id");
    

        // Construct callback URL
        $query = $_SERVER['QUERY_STRING'];
        $finalCallback = $callback . (strpos($callback, '?') === false ? '?' : '&') . $query . "&userId=$userId&vacancy=$vacancy&id=$client_id&secret=$client_secret&domain=$domain&owner=$owner";
    
        $this->logger->log("Final redirect URL: $finalCallback");
    
        header("Location: " . $finalCallback);
        exit;
    }

}