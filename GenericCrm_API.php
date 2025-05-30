<?php
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class GenericCrm_API_Client
{
    
    public static $CLIENT_ID;
    public static $CLIENT_SECRET;
    public $apiUrl;
    protected $config;
    protected $clientId;
    protected $clientSecret;
    protected $accessToken;
    protected $refreshToken;
    protected $accessTokenExpireTS;
    protected $logger;
    private $companyCache = [];

    public function __construct($apiUrl, $clientId, $clientSecret, $username, $password, $logger = null)
    {
        $this->apiUrl = $apiUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->config = ['username' => $username, 'password' => $password];
        $this->logger = $logger ?: new Logger();
        $this->logger->log('Initializing GenericCrm API Client...');
        $this->loadToken();
    }

    public function obtainAccessTokenWithROPC($username, $password)
    {
        $url = $this->apiUrl . '/identity/connect/token';
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $postData = [
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'username' => $username,
            'password' => $password,
            'scope' => 'openid profile api email',
        ];
    
        $this->logger->log("Obtaining access token via ROPC...");
        $this->logger->log("Request URL: $url");
        $this->logger->log("Post Data: " . json_encode($postData));
    
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => $headers,
                'form_params' => $postData,
            ]);
    
            $responseBody = $response->getBody()->getContents();
            $this->logger->log("Response: $responseBody");
    
            $responseData = json_decode($responseBody, true);
    
            if (isset($responseData['access_token'])) {
                $this->logger->log("Access token obtained successfully.");
                $this->saveToken($responseData);
            } else {
                $errorMessage = "Failed to obtain access token. Response: " . json_encode($responseData);
                $this->logger->log($errorMessage);
                throw new Exception($errorMessage);
            }
        } catch (Exception $e) {
            $errorMessage = 'Error obtaining access token via ROPC: ' . $e->getMessage();
            $this->logger->log($errorMessage);
            throw $e;
        }
    }




    protected function saveToken($response)
    {
        $this->accessToken = isset($response['access_token']) ? $response['access_token'] : null;
        $this->accessTokenExpireTS = isset($response['expires_in']) ? time() + $response['expires_in'] : null;
    
        $this->logger->log('Saving token: ' . json_encode($response));
    
        $tokenData = [
            'accessToken' => $this->accessToken,
            'accessTokenExpireTS' => $this->accessTokenExpireTS ? date('Y-m-d H:i:s', $this->accessTokenExpireTS) : null,
        ];
    
        $handoffModel = new Generic_Model_CrmHandoff();
    
        try {
            $handoffModel->saveHandoffSettings($tokenData);
            $this->logger->log('Token successfully saved to database: ' . json_encode($tokenData));
        } catch (Exception $e) {
            $this->logger->log('Error saving token to database: ' . $e->getMessage());
            throw $e;
        }
    
        $this->logger->log('Token data saved to the database: ' . json_encode($tokenData));
    }

    public function loadToken()
    {
        $handoffGenericCrmModel = new Generic_Model_CrmHandoff();
    
        try {
            $settings = $handoffGenericCrmModel->getSettings(Generic_Model_Handoff::HANDOFF_GENERIC_CRM_ID);
    
            $this->logger->log('Loaded token from database: ' . json_encode($settings));
    
            if ($settings) {
                $this->accessToken = isset($settings['accessToken']) ? $settings['accessToken'] : null;
                $this->accessTokenExpireTS = isset($settings['accessTokenExpireTS']) ? strtotime($settings['accessTokenExpireTS']) : null;
                $this->logger->log('Token successfully loaded from the database.');
            } else {
                $this->logger->log('No token found in the database.');
            }
        } catch (Exception $e) {
            $this->logger->log('Error loading token from database: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function ensureValidToken()
    {
        if (!$this->accessToken || time() > $this->accessTokenExpireTS) {
            $this->logger->log('Access token is expired or not available. Obtaining a new one...');
    
            $username = isset($this->config['username']) ? $this->config['username'] : null;
            $password = isset($this->config['password']) ? $this->config['password'] : null;
    
            if (!$username || !$password) {
                $this->logger->log('Username or password missing in config.');
                throw new Exception('Username or password missing in config.');
            }
    
            $this->obtainAccessTokenWithROPC($username, $password);
        }
    }



    public function refreshToken()
    {
        if (!$this->refreshToken) {
            $this->logger->log('No refresh token available in the database.');
            throw new Exception('No refresh token available.');
        }
    
        $tokenUrl = $this->apiUrl . '/identity/connect/token';
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    
        try {
            $responseBody = $this->makeGuzzleRequest($tokenUrl, $headers, $postData, false);
            $responseData = json_decode($responseBody, true);
    
            if (isset($responseData['access_token'])) {
                $this->saveToken($responseData);
                $this->logger->log('Token successfully refreshed.');
            } else {
                throw new Exception('Failed to refresh token.');
            }
        } catch (Exception $e) {
            $this->logger->log('Error refreshing token: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Add Candidate to CRM
     */
    public function addCandidateToCRM($candidateData)
    {
        $url = $this->apiUrl . '/api/v1/people';
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        $this->logger->log('Sending request to add candidate to CRM with data: ' . json_encode($candidateData));

        try {
            $response = $this->makeGuzzleRequest($url, $headers, $candidateData, false);
            $decodedResponse = json_decode($response, true);
            //$this->logger->log('Response from adding candidate: ' . $response);

            if (isset($decodedResponse['Id'])) {
                return $decodedResponse['Id']; 
            }

            return false;
        } catch (Exception $e) {
            $this->logger->log('Error while adding candidate: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function getCandidateDataById($id)
    {
        // building the URL for fetching candidate data
        $url = $this->apiUrl . "/api/v1/people/{$id}";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type'  => 'application/json',
        ];
    
        $this->logger->log("Fetching candidate data for candidate ID: {$id}");
        
        try {
            $response = $this->makeGuzzleRequest($url, $headers);
            //$this->logger->log("Candidate data fetched: " . $response);
            return json_decode($response, true);
        } catch (Exception $e) {
            $this->logger->log("Error fetching candidate data for candidate ID {$id}: " . $e->getMessage());
            throw new Exception("Error fetching candidate data: " . $e->getMessage());
        }
    }
    
    public function searchCompany($companyName)
    {
        $url = $this->apiUrl . "/api/v1/quicksearch/companies?request.searchTerm=" . urlencode($companyName);
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $this->logger->log("Searching for company: $companyName");
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers);
            $companies = json_decode($response, true);
    
            if (!empty($companies)) {
                foreach ($companies as $company) {
                    if ($company['ItemType'] === 'Companies') {
                        $this->logger->log("Company found: {$company['DisplayName']}, ID: {$company['ItemId']}");
                        return $company;
                    }
                }
            }
    
            $this->logger->log("Company not found: $companyName");
            return null;
        } catch (Exception $e) {
            $this->logger->log("Error searching for company: " . $e->getMessage());
            return null;
        }
    }


    public function searchEducationalInstitution($institutionName)
    {
        $url = $this->apiUrl . "/api/v1/quicksearch/educationalorganisations?request.searchTerm=" . urlencode($institutionName);
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $this->logger->log("Searching for educational institution: $institutionName");
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers);
            $institutions = json_decode($response, true);
    
            if (!empty($institutions)) {
                foreach ($institutions as $institution) {
                    if ($institution['ItemType'] === 'Companies') {
                        $this->logger->log("Institution found: {$institution['DisplayName']}, ID: {$institution['ItemId']}");
                        return $institution['ItemId'];
                    }
                }
            }
    
            $this->logger->log("Institution not found: $institutionName");
            return null;
        } catch (Exception $e) {
            $this->logger->log("Error searching for educational institution: " . $e->getMessage());
            return null;
        }
    }
    
    public function createCompany($companyData)
    {
        $url = $this->apiUrl . "/api/v1/companies";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $this->logger->log("Creating new company: " . json_encode($companyData));
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers, $companyData, true);
            $company = json_decode($response, true);
    
            if (isset($company['ItemId'])) {
                $companyId = $company['ItemId'];
                $this->logger->log("Company created successfully: {$companyData['CompanyName']}, ID: $companyId");
                return $companyId;
            }
    
            return null;
        } catch (Exception $e) {
            $this->logger->log("Error creating company: " . $e->getMessage());
            return null;
        }
    }

    
    public function addWorkHistoryToCandidate($candidateId, $workData)
    {
        $url = $this->apiUrl . "/api/v1/people/{$candidateId}/positions";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $this->logger->log("Adding work history for candidate ID: {$candidateId}");
        $this->logger->log("Work data: " . json_encode($workData));
    
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $workData,
            ]);
    
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $this->logger->log("Response from adding work history: {$responseBody}");
    
            if ($statusCode === 200 || $statusCode === 201) {
                $this->logger->log("Work history added successfully for candidate ID: {$candidateId}");
                return true;
            } else {
                $this->logger->log("Unexpected response status while adding work history: {$statusCode}");
                return false;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorResponse = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->log("Client exception during adding work history: {$errorResponse}");
            throw new Exception("Error adding work history: {$errorResponse}");
        } catch (Exception $e) {
            $this->logger->log("Unexpected error during adding work history: " . $e->getMessage());
            throw new Exception("Error adding work history: " . $e->getMessage());
        }
    }
    
    
    
    public function addEducationHistoryToCandidate($candidateId, $educationData)
    {
        $url = $this->apiUrl . "/api/v1/people/{$candidateId}/education";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $this->logger->log("Adding education history for candidate ID: {$candidateId}");
        $this->logger->log("Education data: " . json_encode($educationData));
    
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $educationData,
            ]);
    
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $this->logger->log("Response from adding education history: {$responseBody}");
    
            if ($statusCode === 200 || $statusCode === 201) {
                $this->logger->log("Education history added successfully for candidate ID: {$candidateId}");
                return true;
            } else {
                $this->logger->log("Unexpected response status while adding education history: {$statusCode}");
                return false;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorResponse = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->log("Client exception during adding education history: {$errorResponse}");
            throw new Exception("Error adding education history: {$errorResponse}");
        } catch (Exception $e) {
            $this->logger->log("Unexpected error during adding education history: " . $e->getMessage());
            throw new Exception("Error adding education history: " . $e->getMessage());
        }
    }

    

        /**
         * Link Candidate to Job (Assignment)
         */
        public function linkCandidateToJob($assignmentId, $candidateId, $ownerId, $recordStatusId = null)
    {
        
    
        $recordStatusId = $recordStatusId ?: "SOME_DEFAULT_STATUS_ID"; 
    
        $validStatuses = $this->getAssignmentCandidateStatuses();
      
        $isValidStatus = false;
        foreach ($validStatuses as $status) {
            if ($status['Id'] === $recordStatusId) {
                $isValidStatus = true;
                break;
            }
        }
    
        if (!$isValidStatus) {
            $this->logger->log('Invalid RecordStatusId provided: ' . $recordStatusId);
            throw new Exception('Invalid RecordStatusId provided.');
        }
    
        $url = $this->apiUrl . '/api/v1/assignments/' . $assignmentId . '/candidates';
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
    //        'Content-Type' => 'application/json'
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    
        $postData = [      
            "Candidate" => ["Id" => $candidateId],
            "RecordStatus" => ["Id" => $recordStatusId],
            "FitToProfile" => "Profile fitting data...",
            "InternalComments" => "Linking candidate to job",
            "ProgressNotes" => "Progress notes here",
         ];
    
        $this->logger->log('Sending request to link candidate to job with data: ' . json_encode($postData));
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers, $postData, false);
            $decodedResponse = json_decode($response, true);
            $this->logger->log('Response from linking candidate to job: ' . print_r($decodedResponse, true));
    
            return isset($decodedResponse['Candidate']['Id']);
        } catch (Exception $e) {
            $this->logger->log('Error while linking candidate: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function isCandidateAssignedToJob($assignmentId, $candidateId)
    {
        $url = $this->apiUrl . "/api/v1/assignments/{$assignmentId}/candidates/list";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json'
        ];
        $postData = [
            'Select' => ['ItemId']
        ];
    
        $this->logger->log("Checking if candidate ID {$candidateId} is assigned to job ID {$assignmentId}.");
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers, $postData, true);
            $decodedResponse = json_decode($response, true);
    
            if (!isset($decodedResponse['Items']) || empty($decodedResponse['Items'])) {
                $this->logger->log("No candidates found for assignment ID: {$assignmentId}.");
                return false;
            }
    
            foreach ($decodedResponse['Items'] as $candidate) {
                if (isset($candidate['ItemId']) && $candidate['ItemId'] === $candidateId) {
                    $this->logger->log("Candidate ID {$candidateId} is already assigned to job ID {$assignmentId}.");
                    return true;
                }
            }
    
            $this->logger->log("Candidate ID {$candidateId} is NOT assigned to job ID {$assignmentId}.");
            return false;
        } catch (Exception $e) {
            $this->logger->log("Error checking candidate assignment: " . $e->getMessage());
            throw new Exception("Error checking candidate assignment: " . $e->getMessage());
        }
    }

    public function updateCompanyToPlaceOfStudy($companyId)
    {
        $url = $this->apiUrl . "/api/v1/companies/{$companyId}";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $data = [
            'Fields' => [
                'IsPlaceOfStudy' => 'true'
            ]
        ];
    
        $this->logger->log("Updating company ID $companyId to PlaceOfStudy.");
    
        try {
            $this->makeGuzzleRequest($url, $headers, $data, true, 'PATCH');
            $this->logger->log("Company ID $companyId updated to PlaceOfStudy.");
        } catch (Exception $e) {
            $this->logger->log("Error updating company ID $companyId: " . $e->getMessage());
        }
    }

    public function makeGuzzleRequest($url, $headers, $postData = null, $isJson = false, $httpMethod = null)
    {
        $client = new \GuzzleHttp\Client();
        $this->logger->log('Full URL for Guzzle request: ' . $url);
        
        try {
            $options = [
                'headers' => $headers,
                'timeout' => 30,
                'verify' => false,
            ];
    
            if ($postData) {
                if ($isJson) {
                    $options['json'] = $postData;
                } else {
                    $options['form_params'] = $postData;
                }
            }
    
            $this->logger->log('Post data in guzzle: ' . json_encode($postData));
            
            if ($httpMethod) {
                $method = $httpMethod;
            } else {
                $method = $postData ? 'POST' : 'GET';
            }
            
            $response = $client->request($method, $url, $options);
        
            $responseBody = $response->getBody();
            $responseBody->rewind();
            $fullResponse = $responseBody->getContents();
        
            return $fullResponse;
        
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody();
                $responseBody->rewind();
                $this->logger->log('Full Guzzle Response Body (Error): ' . $responseBody->getContents());
            }
        
            $this->logger->log('Guzzle Error: ' . $e->getMessage());
            throw new \Exception('Guzzle Error: ' . $e->getMessage());
        }
    }
    
    public function getAssignmentCandidateStatuses()
    {
        $url = $this->apiUrl . '/api/v1/settings/AssignmentCandidateStatus';
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    
        $this->logger->log('Fetching Assignment Candidate Statuses from GenericCrm.');
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers, null, true);
            $decodedResponse = json_decode($response, true);
    
            if (isset($decodedResponse['Settings']['ItemReferences'])) {
                $statuses = $decodedResponse['Settings']['ItemReferences'];
                $this->logger->log('Fetched Candidate Statuses: ' . json_encode($statuses));
                return $statuses;
            }
    
            return false;
        } catch (Exception $e) {
            $this->logger->log('Error fetching Assignment Candidate Statuses: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function searchDuplicatePeople($name, $email)
    {
        if (is_array($name)) {
            $name = implode(' ', $name);
        }
    
        if (empty($email)) {
            $this->logger->log('Email is missing, cannot search for duplicate.');
            return false; 
        }
    
        $this->logger->log('Searching for duplicate people with name: ' . $name . ' and email: ' . $email);
    
        $endpoint = '/api/v1/duplicates/people';
        $params = http_build_query([
            'request.personName' => $name,
            'request.emailAddress' => $email,
            'request.pageIndex' => 0,
            'request.pageSize' => 10
        ]);
    
        $url = $this->apiUrl . $endpoint . '?' . $params;
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    
        try {
            $response = $this->makeGuzzleRequest($url, $headers);
            $decodedResponse = json_decode($response, true);
    
            if (!empty($decodedResponse) && isset($decodedResponse[0]['ItemId'])) {
                $this->logger->log('Duplicate person found: ' . $decodedResponse[0]['DisplaySummary']);
                return $decodedResponse[0]; 
            }
    
            $this->logger->log('No duplicate person found');
            return false;
        } catch (Exception $e) {
            $this->logger->log('Error while searching for duplicate people: ' . $e->getMessage());
            throw $e;
        }
    }
    
    
    public function addCandidateFile($candidateId, $fileContent, $fileName)
    {
        $url = $this->apiUrl . '/api/v1/people/' . $candidateId . '/document';
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken()
        ];
    
        $this->logger->log("Starting file upload for candidate ID: $candidateId");
        $this->logger->log("File name: $fileName");
        $this->logger->log("API URL: $url");
    
        if (!$fileContent || !$fileName) {
            $this->logger->log("Invalid file content or file name. Upload aborted.");
            throw new Exception("Invalid file content or file name.");
        }
    
        try {
            $multipartData = [
                [
                    'name'     => 'file',
                    'contents' => $fileContent,
                    'filename' => $fileName
                ]
            ];
    
            $this->logger->log("Preparing multipart data for upload.");
            $this->logger->log("Request headers: " . json_encode($headers));
    
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => $headers,
                'multipart' => $multipartData
            ]);
    
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();
            $this->logger->log("Response status code: $statusCode");
            $this->logger->log("Response body: $responseBody");
    
            if ($statusCode === 200 || $statusCode === 201) {
                $this->logger->log("File uploaded successfully: $fileName");
            } else {
                $this->logger->log("Unexpected response status: $statusCode");
            }
    
            return [$statusCode, json_decode($responseBody, true)];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorResponse = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->log("Client exception during file upload: $errorResponse");
            throw new Exception("Error uploading file to GenericCrm: $errorResponse");
        } catch (Exception $e) {
            $this->logger->log("Unexpected error during file upload: " . $e->getMessage());
            throw new Exception("Error uploading file to GenericCrm: " . $e->getMessage());
        }
    }
    
    public function getCandidateDocuments($candidateId)
    {
        $url = $this->apiUrl . "/api/v1/people/{$candidateId}/documents/list";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json'
        ];
        $postData = [
            'Select' => [
                'AttachmentName',
                'DocumentTypeDisplayText',
                'ExpirationDate',
                'CreatedBy'
            ]
        ];
    
        try {
            $this->logger->log("Fetching documents for candidate ID: {$candidateId}");
            $response = $this->makeGuzzleRequest($url, $headers, $postData, true);
            $decodedResponse = json_decode($response, true);
    
            if (!isset($decodedResponse['Items']) || empty($decodedResponse['Items'])) {
                $this->logger->log("No documents found for candidate ID: {$candidateId}");
                return [];
            }
    
            $this->logger->log("Documents retrieved: " . print_r($decodedResponse['Items'], true));
            return $decodedResponse['Items'];
        } catch (Exception $e) {
            $this->logger->log("Error fetching documents for candidate ID: {$candidateId} - " . $e->getMessage());
            throw new Exception("Error fetching documents: " . $e->getMessage());
        }
    }


    public function setDefaultCV($candidateId, $documentId)
    {
        $url = $this->apiUrl . "/api/v1/people/{$candidateId}/documents/{$documentId}/defaultCv";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
    
        $this->logger->log("Setting default CV for candidate ID: {$candidateId}, document ID: {$documentId}");
        $this->logger->log("API URL: {$url}");
    
        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post($url, [
                'headers' => $headers,
            ]);
    
            $statusCode = $response->getStatusCode();
    
            if ($statusCode === 204) {
                $this->logger->log("Successfully set document as default CV for candidate ID: {$candidateId}");
                return true;
            } else {
                $this->logger->log("Unexpected response status: {$statusCode}");
                return false;
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorResponse = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            $this->logger->log("Client exception during setting default CV: {$errorResponse}");
            throw new Exception("Error setting default CV: {$errorResponse}");
        } catch (Exception $e) {
            $this->logger->log("Unexpected error during setting default CV: " . $e->getMessage());
            throw new Exception("Error setting default CV: " . $e->getMessage());
        }
    }

    public function updateCandidateField($candidateId, $fieldName, $fieldValue)
    {
        $cleanedFieldValue = strip_tags($fieldValue);
    
        $url = $this->apiUrl . "/api/v1/people/{$candidateId}";
        $headers = [
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    
        $data = [
            'Fields' => [
                $fieldName => $cleanedFieldValue
            ]
        ];
    
        $this->logger->log("Updating candidate ID {$candidateId}: setting {$fieldName} to sanitized value.");
        
        try {
            $this->makeGuzzleRequest($url, $headers, $data, true, 'PATCH');
            $this->logger->log("Successfully updated candidate ID {$candidateId}: {$fieldName} set to sanitized value.");
            return true;
        } catch (Exception $e) {
            $this->logger->log("Error updating candidate ID {$candidateId}: " . $e->getMessage());
            return false;
        }
    }
    
    public function log($message) {
        if ($this->logger) {
            $this->logger->log($message); 
        }
    }

    public function getAccessTokenExpireTS() {
        return $this->accessTokenExpireTS;
    }
        
    public function getAccessToken()
    {
        $this->ensureValidToken();
        return $this->accessToken;
    }






     public function getLogger() {
        return $this->logger;
     }   
    public function getRefreshToken() {
        return $this->refreshToken;
    }
    
    
}
