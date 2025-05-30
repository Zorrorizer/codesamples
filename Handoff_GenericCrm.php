<?php
require_once __DIR__ . '/GenericCrm_API.php';
require_once __DIR__ . '/GenericCrm_CVparser.php';


class App_AM_Worker_Handoff_GenericCrm extends App_AM_Worker_Handoff_Abstract
{
    public $GenericCrmClient;
    public $personID;
    
    protected $config;
    protected $settings;
    protected $logger;
    protected $candidateDataStored = [];
    protected $internalComments;
    protected $linkCandidateToJob = true;
    
    public function __construct($settings, $handoffID, $projectID, $personID, $handoff_options)
    {
        $this->initFileLogger('handoff_GenericCrm_worker', $projectID, $personID);
        $this->settings = $settings;
        
        //log settings
        $this->logger->log('Settings in __construct:'. print_r($settings, true));
    
        $handoffActive = ZX_PluginsCommander::getInstance()->getStorage()->getOption('handoff_active');
        $this->logger->log('handoff_active=' . var_export($handoffActive, true));
    
        $loginHandoffID = intval(Zend_Registry::get('login')->handoffID);
        $this->logger->log('loginHandoffID=' . $loginHandoffID);
    
        $username = isset($this->settings['username']) ? $this->settings['username'] : null;
        $password = isset($this->settings['password']) ? $this->settings['password'] : null;
        $domainName = isset($this->settings['domain_name']) ? $this->settings['domain_name'] : null;
    
        if (!$username || !$password || !$domainName) {
            $this->logger->log('Missing required settings: username, password, or domain_name.');
            throw new Exception('Invalid settings: username, password, and domain_name are required.');
        }
        
        $clientId = $this->config->externalcrm->api_client_id;
        $clientSecret = $this->config->externalcrm->api_client_secret;
    
        $this->GenericCrmClient = new GenericCrm_API_Client(
            $domainName,
            $clientId,
            $clientSecret,
            $username,
            $password,
            $this->logger
        );
    
        $this->logger->log('GenericCrm worker initialized.');
        parent::__construct($settings, $handoffID, $projectID, $personID, $handoff_options);
    }

    protected function init()
    {
        $this->logger->log('GenericCrm handoff initialized.');
    }

    private function initializeLogger() {
        $this->logger = new \Generic_FileLogger_Manager('handoff_GenericCrm');
    }

    public function checkConnect()
    {
        try {
            $username = isset($this->settings['username']) ? $this->settings['username'] : null;
            $password = isset($this->settings['password']) ? $this->settings['password'] : null;
            $accessToken = isset($this->settings['accessToken']) ? $this->settings['accessToken'] : null;
            $accessTokenExpireTS = isset($this->settings['accessTokenExpireTS']) ? strtotime($this->settings['accessTokenExpireTS']) : null;
    
            $this->logger->log("checkConnect called. Username: $username, AccessTokenExpireTS: $accessTokenExpireTS, Current time: " . time());
    
            if ($accessToken && $accessTokenExpireTS && time() < $accessTokenExpireTS) {
                $this->logger->log('Token is valid and not expired. Connection successful.');
                return [
                    'status' => true,
                    'message' => 'Token is valid and not expired.',
                ];
            }
    
            if ($username && $password) {
                $this->logger->log("Obtaining new access token using ROPC...");
                $newAccessToken = $this->GenericCrmClient->obtainAccessTokenWithROPC($username, $password);
    
                if ($newAccessToken) {
                    $updateData = [
                        'accessToken' => $newAccessToken,
                        'accessTokenExpireTS' => date('Y-m-d H:i:s', $this->GenericCrmClient->getAccessTokenExpireTS()),
                    ];
    
                    $handoffModel = new Generic_Model_HandoffGenericCrm();
                    $handoffModel->saveHandoffSettings($updateData);
    
                    $this->logger->log("Token refreshed and saved to database: " . json_encode($updateData));
                    return [
                        'status' => true,
                        'message' => 'Successfully connected to GenericCrm using ROPC.',
                    ];
                }
            } else {
                throw new Exception('Missing credentials for ROPC.');
                return[
                    'status' => false,
                    'message' => 'Missing credentials for ROPC.',
                ];
            }
        } catch (Exception $e) {
            $this->logger->log("Error in checkConnect: " . $e->getMessage());
            return [
                'status' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    public function handleAuthorization()
    {
        if (!$this->GenericCrmClient->getAccessToken() || time() > $this->GenericCrmClient->getAccessTokenExpireTS()) {
            $this->logger->log('Access token expired or not available. Obtaining a new token...');
            
            try {
                $username = isset($this->settings['username']) ? $this->settings['username'] : null;
                $password = isset($this->settings['password']) ? $this->settings['password'] : null;
    
                if (!$username || !$password) {
                    throw new Exception('Username or password is missing in settings.');
                }
    
                $newAccessToken = $this->GenericCrmClient->obtainAccessTokenWithROPC($username, $password);
    
                if ($newAccessToken) {
                    $this->logger->log('Successfully obtained a new access token.');
                } else {
                    throw new Exception('Failed to obtain a new access token.');
                }
            } catch (Exception $e) {
                $this->logger->log('Error during authorization: ' . $e->getMessage());
                throw $e;
            }
        } else {
            $this->logger->log('Access token is valid.');
        }
    }

    /**
     * The main method for exporting a candidate to GenericCrm,
     *
     * @param array $custom Any parameters passed in the queue
     * @return bool
     * @throws Exception
     */
    public function candidateExport($custom)
    {
        if (isset($custom['linkCandidateToJob'])) {
            $this->linkCandidateToJob = $custom['linkCandidateToJob'];
        }
        
        $this->logger->log('linkCandidateToJob:'. print_r($this->linkCandidateToJob, true));

        try {
            $this->setLogAction('Attempting to add candidate to CRM.');

            // Parse CV
            $parsedCV = $this->parseCVusingCRM();
            if (!$parsedCV) {
                $this->setLogAction('CV parsing failed. Cannot proceed with candidate export.');
                return false;
            }
            $this->setLogAction('Raw parsed CV data: ' . json_encode($parsedCV));

            // Employment history
            $this->setLogAction('Processing Positions...');
            foreach ($parsedCV['Positions'] as $k => &$position) {
                if (!isset($position['Company']) || !is_array($position['Company'])) {
                    $position['Company'] = array();
                }
                $originalCompanyName = isset($position['Company']['CompanyName']) ? $position['Company']['CompanyName'] : '';
                $this->setLogAction('Original company name in position [' . $k . ']: ' . $originalCompanyName);

                if (!isset($position['Company']['CompanyName']) || empty(trim($position['Company']['CompanyName']))) {
                    $position['Company']['CompanyName'] = 'Company_Name_Not_Provided';
                    $this->setLogAction('Company name not provided, setting default: Company_Name_Not_Provided');
                }
                $this->setLogAction('Final company name for position [' . $k . ']: ' . $position['Company']['CompanyName']);
                $companyId = $this->findOrCreateCompany($position['Company']['CompanyName']);
                $this->setLogAction('findOrCreateCompany returned: ' . $companyId);

                if (!$companyId || !preg_match('/^[a-f0-9\-]{36}$/', $companyId)) {
                    $companyId = 'DEFAULT_COMPANY_ID'; // Replace with a valid default company ID
                    $this->setLogAction('Invalid companyId detected, setting default GUID: ' . $companyId);
                }

                $position['Company']['Id'] = $companyId;
                $this->setLogAction('Position [' . $k . '] updated with Company ID: ' . $companyId);
                $parsedCV['Positions'][$k] = $position;
            }
            unset($position);
            $this->setLogAction('Positions after processing: ' . json_encode($parsedCV['Positions']));

            // Education history
            $this->setLogAction('Processing Education...');
            foreach ($parsedCV['Education'] as $k => &$education) {
                if (!isset($education['Company']) || !is_array($education['Company'])) {
                    $education['Company'] = array();
                }
                $originalInstitutionName = isset($education['Company']['CompanyName']) ? $education['Company']['CompanyName'] : '';
                $this->setLogAction('Original institution name in education [' . $k . ']: ' . $originalInstitutionName);

                if (!isset($education['Company']['CompanyName']) || empty(trim($education['Company']['CompanyName']))) {
                    $education['Company']['CompanyName'] = 'Institution_Name_Not_Provided';
                    $this->setLogAction('Institution name not provided, setting default: Institution_Name_Not_Provided');
                }
                $this->setLogAction('Final institution name for education [' . $k . ']: ' . $education['Company']['CompanyName']);
                $institutionId = $this->findOrCreateCompany($education['Company']['CompanyName'], true);
                $this->setLogAction('findOrCreateCompany (education) returned: ' . $institutionId);

                if (!$institutionId || !preg_match('/^[a-f0-9\-]{36}$/', $institutionId)) {
                    $institutionId = '1dd96814-49ab-4c2f-9a69-293399184008';
                    $this->setLogAction('Invalid institutionId detected, setting default GUID: ' . $institutionId);
                }

                $education['Company']['Id'] = $institutionId;
                $this->setLogAction('Education [' . $k . '] updated with Institution ID: ' . $institutionId);
                $parsedCV['Education'][$k] = $education;
            }
            unset($education);
            $this->setLogAction('Education after processing: ' . json_encode($parsedCV['Education']));

            // Complete candidate data
            $completeCandidateData = $this->completeCandidateData($parsedCV);
            $this->setLogAction('Complete candidate data: ' . json_encode($completeCandidateData));

            // Check for duplicates
            $duplicateCandidate = $this->GenericCrmClient->searchDuplicatePeople(
                $completeCandidateData['NameComponents']['FullName'],
                $completeCandidateData['EmailAddresses'][0]['ItemValue']
            );

            $remoteProjectID = $this->getRemoteProjectID(array('GenericCrm'));
            $crmUserId       = isset($completeCandidateData['Owner']['Id']) ? $completeCandidateData['Owner']['Id'] : '';

            if ($duplicateCandidate) {
                $this->setLogAction('Candidate export aborted: Duplicate candidate detected.');

                if (!$this->candidateWasAlreadyExported()) {
                    $this->setLogAction("Importing candidate files..");
                    $this->addCandidateFiles($duplicateCandidate['ItemId']);
                    $this->setLogAction("Candidate files imported.");
                    
                    $this->setLogAction("Linking existing candidate " . $duplicateCandidate['ItemId'] . " to job: " . $remoteProjectID);
                    $this->linkCandidateToJob($remoteProjectID, $duplicateCandidate['ItemId'], $crmUserId);
                    
                    $this->candidateAdded($duplicateCandidate['ItemId'], 'duplicate', array());
                    return false;
                }
                $this->candidateAdded($duplicateCandidate['ItemId'], 'duplicate', array());
                return false;
            }

            $candidateId = $this->GenericCrmClient->addCandidateToCRM($completeCandidateData);
            if (!$candidateId) {
                $this->setLogAction('Failed to add candidate to CRM.');
                return false;
            }
            $this->setLogAction('Candidate added to CRM, ID: ' . $candidateId);

            // Move history updates here to ensure they are done only once
            $this->updateWorkHistory($candidateId, $parsedCV['Positions']);
            $this->updateEducationHistory($candidateId, $parsedCV['Education']);

            $this->candidateAdded($candidateId, 'new', array());

            return true;
        } catch (Exception $e) {
            $this->setLogAction('Error occurred during candidate export: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Method called after a candidate is added (or found) in the GenericCrm CRM.
     * updates work/education history, and optionally handles files/notes, etc.
     *
     * @param string $candidateId   The candidate's GUID in GenericCrm
     * @param string $candidate_status e.g. 'new', 'duplicate', or 'existing'
     * @param array  $parsedCV      Array of data from parseCVusingCRM (Positions/Education/Person, etc.)
     */
    protected function candidateAdded($candidateId, $candidate_status, $parsedCV)
    {
        $this->setLogAction("candidateAdded called. candidateId={$candidateId}, status={$candidate_status}");
    
        $remoteProjectID = $this->getRemoteProjectID(array('GenericCrm'));
       
        $crmUserId = ''; 
    
        // link to job if not already linked
        $this->setLogAction("Checking if candidate is already linked or not. We'll link if needed.");
        $alreadyLinked = false;
        if ($remoteProjectID) {
            // check if candidate is already linked to job
            if ($this->GenericCrmClient->isCandidateAssignedToJob($remoteProjectID, $candidateId)) {
                $this->setLogAction("Candidate is already linked to jobID: {$remoteProjectID}");
                $alreadyLinked = true;
            }
    
            if (!$alreadyLinked) {
                $this->setLogAction("Linking candidate to jobID={$remoteProjectID} now.");
                $this->linkCandidateToJob($remoteProjectID, $candidateId, $crmUserId);
            }
        }
    
        // Save sync data
        if (!empty($this->projectID)) {
            (new Generic_Model_HandoffCandidateSync())->setSynced($this->handoffID, $this->projectID, $this->personID, $candidateId);
        }
    
        if ($candidate_status == 'new') {
            $this->setLogAction('Candidate is new, performing updates for new candidate...');
        } else {
            $this->setLogAction("Candidate status = {$candidate_status}, let's still do updates if needed...");
        }
    
        // 1) Update work history
        try {
            $this->setLogAction("Updating work history for candidate ID={$candidateId}");
            if (isset($parsedCV['Positions']) && is_array($parsedCV['Positions'])) {
                $this->updateWorkHistory($candidateId, $parsedCV['Positions']);
            } else {
                $this->setLogAction("No 'Positions' found in parsedCV, skipping work history update.");
            }
        } catch (Exception $ex) {
            $this->setLogAction('Error during updateWorkHistory: '.$ex->getMessage());
        }
    
        // 2) Update education history
        try {
            $this->setLogAction("Updating education history for candidate ID={$candidateId}");
            if (isset($parsedCV['Education']) && is_array($parsedCV['Education'])) {
                $this->updateEducationHistory($candidateId, $parsedCV['Education']);
            } else {
                $this->setLogAction("No 'Education' found in parsedCV, skipping education update.");
            }
        } catch (Exception $ex) {
            $this->setLogAction('Error during updateEducationHistory: '.$ex->getMessage());
        }
    
        // 3) Import files (CV, attachments) – if we want to handle it in candidateAdded
        if ($candidate_status == 'new') {
            $this->setLogAction("Importing candidate files for a new candidate...");
            $this->addCandidateFiles($candidateId);
            $this->setLogAction("Candidate files imported for candidate ID={$candidateId}");
        } else {
            $this->setLogAction("Skipping addCandidateFiles for candidate_status={$candidate_status}, or do if needed.");
        }
    
        if ($this->settings['sync_candidate_notes_to_crm']) {
            $this->setLogAction("NOT adding notes to 'InternalComments' per Managers request, only logging.");
            // $internalCommentsToSend = $this->addCandidateNotesToString($candidateId);
            // $this->setLogAction("We would set 'InternalComments' => ".$internalCommentsToSend);
            // Actually not updating: $this->GenericCrmClient->updateCandidateField($candidateId, 'InternalComments', $internalCommentsToSend);
        }
    
        $this->setLogAction("candidateAdded done for candidate ID={$candidateId}, status={$candidate_status}.");
    }
        
    protected function candidateWasAlreadyExported() {
        return (new Generic_Model_HandoffCandidateSync())->getSyncedCrmID($this->handoffID, $this->personID);
    }

    public function findOrCreateCompany($companyName, $isPlaceOfStudy = false)
    {
        if (empty($companyName)) {
            $this->logger->log("Company name is empty, skipping lookup.");
            return null;
        }
    
        $company = $this->GenericCrmClient->searchCompany($companyName);
        
        if ($company) {
            $this->logger->log("Company found: " . print_r($company, true));
            $companyId = $company['ItemId'];
                
            if ($isPlaceOfStudy && (empty($company['IsPlaceOfStudy']) || $company['IsPlaceOfStudy'] === false)) {
                $this->logger->log("Updating existing company as PlaceOfStudy: $companyName (ID: $companyId)");
                $this->GenericCrmClient->updateCompanyToPlaceOfStudy($companyId);
            }
    
            return $companyId;
        }
    
        $companyData = [
            'CompanyName'    => $companyName,
            'IsPlaceOfStudy' => $isPlaceOfStudy ? 'true' : 'false',
            'IsClient'       => !$isPlaceOfStudy ? 'true' : 'false',
            'IsSupplier'     => 'false',
            'IsPartner'      => 'false',
        ];
    
        $companyId = $this->GenericCrmClient->createCompany($companyData);
    
        if ($companyId && preg_match('/^[a-f0-9\-]{36}$/', $companyId)) {
            $this->logger->log("Created new company ID: $companyId (PlaceOfStudy: " . ($isPlaceOfStudy ? 'true' : 'false') . ")");
        } else {
            $this->logger->log("Failed to create company: $companyName. Response ID: " . var_export($companyId, true));
            return null;
        }
    
        return $companyId;
    }

    protected function completeCandidateData($parsedCV)
    {
        $this->setLogAction('Completing candidate data with system information.');

        $systemData = parent::getCandidateData(true);

        $candidateData = [
            'OptionalId' => isset($systemData['candidateCustomInfo']['OptionalId']) ? $systemData['candidateCustomInfo']['OptionalId'] : '',
            'DefaultPosition' => [
                'OptionalId' => '',
                'JobTitle' => isset($parsedCV['Positions'][0]['JobTitle']) ? $parsedCV['Positions'][0]['JobTitle'] : (isset($systemData['profile_info']['occupation']) ? $systemData['profile_info']['occupation'] : ''),
                'StartDate' => isset($parsedCV['Positions'][0]['StartDate']) ? $parsedCV['Positions'][0]['StartDate'] : '',
                'EndDate' => '',
                'PositionStatus' => 'Current',
                'PositionType' => [
                    'Value' => 0,
                ],
                'IsDefault' => "true",
                'Company' => [
                    'Id' => isset($parsedCV['Positions'][0]['Company']['Id']) ? $parsedCV['Positions'][0]['Company']['Id'] : 'DEFAULT_COMPANY_ID',
                ],
                'Description' => isset($parsedCV['Positions'][0]['Description']) ? $parsedCV['Positions'][0]['Description'] : '',
                'InternalComments' => 'Cras in augue varius tellus tincidunt luctus',
            ],
            'Positions' => isset($parsedCV['Positions']) ? $parsedCV['Positions'] : [],
            'Owner' => [
                'Id' => isset($systemData['crmUserId']) ? $systemData['crmUserId'] : '',
            ],
            'NameComponents' => [
                'FullName' => isset($parsedCV['Person']['NameComponents']['FullName']) ? $parsedCV['Person']['NameComponents']['FullName'] : '',
                'FamilyName' => isset($parsedCV['Person']['NameComponents']['FamilyName']) ? $parsedCV['Person']['NameComponents']['FamilyName'] : '',
                'FirstName' => isset($parsedCV['Person']['NameComponents']['FirstName']) ? $parsedCV['Person']['NameComponents']['FirstName'] : '',
                'Title' => 'Miss',
            ],
            'CandidateStatus' => [
                'Id' => 'DEFAULT_CANDIDATE_STATUS_ID',
            ],
            "IsCandidate" => 'true',
            "IsPermanentCandidate" => 'true',
            "IsInterimCandidate" => 'false',
            "IsNonExecCandidate" => 'false',
            "IsDoNotMailshot" => 'true',
            "IsDoNotContact" => 'false',
            'HomeAddress' => [
                'FullAddress' => isset($parsedCV['Person']['Location']['AddressComponents']['FullAddress']) ? $parsedCV['Person']['Location']['AddressComponents']['FullAddress'] : 'missing data',
                'Street' => isset($parsedCV['Person']['Location']['AddressComponents']['Street']) ? $parsedCV['Person']['Location']['AddressComponents']['Street'] : 'missing data',
                'TownCity' => isset($parsedCV['Person']['Location']['AddressComponents']['TownCity']) ? $parsedCV['Person']['Location']['AddressComponents']['TownCity'] : 'missing data',
                'Postcode' => isset($parsedCV['Person']['Location']['AddressComponents']['Postcode']) ? $parsedCV['Person']['Location']['AddressComponents']['Postcode'] : 'missing data',
                'Country' => isset($parsedCV['Person']['Location']['AddressComponents']['Country']) ? $parsedCV['Person']['Location']['AddressComponents']['Country'] : 'missing data',
            ],
            "EmailAddresses" => [
                [
                    "IsPersonal" => 'true',
                    "IsBusiness" => 'false',
                    "PreferredDisplayOrderIndexLegacy" => 0,
                    "PreferredDisplayOrderIndex" => 0,
                    "IsVisibleAsDefault" => 'true',
                    "FieldName" => isset($parsedCV['Person']['EmailAddresses'][0]['FieldName']) ? $parsedCV['Person']['EmailAddresses'][0]['FieldName'] : '',
                    "ItemValue" => isset($parsedCV['Person']['EmailAddresses'][0]['ItemValue']) ? $parsedCV['Person']['EmailAddresses'][0]['ItemValue'] : '',
                ]
            ],
            "PhoneNumbers" => [
                [
                    "IsPrimary" => 'true',
                    "PreferredDisplayOrderIndexLegacy" => 0,
                    "PreferredDisplayOrderIndex" => 0,
                    "FormattedValue" => isset($parsedCV['Person']['PhoneNumbers'][0]['FormattedValue']) ? $parsedCV['Person']['PhoneNumbers'][0]['FormattedValue'] : '',
                    "IsVisibleAsDefault" => 'true',
                    "FieldName" => isset($parsedCV['Person']['PhoneNumbers'][0]['FieldName']) ? $parsedCV['Person']['PhoneNumbers'][0]['FieldName'] : '',
                    "ItemValue" => isset($parsedCV['Person']['PhoneNumbers'][0]['ItemValue']) ? $parsedCV['Person']['PhoneNumbers'][0]['ItemValue'] : '',
                ]
            ],
        ];

        if (isset($parsedCV['Education']) && count($parsedCV['Education']) > 1) {
            array_shift($parsedCV['Education']);
        }

        $this->setLogAction('Candidate data completed: ' . print_r($candidateData, true));
        return $candidateData;
    }


    public function updateWorkHistory($candidateId, $positions)
    {    
        if (count($positions) > 1) {
            array_shift($positions);
        }
        
        foreach ($positions as $position) {
            if (!empty($position['Company']['Id']) && preg_match('/^[a-f0-9\-]{36}$/', $position['Company']['Id'])) {
                $companyId = $position['Company']['Id'];
            } elseif (!empty($position['SuggestedCompanies'][0]['ItemId'])) {
                $companyId = $position['SuggestedCompanies'][0]['ItemId'];
            } elseif (!empty($position['Company']['CompanyName'])) {
                $companyId = $this->findOrCreateCompany($position['Company']['CompanyName']);
            } else {
                $companyId = null;
            }
        
            if (!$companyId || !preg_match('/^[a-f0-9\-]{36}$/', $companyId)) {
                $this->logger->log("Skipping work history entry due to missing or invalid Company ID: " . var_export($companyId, true));
                continue;
            }
        
            $jobTitle    = isset($position['JobTitle']) ? $position['JobTitle'] : 'Unknown Job Title';
            $description = isset($position['Description']) ? $position['Description'] : 'No description provided';
            $startDate   = isset($position['StartDate']) ? $position['StartDate'] : '1900-01-01T00:00:00';
            $endDate     = isset($position['EndDate']) ? $position['EndDate'] : date('Y-m-d\TH:i:s');
        
            if (strtotime($startDate) >= strtotime($endDate)) {
                $endDate = date('Y-m-d\TH:i:s');
            }
        
            $workData = [
                'JobTitle'       => $jobTitle,
                'Company'        => ['Id' => $companyId],
                'Description'    => $description,
                'StartDate'      => $startDate,
                'EndDate'        => $endDate,
                'PositionStatus' => isset($position['PositionStatus']['Value']) ? $position['PositionStatus']['Value'] : 0,
            ];
        
            $this->logger->log("Prepared work data for candidate ID: $candidateId. Data: " . json_encode($workData));
        
            try {
                $this->GenericCrmClient->addWorkHistoryToCandidate($candidateId, $workData);
                $this->logger->log("Work history added successfully for candidate ID: $candidateId.");
            } catch (Exception $e) {
                $this->logger->log("Error adding work history for candidate ID: $candidateId - " . $e->getMessage());
            }
        }
    }
    
    public function updateEducationHistory($candidateId, $educationRecords)
    {
        if (count($educationRecords) > 1) {
            array_shift($educationRecords);
        }
        
        foreach ($educationRecords as $education) {
            if (!empty($education['Company']['Id']) && preg_match('/^[a-f0-9\-]{36}$/', $education['Company']['Id'])) {
                $institutionId = $education['Company']['Id'];
            } elseif (!empty($education['SuggestedCompanies'][0]['ItemId'])) {
                $institutionId = $education['SuggestedCompanies'][0]['ItemId'];
            } elseif (!empty($education['Company']['CompanyName'])) {
                $institutionId = $this->findOrCreateCompany($education['Company']['CompanyName'], true);
            } else {
                $institutionId = null;
            }
        
            if (!$institutionId || !preg_match('/^[a-f0-9\-]{36}$/', $institutionId)) {
                $this->logger->log("Skipping education entry due to missing or invalid Institution ID: " . var_export($institutionId, true));
                continue;
            }
        
            $subject    = isset($education['Subject']) ? $education['Subject'] : 'Unknown Degree';
            $startDate  = isset($education['StartDate']) ? $education['StartDate'] : '1900-01-01T00:00:00';
            $endDate    = isset($education['EndDate']) ? $education['EndDate'] : date('Y-m-d\TH:i:s');
            
            if (strtotime($startDate) >= strtotime($endDate)) {
                $endDate = date('Y-m-d\TH:i:s');
            }
        
            $educationData = [
                'Company'     => ['Id' => $institutionId],
                'Subject'     => $subject,
                'StartDate'   => $startDate,
                'EndDate'     => $endDate,
                'Qualification' => isset($education['Qualification']) ? $education['Qualification'] : [],
            ];
        
            $this->logger->log("Prepared education data for candidate ID: $candidateId. Data: " . json_encode($educationData));
        
            try {
                $this->GenericCrmClient->addEducationHistoryToCandidate($candidateId, $educationData);
                $this->logger->log("Education history added successfully for candidate ID: $candidateId.");
            } catch (Exception $e) {
                $this->logger->log("Error adding education history for candidate ID: $candidateId - " . $e->getMessage());
            }
        }
    }

    public function updateEducationHistory_o($candidateId, $education)
    {
        foreach ($education as $entry) {
            $institutionName = (isset($entry['Company']['CompanyName']) && !empty(trim($entry['Company']['CompanyName'])))
                ? $entry['Company']['CompanyName']
                : 'Institution_Name_Not_Provided';
        
            if (!empty($entry['SuggestedCompanies'][0]['ItemId']) && preg_match('/^[a-f0-9\-]{36}$/', $entry['SuggestedCompanies'][0]['ItemId'])) {
                $institutionId = $entry['SuggestedCompanies'][0]['ItemId'];
            } else {
                $institutionId = $this->findOrCreateCompany($institutionName, true);
            }
        
            if (!$institutionId || !preg_match('/^[a-f0-9\-]{36}$/', $institutionId)) {
                $this->logger->log("Skipping education entry due to missing or invalid Institution ID: " . var_export($institutionId, true));
                continue;
            }
                        
            $educationData = [
             

                    'SuggestedCompanies' => [
                        [
                            'ItemId' => $institutionId,
                            'DisplayName' => $institutionName,
                        ],
                    ],
                    'CompanyName' => $institutionName,
                    'Subject'       => isset($entry['Qualification']['DisplayTitle']) ? $entry['Qualification']['DisplayTitle'] : 'Unknown Degree',
                    'StartDate'     => isset($entry['StartDate']) ? $entry['StartDate'] : '1900-01-01T00:00:00',
                    'EndDate'       => isset($entry['EndDate']) ? $entry['EndDate'] : date('Y-m-d\TH:i:s'),
                    'Company'       => [
                        
                        'Id' => $institutionId,
                        'ItemId' => $institutionId,
                        'CompanyName' => $institutionName,
                        'DisplaySummary' => $institutionName,
                        'DisplayName' => $institutionName,
                        'ItemType' => 'Companies'
                    ],
                    
            ];

        
            $this->logger->log("Prepared education data for candidate ID: $candidateId. Data: " . json_encode($educationData));
        
            try {
                $this->GenericCrmClient->addEducationHistoryToCandidate($candidateId, $educationData);
                $this->logger->log("Education history added successfully for candidate ID: $candidateId.");
            } catch (Exception $e) {
                $this->logger->log("Error adding education history for candidate ID: $candidateId - " . $e->getMessage());
            }
        }
    }

    public function linkCandidateToJob($assignmentId, $candidateId, $crmUserId)
    {
        if (empty($this->linkCandidateToJob)) {
            $this->setLogAction('Job not linked. Argument linkCandidateToJob is false', __LINE__);
            return false;
        }
        
        try {
            $this->setLogAction("Checking if candidate ID {$candidateId} is already linked to job ID {$assignmentId}.");
            
            if ($this->GenericCrmClient->isCandidateAssignedToJob($assignmentId, $candidateId)) {
                $this->setLogAction("Candidate ID {$candidateId} is already linked to job ID {$assignmentId}, skipping.");
                return true;
            }
    
            $this->setLogAction("Linking candidate ID {$candidateId} to job ID {$assignmentId}.");
            return $this->GenericCrmClient->linkCandidateToJob($assignmentId, $candidateId, $crmUserId);
        } catch (Exception $e) {
            $this->setLogAction("Error linking candidate ID {$candidateId} to job ID {$assignmentId}: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getCandidateData($safeData = false)
    {
        if (!empty($this->candidateDataStored)) {
            return $this->candidateDataStored;
        }
    
        $result = parent::getCandidateData(true);
        $this->setLogAction('Candidate info before parse: ' . print_r($result, true));
    
        $crmParsedCV = $this->parseCVusingCRM();
        $this->setLogAction('Candidate info from parse: ' . print_r($crmParsedCV, true));
    
        if ($crmParsedCV) {
            $result['profile_info']['first_name'] = isset($crmParsedCV['NameComponents']['FirstName'])
                ? $crmParsedCV['NameComponents']['FirstName']
                : (isset($result['profile_info']['first_name']) ? $result['profile_info']['first_name'] : 'Unknown');
    
            $result['profile_info']['last_name'] = isset($crmParsedCV['NameComponents']['FamilyName'])
                ? $crmParsedCV['NameComponents']['FamilyName']
                : (isset($result['profile_info']['last_name']) ? $result['profile_info']['last_name'] : 'Unknown');
    
            $result['profile_info']['email'] = isset($crmParsedCV['EmailAddresses'][0]['ItemValue'])
                ? $crmParsedCV['EmailAddresses'][0]['ItemValue']
                : (isset($result['profile_info']['email']) ? $result['profile_info']['email'] : 'unknown@example.com');
    
            $result['profile_info']['phone'] = isset($crmParsedCV['PhoneNumbers'][0]['ItemValue'])
                ? $crmParsedCV['PhoneNumbers'][0]['ItemValue']
                : (isset($result['profile_info']['phone']) ? $result['profile_info']['phone'] : '+00000000000');
    
            $result['candidateCustomInfo']['profile_history_info']['profile_work_history'] = isset($crmParsedCV['Positions'])
                ? array_map(function ($position) {
                    return [
                        'Description' => isset($position['Description']) ? $position['Description'] : '',
                        'Company' => isset($position['Company']['CompanyName']) ? $position['Company']['CompanyName'] : '',
                        'StartDate' => isset($position['StartDate']) ? $position['StartDate'] : '',
                        'EndDate' => isset($position['EndDate']) ? $position['EndDate'] : '',
                        'PositionStatus' => isset($position['PositionStatus']['Value']) ? $position['PositionStatus']['Value'] : 0
                    ];
                }, $crmParsedCV['Positions'])
                : [];
    
            $result['candidateCustomInfo']['profile_history_info']['profile_education_history'] = isset($crmParsedCV['Education'])
                ? array_map(function ($education) {
                    return [
                        'Institution' => isset($education['Company']['CompanyName']) ? $education['Company']['CompanyName'] : '',
                        'Degree' => isset($education['Qualification']['ItemDisplayText']) ? $education['Qualification']['ItemDisplayText'] : '',
                        'StartDate' => isset($education['StartDate']) ? $education['StartDate'] : '',
                        'EndDate' => isset($education['EndDate']) ? $education['EndDate'] : ''
                    ];
                }, $crmParsedCV['Education'])
                : [];
        }
    
        $candidateData = [
            'NameComponents' => [
                'FullName' => trim(
                    (isset($result['profile_info']['first_name']) ? $result['profile_info']['first_name'] : '') . ' ' .
                    (isset($result['profile_info']['last_name']) ? $result['profile_info']['last_name'] : '')
                ),
                'FirstName' => isset($result['profile_info']['first_name']) ? $result['profile_info']['first_name'] : '',
                'FamilyName' => isset($result['profile_info']['last_name']) ? $result['profile_info']['last_name'] : '',
                'Title' => '',
                'MiddleName' => '',
                'Suffix' => '',
                'Nickname' => '',
                'MaidenName' => ''
            ],
            'EmailAddresses' => [
                [
                    'IsPersonal' => 'false',
                    'FieldName' => 'Email1Address',
                    'ItemValue' => isset($result['profile_info']['email']) ? $result['profile_info']['email'] : 'unknown@example.com'
                ]
            ],
            'PhoneNumbers' => [
                [
                    'IsPrimary' => 'false',
                    'FieldName' => 'MobilePhone',
                    'ItemValue' => isset($result['profile_info']['phone']) ? $result['profile_info']['phone'] : '+00000000000',
                    'FormattedValue' => isset($result['profile_info']['phone']) ? $result['profile_info']['phone'] : '+00000000000',
                    'IsVisibleAsDefault' => 'true'
                ]
            ],
            'Education' => isset($result['candidateCustomInfo']['profile_history_info']['profile_education_history'])
                ? $result['candidateCustomInfo']['profile_history_info']['profile_education_history']
                : [],
            'Positions' => isset($result['candidateCustomInfo']['profile_history_info']['profile_work_history'])
                ? $result['candidateCustomInfo']['profile_history_info']['profile_work_history']
                : [],
            'IsPermanentCandidate' => 'true',
            'IsVIP' => 'false',
            'IsWillingToTravel' => 'true',
            'IsWillingToRelocate' => 'true'
        ];
    
        $this->candidateDataStored = $candidateData;
        $this->setLogAction('Final candidate data: ' . print_r($candidateData, true));
        return $this->candidateDataStored;
    }


    protected function isDuplicate($candidateData)
    {
        $this->setLogAction('Checking for duplicate candidate.');
    
        $fullName = isset($candidateData['NameComponents']['FullName']) ? trim($candidateData['NameComponents']['FullName']) : '';
        $email = isset($candidateData['EmailAddresses'][0]['ItemValue']) ? $candidateData['EmailAddresses'][0]['ItemValue'] : '';
    
        $this->setLogAction("Prepared FullName: '{$fullName}', Email: '{$email}'");
    
        if (empty($fullName) || empty($email)) {
            $this->setLogAction("FullName or Email is missing: FullName = '{$fullName}', Email = '{$email}'");
            return false;
        }
    
        $response = $this->GenericCrmClient->searchDuplicatePeople($fullName, $email);
    
        if (!empty($response)) {
            $this->setLogAction('Duplicate candidate found: ' . print_r($response, true));
            return true;
        }
    
        $this->setLogAction('No duplicate candidate found.');
        return false;
    }

    
    protected function parseCVusingCRM()
    {
        $CV = $this->getCV();
        $this->setLogAction('CV info: ' . print_r($CV, true));
    
        if (!$CV || !isset($CV['filename'], $CV['contents'])) {
            $this->setLogAction('No valid CV found for parsing.');
            return false;
        }
    
        $parser = new GenericCrmCVParser($this->GenericCrmClient, $this->getLogger());
        try {
            $documentId = $parser->uploadAndParseCV($CV);
            if (!$documentId) {
                $this->setLogAction('Failed to parse CV. Document ID is null.');
                return false;
            }
    
            $parsedData = $parser->getParsedCVDetails($documentId);
            if (!$parsedData) {
                $this->setLogAction('Parsed data is empty or invalid.');
                return false;
            }
    
            $this->setLogAction('CV parsed successfully. Parsed data: ' . print_r($parsedData, true));
            return $parsedData;
        } catch (Exception $e) {
            $this->setLogAction('Error during CV parsing: ' . $e->getMessage());
            return false;
        }
    }
                       
    
    protected function getCV() 
    {
        $cvRow = (new Generic_Model_AppHiveFiles)->getCVDocRow($this->personID, false);

        if ($cvRow) {
            $contents = $cvRow->getContent();
            return [
                'filename' => $cvRow->FileNameOrg,
                'contents' => $contents
            ];
        }

        return false;
    }
 
    protected function addCandidateFiles($GenericCrmCandidateID)
    {
        $documents = (new Generic_Model_AppHiveFiles())->getDocumentsList($this->personID, null, null, null, false, true);
    
        if (empty($documents)) {
            $this->setLogAction("No documents found for person ID: {$this->personID}", __LINE__);
            return false;
        }
    
        $this->setLogAction("Found " . count($documents) . " documents to process.", __LINE__);
    
        foreach ($documents as $file) {
            try {
                $this->addCandidateFile($GenericCrmCandidateID, $file->ID);
            } catch (Exception $ex) {
                $this->setLogAction('Error uploading file ID: ' . $file->ID . ', ' . $ex->getMessage(), __LINE__);
                App_AM_Worker_Handoff::queue('file', $this->handoffID, $this->projectID, $this->personID, ['fileID' => $file->ID]);
            }
        }
    
        $this->setLogAction("Completed processing all documents.", __LINE__);
    }
    
    protected function addCandidateFile($GenericCrmCandidateID, $fileID)
    {
        $file = (new Generic_Model_AppHiveFiles())->getRawById($fileID);
    
        if (!$file) {
            $this->setLogAction("No file found for ID: $fileID", __LINE__);
            return false;
        }
    
        $content = $file->getContent();
        if (!$content) {
            $this->setLogAction("No content found for file ID: $fileID", __LINE__);
            return false;
        }
    
        $fileName = $file->FileNameOrg ?: "UnknownFileName";
        $this->setLogAction("Checking if file already exists: $fileName for candidate ID: $GenericCrmCandidateID");
    
        $documents = $this->GenericCrmClient->getCandidateDocuments($GenericCrmCandidateID);
    
        if (!empty($documents)) {
            foreach ($documents as $document) {
                if (isset($document['AttachmentName']) && $document['AttachmentName'] === $fileName) {
                    $this->setLogAction("File already exists in CRM: $fileName, skipping upload.");
                    return false;
                }
            }
        }
    
        $this->setLogAction("Uploading file: $fileName for candidate ID: $GenericCrmCandidateID");
    
        try {
            list($response_status, $response_body_raw) = $this->GenericCrmClient->addCandidateFile(
                $GenericCrmCandidateID,
                $content,
                $fileName
            );
    
            $this->setLogAction("API response status: {$response_status}");
            $this->setLogAction("Raw API response body: " . print_r($response_body_raw, true));
    
            $response_body = is_array($response_body_raw) ? $response_body_raw : json_decode($response_body_raw, true);
    
            if ($response_status != 200 && $response_status != 201) {
                $this->setLogAction("Error uploading file: " . print_r($response_body, true), __LINE__);
                return false;
            }
    
            $this->setLogAction("File uploaded successfully: $fileName");
    
            $this->setLogAction("Retrieving updated document list for candidate ID: {$GenericCrmCandidateID}");
            $documents = $this->GenericCrmClient->getCandidateDocuments($GenericCrmCandidateID);
    
            if (empty($documents)) {
                $this->setLogAction("No documents found for candidate ID: {$GenericCrmCandidateID} after upload.", __LINE__);
                return false;
            }
    
            $matchingDocument = null;
            foreach ($documents as $document) {
                if (isset($document['AttachmentName']) && $document['AttachmentName'] === $fileName) {
                    $matchingDocument = $document;
                    break;
                }
            }
    
            if (!$matchingDocument) {
                $this->setLogAction("No matching document found for file: $fileName", __LINE__);
                return false;
            }
    
            $documentId = $matchingDocument['ItemId'];
            $this->setLogAction("Marking document ID: {$documentId} as default CV...");
    
            try {
                $this->GenericCrmClient->setDefaultCV($GenericCrmCandidateID, $documentId);
                $this->setLogAction("Document ID: {$documentId} marked as default CV successfully.");
            } catch (Exception $e) {
                $this->setLogAction("Failed to mark document ID: {$documentId} as default CV. Error: " . $e->getMessage(), __LINE__);
            }
    
            return true;
        } catch (Exception $e) {
            $this->setLogAction("Exception during file upload for file ID: $fileID - " . $e->getMessage(), __LINE__);
            return false;
        }
    }

    public function fileExport($fileInfo)
    {
        $this->setLogAction('Start method: ' . __METHOD__);
    
        try {
            $this->setLogAction('Checking if candidate is synced...');
            $remoteID = isset($fileInfo['candidateId'])
                ? $fileInfo['candidateId']
                : (new Generic_Model_HandoffCandidateSync())->getSyncedCrmID($this->handoffID, $this->personID);
    
            if (!$remoteID) {
                $this->setLogAction('No remote ID found for candidate.', __LINE__);
                return false;
            }
    
            $this->setLogAction("Candidate ID is: $remoteID");
    
            $this->setLogAction('Adding files...');
            $this->addCandidateFiles($remoteID);
            $this->setLogAction('Files added successfully.');
    
            return true;
        } catch (Exception $e) {
            $this->setLogAction('Error in fileExport: ' . $e->getMessage(), __LINE__);
            return false;
        }
    }

    protected function addCandidateNotesToString($remoteID)
    {
        $this->logger->log('Exporting notes has been put on hold until possible reconsideration.');
        return true;
        $noteIDsArray = (new Generic_Model_NotesFast())->getRawByAppHive($this->personID);
        
        $this->internalComments = [];
        
        foreach ($noteIDsArray as $noteID) {
            try {
                $note = $this->addCandidateNote($noteID['id'], $remoteID);
                if ($note !== false) {
                    //log
                    $this->logger->log('Adding to internalComments string:'.$note);
                    $this->internalComments[] = $note;
                }
            } catch (Exception $ex) {
                // in case of exception try to add note once again in a separate worker
               // App_AM_Worker_Handoff::queue('note', $this->handoffID, $this->projectID, $this->personID, ['noteID' => $noteID]);
            }
        }
    
        // Reverse order before joining into string
        return implode("\n", array_reverse($this->internalComments));
    }

        
    public function noteExport($noteInfo)
    {
        
        $this->logger->log('Start method: noteExport');
        $this->logger->log('Note data: ' . print_r($noteInfo, true));
        $this->logger->log('Exporting notes has been put on hold until possible reconsideration.');
        return true;
    
        $remoteID = (new Generic_Model_HandoffCandidateSync())->getSyncedCrmID($this->handoffID, $this->personID);
    
        if (!$remoteID) {
            $this->logger->log('No remote ID found for candidate, cannot update notes.', __LINE__);
            return false;
        }
    
        $internalCommentsToSend = $this->addCandidateNotesToString($remoteID);
    
        if (empty($internalCommentsToSend)) {
            $this->logger->log('No notes to send, skipping.');
            return false;
        }
    
        $cleanComments = strip_tags($internalCommentsToSend);
    
        $this->logger->log('Final Internal Comments: ' . $cleanComments);
    
        $this->GenericCrmClient->updateCandidateField($remoteID, 'InternalComments', $cleanComments);
        $this->logger->log('Notes updated in GenericCrm for candidate ID: ' . $remoteID);
    
        return true;
    }

        
    protected function addCandidateNote($noteID, $ivCandidateID)
    {
        $this->logger->log('Note Id:'.$noteID);
        $note = (new Generic_Model_NotesFast())->getNoteById($noteID, $this->personID);
        
        if (!$note) {
            $this->logger->log('Note not found, skipping', __LINE__);
            return false;
        }
    
        if ($note->type == 'plugin_crm') {
            $this->logger->log('Note type is plugin_crm, skipping', __LINE__);
            return false;
        }
    
        $tags = explode(',', $note->tags);
        // log tags
        $this->logger->log('Note tags: ' . print_r($tags, true));
    
        if (in_array('ats', $tags)) {        
            $noteBody = $this->getNoteBodyForATS($note, ['include' => [12]]);
            if (!$noteBody) {
                $this->logger->log('Note has tag ats, skipping', __LINE__);
                return false;
            }
        } else {
            $noteBody = Zend_Controller_Action_HelperBroker::getStaticHelper('Notes')->getNotesBody($note);
        }
    
        $noteDate = $note->note_createDate;
        $noteOwnerId = $note->note_owner_ClientID;
        $noteOwnerFirstName = $note->firstname;
        $noteOwnerLastName = $note->lastname;
        
        
        $formattedNote = "Note created on: {$noteDate}\n";
        $formattedNote .= "Owner: {$noteOwnerFirstName} {$noteOwnerLastName} (ID: {$noteOwnerId})\n";
        $formattedNote .= "{$noteBody}";
        $formattedNote .= "\n-------------------";
    
        $this->logger->log('Returning note Body: '.$formattedNote);
    
        return $formattedNote;
    }
        
    public function vacancyNoteExport($custom)
    {
        $this->setLogAction('Start method: ' . __METHOD__);
        return true;
    }

    public function getDebugInfo(array $additional_info = [])
    {
        return array_merge([
            'handoffID' => $this->handoffID,
            'projectID' => $this->projectID,
            'personID' => $this->personID
        ], $additional_info);
    }
    
    public function getGenericCrmClient() {
        return $this->GenericCrmClient;
    }
    
    public function setLogAction($message = '')
    {
        //echo $message . PHP_EOL;
    
        if ($this->logger) {
            $this->logger->log($message);
        } else {
            echo "Logger is not initialized!" . PHP_EOL;
        }
    }

    public function getLogger() {
        return $this->logger;
    }
}
