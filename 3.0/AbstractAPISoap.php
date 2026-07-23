<?php

/** 
 * @created 03/10/17
 * @lastUpdated 11/02/25
 * @version 3.0.0
 *  
 * Generic class for a client API. Handle the SOAP connection to NETIM's API, and many operation described here: http://support.netim.com/en/wiki/Category:Functions
 * 
 * How to use the class?
 * =====================
 * 
 * Beforehand you need to include this script into your php script:
 * ```php
 * 		include_once('$PATH/APISoap.php');
 * 		//(replace $PATH by the path of the file)
 * ```
 * 
 * Then you can instantiate a APISoap object:
 * ```php
 * 		$name = 'AA001_user';
 * 		$key = 'your_api_key';
 * 		$client = new APISoap($name, $key);
 * ```
 * 
 * You can also create a conf.xml file next to the APISoap.php class with the login credentials to connect to the API with no parameters
 * 	
 * Now that you have your object, you can issue commands to the API.
 * 
 * Say you want to see the information you gave when creating your contact, and your contact ID is 'GK521'.
 * The code is:
 * ```php
 * 		$result = $client->contactInfo('GK521');
 * ```
 * 
 * (SIDENOTE: you may have noticed that you didn't need to explicitely open nor close a connexion with the API, the client handle it for you.
 * It is good for shortlived scripts. The connection is automatically stopped when the script ends. However if you open multiple connections
 * in a long running script, you should close each connection when you don't need them anymore to avoid having too many connections opened).
 * 
 * To know if there is an error we provide you an exception type NetimAPIException
 * 
 * How to issue many commands more effectively
 * ===========================================
 * 
 * Previously we saw how to issue a simple command. Now we will look into issueing many commands sequentially.
 * 
 * Let's take an example, we want to create 2 contacts, look up info on 2 domains and look up infos on the contacts previously created
 * We could do it simply:
 * ```php
 * 		//creating contacts
 * 		try
 * 		{
 * 			$result1 = $client->contactCreate(...); //skipping needed parameters here for the sake of the example brevity
 * 			$result2 = $client->contactCreate(...);
 * 			
 * 			//asking for domain informations
 * 			$result3 = $client->domainInfo('myDomain.fr');
 * 			$result4 = $client->domainInfo('myDomain.com');
 * 		}
 * 		catch (NetimAPIException $exception)
 * 		{
 * 			//do something about the error
 * 		}
 * 		
 * 		//asking for contact informations
 * 		$result5 = $client->contactInfo($result1));
 * 		$result6 = $client->contactInfo($result2));
 * ```
 * 	
 * The connection is automatically closed when the script ends. However we recommend you to close the connection yourself when you won't use it
 * anymore like so : 
 * ```php
 * 		$client->sessionClose();
 * ```
 * The reason is that PHP calls the destructor only if it's running out of memory or when the script ends. If your script is running in a cron for
 * example, and it instanciates many APISoap objects without closing them, you may reach the limit of sessions you're allowed to open.
 */

namespace Netim {

    use SoapFault;
	use SoapClient;
    use stdClass;

	ini_set("soap.wsdl_cache_enabled", "0");

	abstract class AbstractAPISoap
	{

		private $_connected;
		private $_sessionID;
		private $_clientSOAP;

		private $_name;
		private $_key;
		private $_apiURL;
        private $_preferences;

		private $_lastRequestParams;
		private $_lastRequestFunction;
		private $_lastResponse;
		private $_lastError;

		/**
		 * Constructor for class AbstractAPISoap
		 *
		 * @param	string	$name			API user name
		 * @param	string	$key			Value of the API key
		 * @param	string	$apiURL			the URL of the API
		 * @param	array	$preferences	the preferences of the API session
		 *	 
		 * @throws	Error	if $name, $key or $apiURL are not string or are empty
		 * 
		 * @link semantic versionning http://semver.org/ by Tom Preston-Werner 
		 */
		protected function __construct(string $name, string $key, string $apiURL, array $preferences)
		{
			register_shutdown_function([&$this, "__destruct"]);
			// Init variables
			$this->_connected = false;
			$this->_sessionID = null;

			$this->_name = $name;
			$this->_key = $key;
			$this->_apiURL = $apiURL;

			$this->_preferences = $preferences;
			if (empty($this->_preferences['lang'])) {
				$this->_preferences['lang'] = 'EN';
			}

			// Init Client Soap object
			try {
				$headers = @get_headers($apiURL);
				if ($headers !== false) {
					// get_headers() follows redirects: check the final status line, whatever the HTTP version
					$statusLine = '';
					$statusCode = '';
					foreach ($headers as $headerLine) {
						if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
							$statusLine = $headerLine;
							$statusCode = $matches[1];
						}
					}

					if ($statusLine !== '' && $statusCode[0] !== '2') {
						throw new SoapFault($statusLine, $statusLine);
					}
				}
				$this->_clientSOAP = new SoapClient($this->_apiURL, array('trace' => 1, 'exceptions' => 1, 'connection_timeout' => 5));

			} catch (SoapFault $fault) {
				$file = __DIR__ . "/../lib/Core.php";
				if (file_exists($file)) {
					include_once $file;
				}
				throw new NetimAPIexception($fault->getMessage());
			}
		}

		public function __destruct()
		{
			if ($this->_connected && isset($this->_sessionID))
            	$this->sessionClose();

		}

		public function getSOAPClient()
		{
			return $this->_clientSOAP;
		}
		public function getApiURL()
		{
			return $this->_apiURL;
		}
		public function getLastRequestParams()
		{
			return $this->_lastRequestParams;
		}
		public function getLastRequestFunction()
		{
			return $this->_lastRequestFunction;
		}
		public function getLastResponse()
		{
			return $this->_lastResponse;
		}
		public function getLastError()
		{
			return $this->_lastError;
		}

		public function getName()
		{
			return $this->_name;
		}
		public function getKey()
		{
			return $this->_key;
		}
		public function getPreferences($key = null)
		{
			return (isset($key)) ? $this->_preferences[$key] : $this->_preferences;
		}

		public function getSessionID()
		{
			return $this->_sessionID;
		}

		# ---------------------------------------------------
		# PRIVATE UTILITIES
		# ---------------------------------------------------
		
		/**
		 * Launches a function of the API, abstracting the connect/disconnect part to one place
		 *
		 * Example 1: API command returning a StructOperationResponse
		 *
		 *	$params[] = $idContactToDelete;
		 *	return $this->_launchCommand('contactDelete', $params);
		 *
		 * Example 2: API command that takes many args
		 *
		 *	$params[] = $host;
		 *	$params[] = $ipv4;
		 *	$params[] = $ipv6;
		 *	return $this->_launchCommand('hostCreate', $params);
		 *
		 * WARNING: as in the example 2 just above, the second parameter you give to _launchCommand must be an indexed array with parameters in the right order relative to the parameter the API function takes. 
		 *          Example: the function hostCreate of the API takes exactly 3 parameters in that exact order:  $host, $ipv4, $ipv6, see example 2.
		 *
		 * @param string $fn name of a function in the API
		 * @param array $params the parameters of $fn in an indexed array, must be in the right order.
		 *
		 * @throws NetimAPIException
		 *
		 * @return mixed the result of the call of $fn with parameters $params
		 *
		 * @see call_user_func_array https://stackoverflow.com/questions/1422652/how-to-pass-variable-number-of-arguments-to-a-php-function
		 *                           http://php.net/manual/fr/function.call-user-func-array.php
		 * @see array_unshift http://php.net/manual/en/function.array-unshift.php
		 */
		protected function _launchCommand($fn, $params=array())
		{
			$this->_lastRequestFunction = $fn;
			$this->_lastRequestParams = $params;
			$this->_lastResponse = "";
			$this->_lastError = "";

			try {
				//login		
				if (!$this->_connected)
                {
                    if ($fn == "sessionClose") //If already disconnected, just return.
                        return;
                    else if ($fn != "sessionOpen" && $fn != "login") // If not connected and running sessionOpen, don't fall in an endless loop.
                        $this->sessionOpen();
                }
                else if($this->_connected && $fn == "sessionOpen")
                    return;
                

				//Call the Soap function
                if($fn != "sessionOpen" && $fn != "login")
				    array_unshift($params, $this->_sessionID);                
				$res = call_user_func_array(array($this->_clientSOAP, $fn), $params);
				$this->_lastResponse = $res;

			} catch (NetimAPIException $exception) {
				$this->_lastError = $exception->getMessage();
				throw new NetimAPIexception($exception->getMessage(), $exception->getCode(), $exception);

			} catch (SoapFault $fault) {
				$this->_lastError = $fault->getMessage();
				throw new NetimAPIexception($fault->getMessage(), $fault->getCode(), $fault);
			}

            if ($fn == "sessionClose")
            {
                $this->_connected = false;
            }
            else if($fn == "sessionOpen" || $fn == "login")
            {
                $this->_sessionID = $res;
                $this->_connected = true;
            }

			return $res;
		}

		# -------------------------------------------------
		# MISC
		# -------------------------------------------------	
		/**
		 * Returns a welcome message
		 *
		 * Example
		 *	```php
		 *	try
		 *	{
		 *		$res = $client->hello();
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @return string a welcome message
		 * 
		 * @throws NetimAPIException
		 *
		 * @see hello API http://support.netim.com/en/wiki/Hello
		 */
		public function hello():string
		{
			return $this->_launchCommand('hello');
		}
        
		/**
		 * Returns the list of parameters reseller account
		 *
		 *
		 * @return StructAccountInfo A structure of StructAccountInfo containing the information
		 * 
		 * @throws NetimAPIException
		 */
        public function accountInfo():stdClass
		{
			return $this->_launchCommand('accountInfo');
		}

		# -------------------------------------------------
		# SESSION
		# -------------------------------------------------	 

		/**
		 * Open the SOAP session
		 *
		 * @throws NetimAPIException
		 */
		public function sessionOpen(): void
		{
			$params = array(
				$this->getName(),
				$this->getKey(),
				$this->getPreferences(),
			);

			$this->_launchCommand('sessionOpen', $params);
		}

		/**
		 * Close the SOAP session
		 * 
		 * @throws NetimAPIException
		 */
		public function sessionClose():void
		{
			$this->_launchCommand('sessionClose');
		}
        
		/**
		 * Return the information of the current session. 
		 *
		 * @throws NetimAPIException
		 * 
		 * @return StructSessionInfo A structure StructSessionInfo
		 *
		 * @see sessionInfo API https://support.netim.com/en/wiki/SessionInfo
		 */
        public function sessionInfo():stdClass
        {
			return $this->_launchCommand('sessionInfo');
        }
        
		/**
		 * Returns all active sessions linked to the reseller account. 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructSessionInfo[] An array of StructSessionInfo
		 *
		 * @see queryAllSessions API https://support.netim.com/en/wiki/QueryAllSessions
		 */
        public function queryAllSessions():array
		{
			return $this->_launchCommand('queryAllSessions');
		}
        
        /**
		 * Updates the settings of the current session. 
		 *
		 * @param	array	$preferences	the preferences of the API session
		 * 
		 * @throws	NetimAPIException
		 *
		 * @see sessionSetPreference API https://support.netim.com/en/wiki/SessionSetPreference
		 */
		public function sessionSetPreference(array $preferences): void
		{
			$params = array(
				$preferences
			);
			$this->_launchCommand('sessionSetPreference', $params);
		}
        
		# -------------------------------------------------
		# OPERATIONS
		# -------------------------------------------------	
		public function opeInfo(int $operationID)
		{
			$params[] = $operationID;
			return $this->_launchCommand('opeInfo', $params);
		}

        /**
		 * Cancel a pending operation
		 * @warning Depending on the current status of the operation, the cancellation might not be possible
		 * 
		 * @param int $operationID Tracking ID of the operation
		 * 
		 * @throws NetimAPIException
		 *
		 * @see cancelOpe http://support.netim.com/en/wiki/CancelOpe
		 */
		//TODO voir si le fait que cancel ne retourne rien ne fait pas d'erreur
		public function cancelOpe(int $operationID):void
		{
			$params[] = $operationID;
			$this->_launchCommand('cancelOpe', $params);
		}
        
        /**
		 * Returns a list of operations matching the given filters
		 * 
		 * @param	array	$filters	Filters to apply to the list
		 * 
		 * @throws	NetimAPIException
		 * 
		 * @return	StructOpeList
		 * 
		 */
		public function opeList(array $filters = []):array
		{
			$params = array(
				$filters
			);

			return $this->_launchCommand('opeList', $params);
		}

		/**
		 * Requests a special operation
		 *
		 * @param	string	$action		Operation to perform
		 * @param	array	$params		Parameters of the operation
		 * @param	string	$reseller	Reseller account ID (if applicable)
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function opeSpecial(string $action, array $params, string $reseller = ''):stdClass
		{
			$commandParams = [
				$action,
				$params,
				$reseller,
			];

			return $this->_launchCommand('opeSpecial', $commandParams);
		}

		/**
		 * Returns a list of contacts matching the given filters
		 * 
		 * @param	string	$filters	Filters to apply to the list
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return StructContactList[] the list of contacts associated to the account
		 * 
		 * @see contactList API https://support.netim.com/en/wiki/QueryContactList
		 * 
		 */
		public function contactList(array $filters = []): array
		{
			$params = array(
				$filters
			);

			return $this->_launchCommand('contactList', $params);
		}
        
        # -------------------------------------------------
		# HOST
		# -------------------------------------------------
		/**
		 * Creates a new host at the registry
		 *
		 * Example
		 *	```php
		 *	$host = 'ns1.mydomain.com';
		 *	$ipv4 = array('10.11.12.13');
		 *	$ipv6 = array();
		 *	$res = null;
		 *	try
		 *	{
		 *		$res =  $client->hostCreate($host, $ipv4, $ipv6);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $host hostname
		 * @param array $ipv4 Must contain ipv4 adresses as strings
		 * @param array $ipv6 Must contain ipv6 adresses as strings
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see hostCreate API http://support.netim.com/en/wiki/HostCreate
		 */
		public function hostCreate(string $host, array $ipv4, array $ipv6):stdClass
		{
			$params[] = $host;
			$params[] = $ipv4;
			$params[] = $ipv6;
			return $this->_launchCommand('hostCreate', $params);
		}
		
		/**
		 * Returns all informations about a host
		 *
		 * @param	mixed	$host	hostname to be queried
		 * 
		 * @throws	NetimAPIException
		 * 
		 * @return	StructHostInfo
		 */
		public function hostInfo(string $host): stdClass
		{
			$params = array(
				$host
			);
			return $this->_launchCommand('hostInfo', $params);
		}

		/**
		 * Deletes an Host at the registry 
		 *
		 * Example
		 *	```php
		 *	$host = 'ns1.mydomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->hostDelete($host);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $host hostname to be deleted
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see hostDelete API http://support.netim.com/en/wiki/HostDelete
		 */
		public function hostDelete($host):stdClass
		{
			$params[] = $host;
			return $this->_launchCommand('hostDelete', $params);
		}
        
        /**
		 * Updates a host at the registry 
		 *
		 * Example
		 *	```php
		 *	$host = 'ns1.myDomain.com';
		 *	$ipv4 = array('10.12.13.11');
		 *	$ipv6 = array();
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->hostUpdate($host, $ipv4, $ipv6);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $host string hostname
		 * @param array $ipv4 Must contain ipv4 adresses as strings
		 * @param array $ipv6 Must contain ipv6 adresses as strings
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see hostUpdate API http://support.netim.com/en/wiki/HostUpdate
		 */
		public function hostUpdate(string $host, array $ipv4, array $ipv6):stdClass
		{
			$params[] = $host;
			$params[] = $ipv4;
			$params[] = $ipv6;
			return $this->_launchCommand('hostUpdate', $params);
		}
        
        /**
		 * @param	array	$filters	Filters to apply to the list
		 * 
		 * @throws	NetimAPIException
		 *
		 * @return	array	An array of StructHostList
		 *
		 * @see	hostList API http://support.netim.com/en/wiki/hostList
		 */
		public function hostList(array $filters = []):array
		{
			$params = array(
				$filters
			);

			return $this->_launchCommand('hostList', $params);
		}
        
		# -------------------------------------------------
		# CONTACT
		# -------------------------------------------------	        

		/**
		 * Creates a contact
		 *
		 * Example1: non-owner
		 *	```php
		 *	//we create a contact as a non-owner 
		 *	$id = null;
		 *	try
		 *	{
		 *		$contact = array(
		 *	 		'firstName'=> 'barack',
		 *			'lastName' => 'obama',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1600 Pennsylvania Ave NW',
		 *			'address2' => '',
		 *			'zipCode'  => '20500',
		 *			'area'	   => 'DC',
		 *			'city'	   => 'Washington',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024561111',
		 *			'fax'	   => '',
		 *			'email'    => 'barack.obama@gov.us',
		 *			'language' => 'EN',
		 *			'isOwner'  => 0
		 *		);
		 *		$id = $client->contactCreate($contact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *
		 *	//continue processing
		 *	```
		 *
		 * Example2: owner
		 *	```php	
		 *	$id = null;
		 *	try
		 *	{
		 *	 	$contact = array(
		 *	 		'firstName'=> 'bill',
		 *			'lastName' => 'gates',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1 hollywood bvd',
		 *			'address2' => '',
		 *			'zipCode'  => '18022',
		 *			'area'	   => 'LA',
		 *			'city'	   => 'Los Angeles',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024531111',
		 *			'fax'	   => '',
		 *			'email'    => 'bill.gates@microsoft.com',
		 *			'language' => 'EN',
		 *			'isOwner'  => 1
		 *		);
		 *		$id = $client->contactCreate($contact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *
		 *	//continue processing
		 *	```
		 * @param StructContact $contact the contact to create
		 *
		 * @throws NetimAPIException
		 *
		 * @return string the ID of the contact
		 *
		 * @see StructContact http://support.netim.com/en/wiki/StructContact
		 */
		public function contactCreate(array $contact): string
		{
			$params[] = $contact;
			return $this->_launchCommand('contactCreate', $params);
		}

		/**
		 * Returns all informations about a contact object
		 *
		 * Example:
		 *	```php
		 *	$idContact = 'BJ007';
		 *	$res = null;
		 *	try 
		 *	{
		 *		$res = $client->contactInfo($idContact);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *	$contactInfo = $res;
		 *	//continue processing
		 *	```
		 * @param string $idContact ID of the contact to be queried
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructContactReturn information on the contact
		 *
		 * @see contactInfo API http://support.netim.com/en/wiki/ContactInfo
		 * @see StructContactReturn API http://support.netim.com/en/wiki/StructContactReturn
		 */
		public function contactInfo(string $idContact): stdClass
		{
			$params = array(
				$idContact
			);
			return $this->_launchCommand('contactInfo', $params);
		}

		/**
		 * Edit contact details
		 *
		 * Example: 
		 *	```php
		 *	//we update a contact as a non-owner 
		 *	$res = null;
		 *	try {
		 *	 	$contact = array(
		 *	 		'firstName'=> 'donald',
		 *			'lastName' => 'trump',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1600 Pennsylvania Ave NW',
		 *			'address2' => '',
		 *			'zipCode'  => '20500',
		 *			'area'	   => 'DC',
		 *			'city'	   => 'Washington',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024561111',
		 *			'fax'	   => '',
		 *			'email'    => 'donald.trump@gov.us',
		 *			'language' => 'EN',
		 *			'isOwner'  => 0
		 *		);
		 *		$res = $client->contactUpdate($idContact, $contact);   
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 * ```
		 *
		 * @param string $idContact the ID of the contact to be updated
		 * @param StructContact $contact the contact object containing the new values
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactUpdate API http://support.netim.com/en/wiki/ContactUpdate
		 */
		public function contactUpdate(string $idContact, array $datas): stdClass
		{
			$params[] = $idContact;
			$params[] = $datas;
			return $this->_launchCommand('contactUpdate', $params);
		}

		/**
		 * Edit contact details (for owner only) 
		 *
		 * Example
		 *	```php
		 *	//we update a owner contact
		 *	$res = null;
		 *	try
		 *	{
		 *			$contact = array(
		 *	 		'firstName'=> 'elon',
		 *			'lastName' => 'musk',
		 *			'bodyForm' => 'IND',
		 *			'bodyName' => '',
		 *			'address1' => '1 hollywood bvd',
		 *			'address2' => '',
		 *			'zipCode'  => '18022',
		 *			'area'	   => 'LA',
		 *			'city'	   => 'Los Angeles',
		 *			'country'  => 'US',
		 *			'phone'	   => '2024531111',
		 *			'fax'	   => '',
		 *			'email'    => 'elon.musk@tesla.com',
		 *			'language' => 'EN',
		 *			'isOwner'  => 1
		 *		);
		 *		$res = $client->contactOwnerUpdate($idContact, $contact); 
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $idContact the ID of the contact to be updated
		 * @param StructOwnerContact $contact the contact object containing the new values
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactOwnerUpdate API http://support.netim.com/en/wiki/ContactOwnerUpdate
		 * @see StructOwnerContact http://support.netim.com/en/wiki/StructOwnerContact
		 * 
		 */
		public function contactOwnerUpdate(string $idContact, array $datas): stdClass
		{
			$params[] = $idContact;
			$params[] = $datas;
			return $this->_launchCommand('contactOwnerUpdate', $params);
		}

		/**
		 * Deletes a contact object 
		 *
		 * Example1:
		 *	```php
		 *	$contactID = 'BJ007';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->contactDelete($contactID);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $idContact ID of the contact to be deleted
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see contactDelete API http://support.netim.com/en/wiki/ContactDelete
		 * @see StructOperationResponse API http://support.netim.com/en/wiki/StructOperationResponse
		 */
		public function contactDelete(string $idContact): stdClass
		{
			$params[] = $idContact;
			return $this->_launchCommand('contactDelete', $params);
		}

		/**
		 * Sets an additional setting of a contact
		 *
		 * @param	string	$idContact	ID of the contact
		 * @param	string	$name		Name of the setting
		 * @param	string	$value		Value of the setting
		 * @param	string	$domain		Domain name (if the setting is domain-specific)
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function contactSetAdditional(string $idContact, string $name, string $value, string $domain = ''):stdClass
		{
			$params = [
				$idContact,
				$name,
				$value,
				$domain,
			];

			return $this->_launchCommand('contactSetAdditional', $params);
		}

        # -------------------------------------------------
		# DOMAIN
		# -------------------------------------------------

		/**
		 * Checks if domain names are available for registration   
		 *
		 *  
		 * Example: Check one domain name
		 *	```php
		 *	$domain = "myDomain.com";
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainCheck($domain);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *	$domainCheckResponse = $res[0];
		 *	//continue processing
		 *	```
		 * @param string $domain Domain names to be checked 
		 * You can provide several domain names separated with semicolons. 
		 * Caution : 
		 *	- you can't mix different extensions during the same call 
		 *	- all the extensions don't accept a multiple checkDomain. See HasMultipleCheck in Category:Tld
		 *
		 * @throws NetimAPIException
		 *
		 * @return array An array of StructDomainCheckResponse
		 * 
		 * @see StructDomainCheckResponse http://support.netim.com/en/wiki/StructDomainCheckResponse
		 * @see DomainCheck API http://support.netim.com/en/wiki/DomainCheck
		 */
		public function domainCheck(string $domain):array
		{
			$params[] = $domain;
			return $this->_launchCommand('domainCheck', $params);
		}
        
        /**
		 * Requests a new domain registration 
		 *
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$idOwner = 'BJ008';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$duration = 1;
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainCreate($domain, $idOwner, $idAdmin, $idTech, $idBilling, $nameservers, $duration);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $domain the name of the domain to create
		 * @param string $idOwner the id of the owner for the new domain
		 * @param string $idAdmin the id of the admin for the new domain
		 * @param string $idTech the id of the tech for the new domain
		 * @param string $idBilling the id of the billing for the new domain
		 *                          To get an ID, you can call contactCreate() with the appropriate information
		 * @param array $nameservers the nameservers for the domain
		 * @param int $duration how long the domain will be created
		 * @param array $options additional options
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainCreate API http://support.netim.com/en/wiki/DomainCreate 
		 */
		public function domainCreate(string $domain, string $idOwner, string $idAdmin, string $idTech, string $idBilling, array $nameservers, int $duration, array $options = null):stdClass
		{
			$params[] = strtolower($domain);

			$params[] = $idOwner;
			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;
			$params[] = $nameservers;
			$params[] = $duration;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainCreate', $params);
		}
        
        /**
		 * Returns all informations about a domain name 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainInfo($domain);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something about the error
		 *	}
		 *
		 *	$domainInfo = $res;
		 *	//continue processing
		 *	```
		 * @param string $domain name of the domain
		 *
		 * @throws NetimAPIException
		 * 
		 * @return StructDomainInfo information about the domain
		 *
		 * @see domainInfo API http://support.netim.com/en/wiki/DomainInfo
		 */
		public function domainInfo(string $domain):stdClass
		{
			$params[] = $domain;
			return $this->_launchCommand('domainInfo', $params);
		}
        
        /**
		 * Requests a new domain registration during a launch phase
		 *
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$idOwner = 'BJ008';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$duration = 1;
		 *	$phase = 'GA';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainCreateLP($domain, $idOwner, $idAdmin, $idTech, $idBilling, $nameservers, $duration, $phase);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $domain the name of the domain to create
		 * @param string $idOwner the id of the owner for the new domain
		 * @param string $idAdmin the id of the admin for the new domain
		 * @param string $idTech the id of the tech for the new domain
		 * @param string $idBilling the id of the billing for the new domain
		 *                          To get an ID, you can call contactCreate() with the appropriate information
		 * @param array $nameservers the nameservers for the domain
		 * @param int $duration how long the domain will be created
		 * @param string $phase the id of the launch phase
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainCreateLP API http://support.netim.com/en/wiki/DomainCreateLP 
		 */
		public function domainCreateLP(string $domain, string $idOwner, string $idAdmin, string $idTech, string $idBilling, array $nameservers, int $duration, string $phase):stdClass
		{
			$params[] = strtolower($domain);

			$params[] = $idOwner;
			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;
			$params[] = $nameservers;
			$params[] = $duration;

			$params[] = $phase;

			return $this->_launchCommand('domainCreateLP', $params);
		}
        
        /**
		 * Deletes immediately a domain name 
		 * 
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainDelete($domain);
		 *		//equivalent to $res = $client->domainDelete($domain, 'NOW');
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $domain the name of the domain to delete
		 * @param string $typeDeletion OPTIONAL if the deletion is to be done now or not. Only supported value as of 2.0 is 'NOW'.
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainDelete API http://support.netim.com/en/wiki/DomainDelete
		 */
		public function domainDelete(string $domain, string $typeDelete = 'NOW'):stdClass
		{
			$params[] = $domain;
			$params[] = strtoupper($typeDelete);

			return $this->_launchCommand('domainDelete', $params);
		}
        
        /**
		 * Requests the transfer of a domain name to Netim 
		 *
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
		 *	$idOwner = 'BJ008';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainTransferIn($domain, $authID, $idOwner, $idAdmin, $idTech, $idBilling, $nameservers);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $domain name of the domain to transfer
		 * @param string $authID authorisation code / EPP code (if applicable)
		 * @param string $idOwner a valid idOwner. Can also be #AUTO#
		 * @param string $idAdmin a valid idAdmin
		 * @param string $idTech a valid idTech
		 * @param string $idBilling a valid idBilling
		 * @param array $nameservers the nameservers for the domain
		 * @param array $options additional options
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainTransferIn API http://support.netim.com/en/wiki/DomainTransferIn
		 */
		public function domainTransferIn(string $domain, string $authID, string $idOwner, string $idAdmin, string $idTech, string $idBilling, array $nameservers, array $options = null):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $authID;

			$params[] = $idOwner;
			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;
			$params[] = $nameservers;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainTransferIn', $params);
		}
        
        /**
		 * Requests the transfer (with change of domain holder) of a domain name to Netim 
		 *
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
		 *	$idOwner = 'BJ008';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainTransferTrade($domain, $authID, $idOwner, $idAdmin, $idTech, $idBilling, $nameservers);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain name of the domain to transfer
		 * @param string $authID authorisation code / EPP code (if applicable)
		 * @param string $idOwner a valid idOwner.
		 * @param string $idAdmin a valid idAdmin
		 * @param string $idTech a valid idTech
		 * @param string $idBilling a valid idBilling
		 * @param array $nameservers the nameservers for the domain
		 * @param array $options additional options
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainTransferTrade API http://support.netim.com/en/wiki/domainTransferTrade
		 */
		public function domainTransferTrade(string $domain, string $authID, string $idOwner, string $idAdmin, string $idTech, string $idBilling, array $nameservers, array $options = null):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $authID;

			$params[] = $idOwner;
			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;
			$params[] = $nameservers;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainTransferTrade', $params);
		}
        
        /**
		 * Requests the internal transfer of a domain name from one Netim account to another. 
		 *
		 * Example:
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$authID = 'qlskjdlqkxlxkjlqksjdlkj';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainInternalTransfer($domain, $authID, $idAdmin, $idTech, $idBilling, $nameservers);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $domain name of the domain to transfer
		 * @param string $authID authorisation code / EPP code (if applicable)
		 * @param string $idAdmin a valid idAdmin
		 * @param string $idTech a valid idTech
		 * @param string $idBilling a valid idBilling
		 * @param array $nameservers the nameservers for the domain
		 * @param array $options additional options:
		 *                       - type (string): 'push' or 'pull' (default 'pull')
		 *                       - recipient (string): ID of the recipient account (mandatory when type is 'push')
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainInternalTransfer API http://support.netim.com/en/wiki/domainInternalTransfer
		 */
		public function domainInternalTransfer(string $domain, string $authID, string $idAdmin, string $idTech, string $idBilling, array $nameservers, array $options = null):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $authID;

			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;
			$params[] = $nameservers;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainInternalTransfer', $params);
		}
        
        /**
		 * Renew a domain name for a new subscription period 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com'
		 *	$duration = 1;
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainCreate($domain, $duration)
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain the name of the domain to renew
		 * @param int $duration the duration of the renewal expressed in year. Must be at least 1 and less than the maximum amount
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainRenew API  http://support.netim.com/en/wiki/DomainRenew
		 */
		public function domainRenew(string $domain, int $duration):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $duration;

			return $this->_launchCommand('domainRenew', $params);
		}
        
        /**
		 * Restores a domain name in quarantine / redemption status
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainRestore($domain);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * @param string $domain name of the domain
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainRestore API http://support.netim.com/en/wiki/DomainRestore
		 */
		public function domainRestore(string $domain):stdClass
		{
			$params[] = strtolower($domain);
			return $this->_launchCommand('domainRestore', $params);
		}
        
        /**
		 * Updates the settings of a domain name
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$codePref = 'registrar_lock': //possible values are 'whois_privacy', 'registrar_lock', 'auto_renew', 'tag' or 'note'
		 *	$value = 1; // 1 or 0
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainSetPreference($domain, $codePref, $value);
		 *		//equivalent to $res = $client->domainSetRegistrarLock($domain,$value); each codePref has a corresponding helping function
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $domain name of the domain
		 * @param string $codePref setting to be modified. Accepted value are 'whois_privacy', 'registrar_lock', 'auto_renew', 'tag' or 'note'
		 * @param string $value new value for the settings. 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainSetPreference API http://support.netim.com/en/wiki/DomainSetPreference
		 * @see domainSetWhoisPrivacy, domainSetRegistrarLock, domainSetAutoRenew, domainSetTag, domainSetNote
		 */
		public function domainSetPreference(string $domain, string $codePref, string $value):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $codePref;
			$params[] = $value;
			return $this->_launchCommand('domainSetPreference', $params);
		}
        
        /**
		 * Requests the transfer of the ownership to another party
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$idOwner = 'BJ008';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainTransferOwner($domain, $idOwner);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $domain name of the domain
		 * @param string $idOwner id of the new owner
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainTransferOwner API http://support.netim.com/en/wiki/DomainTransferOwner
		 * @see function createContact
		 */
		public function domainTransferOwner(string $domain, string $idOwner, array $options = null):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $idOwner;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainTransferOwner', $params);
		}
        
        /**
		 * Replaces the contacts of the domain (administrative, technical, billing) 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$idAdmin = 'BJ007';
		 *	$idTech = 'BJ007';
		 *	$idBilling = 'BJ007';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainChangeContact$domain, $idAdmin, $idTech, $idBilling);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain name of the domain
		 * @param string $idAdmin id of the admin contact
		 * @param string $idTech id of the tech contact
		 * @param string $idBilling id of the billing contact
		 * @param array $options additional options
		 * 
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainChangeContact API http://support.netim.com/en/wiki/DomainChangeContact
		 * @see function createContact
		 */
		public function domainChangeContact(string $domain, string $idAdmin, string $idTech, string $idBilling, array $options = null):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $idAdmin;
			$params[] = $idTech;
			$params[] = $idBilling;

			if (isset($options)) {
				$params[] = $options;
			}

			return $this->_launchCommand('domainChangeContact', $params);
		}
        
		/**
		 * Replaces the DNS servers of the domain (redelegation) 
		 * 
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$nameservers = [
		 *		1 => ['name' => 'ns1.netim.com']
		 * 		2 => ['name' => 'ns2.netim.com']
		 *  ];
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainChangeDNS($domain, $nameservers);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain name of the domain
		 * 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainChangeDNS API http://support.netim.com/en/wiki/DomainChangeDNS
		 */
		public function domainChangeDNS(string $domain, array $nameservers):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $nameservers;

			return $this->_launchCommand('domainChangeDNS', $params);
		}
        
        /**
		 * Allows to sign a domain name with DNSSEC if it uses NETIM DNS servers 
		 * 
		 * @param string $domain name of the domain
		 * @param int $value New signature value 0 : unsign
		 * 										1 : sign 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainSetDNSsec API http://support.netim.com/en/wiki/DomainSetDNSsec
		 */
		public function domainSetDNSsec(string $domain, int $value):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $value;
			return $this->_launchCommand('domainSetDNSsec', $params);
		}
        
        /**
		 * Returns the authorization code to transfer the domain name to another registrar or to another client account 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainAuthID($domain, 0);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param	string	$domain	name of the domain to get the AuthID
		 * @param	int		$sendTo	Send the authorization code to 0: Reseller, 1: Registrant, 2: None
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainAuthID API https://support.netim.com/en/docs/api-soap-3-0/domain-names/send-authid
		 */
		public function domainAuthID(string $domain, int $sendTo):stdClass
		{
			$params[] = $domain;
			$params[] = $sendTo;
			return $this->_launchCommand('domainAuthID', $params);
		}

		/**
		 * Add DS records to a domain if it does not use NETIM’s DNS servers.
		 *
		 * @param	string	$domain		Domain name
		 * @param	array	$data		Array of dsData or keyData to be added
		 *
		 * @return	StructOperationResponse		Operation result
		 *
		 * @see		https://support.netim.com/en/docs/api-soap-3-0/domain-names/ds-record-create
		 */
		public function domainDSRecordCreate(string $domain, array $data = [])
		{
			return $this->_launchCommand('domainDSRecordCreate', [$domain, $data]);
		}

		/**
		 * Remove DS records from a domain if it does not use NETIM’s DNS servers.
		 *
		 * @param	string	$domain		Domain name
		 * @param	array	$data		Array of dsData or keyData to be removed
		 *
		 * @return	StructOperationResponse		Operation result
		 *
		 * @see		https://support.netim.com/en/docs/api-soap-3-0/domain-names/ds-record-delete
		 */
		public function domainDSRecordDelete(string $domain, array $data = [])
		{
			return $this->_launchCommand('domainDSRecordDelete', [$domain, $data]);
		}

		/**
		 * Remove all DS records from a domain if it does not use NETIM’s DNS servers.
		 *
		 * @param	string	$domain		Domain name
		 *
		 * @return	StructOperationResponse		Operation result
		 *
		 * @see		https://support.netim.com/en/docs/api-soap-3-0/domain-names/ds-record-delete-all
		 */
		public function domainDSRecordDeleteAll(string $domain)
		{
			return $this->_launchCommand('domainDSRecordDeleteAll', [$domain]);
		}
		
		/**
		 * List DS records of a domain if it does not use NETIM’s DNS servers.
		 *
		 * @param	string	$domain		Domain name
		 *
		 * @return	StructOperationResponse		Operation result
		 *
		 * @see		https://support.netim.com/en/docs/api-soap-3-0/domain-names/ds-record-list
		 */
		public function domainDSRecordList(string $domain)
		{
			return $this->_launchCommand('domainDSRecordList', [$domain]);
		}

		/**
		 * Returns the list of all prices for each tld 
		 * 
		 * @param string $tld specific tld to get the price for
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return StructDomainPriceList[] 
		 * 
		 * @see domainPriceList API http://support.netim.com/en/wiki/DomainPriceList
		 */
		public function domainPriceList(string $tld = null):array
		{
			if ($tld) {
				$params[] = $tld;
				return $this->_launchCommand('domainPriceList', $params);

			} else {
				return $this->_launchCommand('domainPriceList');
			}
		}

		/**
		 * Allows to know a domain's price 
		 * 
		 * @param string $domain name of domain
		 * @param string $authID authorisation code (optional)
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return StructDomainGetPrices
		 * 
		 * @see domainGetPrices API https://support.netim.com/en/wiki/domainGetPrices
		 * 
		 */
		public function domainGetPrices(string $domain, string $authID = ""):stdClass
		{
			$params[] = $domain;
			$params[] = $authID;
			return $this->_launchCommand('domainGetPrices', $params);
		}

		/**
		 * Allows to know if there is a claim on the domain name 
		 * 
		 * @param string $domain name of domain
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return int 0: no claim ; 1: at least one claim
		 * 
		 */
		public function domainCheckClaims(string $domain):int
		{
			$params[] = $domain;
			return $this->_launchCommand('domainCheckClaims', $params);
		}

		/**
		 * Returns all domains linked to the reseller account.
		 * 
		 * @param array $filters The filter to apply onto the domain list
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return array The filter applies onto the domain name
		 *
		 * @see domainList API https://support.netim.com/en/wiki/domainList
		 *
		 */
		public function domainList(array $filters = []): array
		{
			$params = array(
				$filters
			);
			return $this->_launchCommand('domainList', $params);
		}

		/**
		 * Returns informations about a domain product
		 * 
		 * @param	string	$tld	Domain tld
		 * 
		 * @throws	NetimAPIException
		 * 
		 * @return	array
		 */
		public function domainProductInfo(string $tld)
		{
			$params[] = $tld;
			return $this->_launchCommand('domainProductInfo', $params);
		}

		/**
		 * Resets all DNS settings from a template 
		 * 
		 * @param string 	$domain Domain name
		 * @param int 		$templateDNS Template number
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return StructOperationResponse
		 * 
		 * @see domainZoneInit API https://support.netim.com/en/wiki/DomainZoneInit
		 * 
		 * 
		 */
		public function domainZoneInit(string $domain, int $templateDNS):stdClass
		{
			$params[] = $domain;
			$params[] = $templateDNS;
			return $this->_launchCommand('domainZoneInit', $params);
		}

		/**
		 * Creates a DNS record into the domain zonefile
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com'
		 *	$subdomain = 'www';
		 *	$type = 'A';
		 *	$value = '192.168.0.1';
		 *	$options = array('service' => '', 'protocol' => '', 'ttl' => '3600', 'priority' => '', 'weight' => '', 'port' => '');
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainZoneCreate($domain, $subdomain, $type, $value, $options);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $domain name of the domain
		 * @param string $subdomain subdomain
		 * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
		 * @param string $value value of the new DNS record
		 * @param array $options StructZoneParam : settings of the new DNS record 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainZoneCreate API http://support.netim.com/en/wiki/DomainZoneCreate
		 * @see StructZoneParam http://support.netim.com/en/wiki/StructZoneParam
		 */
		public function domainZoneCreate(string $domain, string $subdomain, string $type, string $value, array $options):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $subdomain;
			$params[] = $type;
			$params[] = $value;
			$params[] = $options;
			return $this->_launchCommand('domainZoneCreate', $params);
		}

		/**
		 * Updates a DNS record from the domain's zonefile
		 *
		 * @param	string	$domain name of the domain
		 * @param	string	$subdomain subdomain
		 * @param	string	$type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
		 * @param	string	$value current value of the DNS record
		 * @param	string	$newValue new value of the DNS record
		 * @param	array	$options StructZoneParam : settings of the new DNS record 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 */
		public function domainZoneUpdate(string $domain, string $subdomain, string $type, string $value, string $newValue, array $options): stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $subdomain;
			$params[] = $type;
			$params[] = $value;
			$params[] = $newValue;
			$params[] = $options;

			return $this->_launchCommand('domainZoneUpdate', $params);
		}

		/**
		 * Deletes a DNS record into the domain's zonefile 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com'
		 *	$subdomain = 'www';
		 *	$type = 'A';
		 *	$value = '192.168.0.1';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainZoneDelete($domain, $subdomain, $type, $value);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain name of the domain
		 * @param string $subdomain subdomain
		 * @param string $type type of DNS record. Accepted values are: 'A', 'AAAA', 'MX, 'CNAME', 'TXT', 'NS and 'SRV'
		 * @param string $value value of the new DNS record
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 * 
		 * @see domainZoneDelete API http://support.netim.com/en/wiki/DomainZoneDelete
		 */
		public function domainZoneDelete(string $domain, string $subdomain, string $type, string $value):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $subdomain;
			$params[] = $type;
			$params[] = $value;
			return $this->_launchCommand('domainZoneDelete', $params);
		}

		/**
		 * Resets the SOA record of a domain name 
		 *
		 * Example
		 *	```php
		 *	$domain = 'myDomain.com'
		 *	$ttl = 24;
		 *	$ttlUnit = 'H';
		 *	$refresh = 24;
		 *	$refreshUnit = 'H';
		 *	$retry = 24;
		 *	$retryUnit = 'H';
		 *	$expire = 24;
		 *	$expireUnit = 'H';
		 *	$minimum = 24;
		 *	$minimumUnit = 'H';
		 *	
		 *	try
		 *	{
		 *		$res = $client->domainZoneInitSoa($domain, $ttl, $ttlUnit, $refresh, $refreshUnit, $retry, $retryUnit, $expire, $expireUnit, $minimum, $minimumUnit);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 * 
		 * @param string $domain name of the domain
		 * @param int 	 $ttl time to live
		 * @param string $ttlUnit TTL unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
		 * @param int	 $refresh Refresh delay
		 * @param string $refreshUnit Refresh unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
		 * @param int	 $retry Retry delay
		 * @param string $retryUnit Retry unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
		 * @param int	 $expire Expire delay
		 * @param string $expireUnit Expire unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
		 * @param int	 $minimum Minimum delay
		 * @param string $minimumUnit Minimum unit. Accepted values are: 'S', 'M', 'H', 'D', 'W'
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 * 
		 * @see domainZoneInitSoa API http://support.netim.com/en/wiki/DomainZoneInitSoa
		 */
		public function domainZoneInitSoa(string $domain, int $ttl, string $ttlUnit, int $refresh, string $refreshUnit, int $retry, string $retryUnit, int $expire, string $expireUnit, int $minimum, string $minimumUnit):stdClass
		{
			$params[] = strtolower($domain);
			$params[] = $ttl;
			$params[] = $ttlUnit;
			$params[] = $refresh;
			$params[] = $refreshUnit;
			$params[] = $retry;
			$params[] = $retryUnit;
			$params[] = $expire;
			$params[] = $expireUnit;
			$params[] = $minimum;
			$params[] = $minimumUnit;

			return $this->_launchCommand('domainZoneInitSoa', $params);
		}

		/**
		 * Returns informations about a DNS zone
		 * 
		 * @param string 	$domain Domain name
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return Array
		 */
		public function domainZoneInfo(string $domain)
		{
			$params[] = $domain;
			return $this->_launchCommand('domainZoneInfo', $params);
		}

		/**
		 * Investigates the state of the domain name from the top to the bottom of the DNS tree.
		 *
		 * @param	string	$domain		Domain name
		 * @param	array	$filters	Filters to apply to the list
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	array
		 * 
		 */
		public function domainZoneCheck(string $domain, array $nameservers)
		{
			$params = array(
				$domain,
				$nameservers,
			);
			return $this->_launchCommand('domainZoneCheck', $params);
		}

		/**
		 * Creates an email address forwarded to recipients
		 *
		 * Example
		 *	```php
		 *	$mailBox = 'example@myDomain.com';
		 *	$recipients = 'address1@abc.com, address2@abc.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainMailFwdCreate($mailBox, $recipients);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $mailBox email adress (or * for a catch-all)
		 * @param string $recipients string list of email adresses (separated by commas)
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainMailFwdCreate API http://support.netim.com/en/wiki/DomainMailFwdCreate
		 */
		public function domainMailFwdCreate(string $mailBox, string $recipients):stdClass
		{
			$params[] = $mailBox;
			$params[] = $recipients;
			return $this->_launchCommand('domainMailFwdCreate', $params);
		}

		/**
		 * Deletes an email forward
		 *
		 * Example
		 *	```php
		 *	$mailBox = 'example@myDomain.com';
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainMailFwdDelete($mailBox);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $mailBox email adress 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainMailFwdDelete API http://support.netim.com/en/wiki/DomainMailFwdDelete
		 */
		public function domainMailFwdDelete(string $mailBox):stdClass
		{
			$params[] = strtolower($mailBox);
			return $this->_launchCommand('domainMailFwdDelete', $params);
		}

		/**
		 * Returns all email forwards for a domain name
		 * 
		 * @param string $domain Domain name
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return array An array of StructDomainMailFwdList
		 */
		public function domainMailFwdList(string $domain):array
		{
			$params[] = strtolower($domain);
			return $this->_launchCommand('domainMailFwdList', $params);
		}

		/**
		 * Creates a web forwarding 
		 *
		 * Example
		 *	```php
		 *	$fqdn = 'subdomain.myDomain.com';
		 *	$target = 'myDomain.com';
		 *	$type = 'DIRECT';
		 *	$options = $array('header'=>301, 'protocol'=>ftp, 'title'=>'', 'parking'=>'');
		 *	
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainWebFwdCreate($fqdn, $target, $type, $options);
		 *		//equivalent to $res = $client->domainWebFwdCreateTypeDirect($fqdn, $target, 301, 'ftp')
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $fqdn hostname (fully qualified domain name)
		 * @param string $target target of the web forwarding
		 * @param string $type type of the web forwarding. Accepted values are: "DIRECT", "IP", "MASKED" or "PARKING"
		 * @param array $options contains StructOptionsFwd : settings of the web forwarding. An array with keys: header, protocol, title and parking.
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainWebFwdCreate API http://support.netim.com/en/wiki/DomainWebFwdCreate
		 * @see StructOptionsFwd http://support.netim.com/en/wiki/StructOptionsFwd
		 */
		public function domainWebFwdCreate(string $fqdn, string $target, string $type, array $options):stdClass
		{
			$params[] = $fqdn;
			$params[] = $target;
			$params[] = strtoupper($type);
			$params[] = $options;
			return $this->_launchCommand('domainWebFwdCreate', $params);
		}

		/**
		 * Updates a web forwarding
		 *
		 * @param	string	$fqdn		Hostname (fully qualified domain name)
		 * @param	string	$target		Target of the web forwarding
		 * @param	string	$type		Type of the web forwarding: "DIRECT", "IP", "MASKED" or "PARKING"
		 * @param	array	$options	StructOptionsFwd: settings of the web forwarding (header, protocol, title, parking)
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 *
		 * @see StructOptionsFwd http://support.netim.com/en/wiki/StructOptionsFwd
		 */
		public function domainWebFwdUpdate(string $fqdn, string $target, string $type, array $options):stdClass
		{
			$params[] = $fqdn;
			$params[] = $target;
			$params[] = strtoupper($type);
			$params[] = $options;

			return $this->_launchCommand('domainWebFwdUpdate', $params);
		}

		/**
		 * Removes a web forwarding 
		 *
		 * Example
		 *	```php
		 *	$fqdn = 'subdomain.myDomain.com'
		 *	$res = null;
		 *	try
		 *	{
		 *		$res = $client->domainWebFwdDelete($fqdn);
		 *	}
		 *	catch (NetimAPIexception $exception)
		 *	{
		 *		//do something when operation had an error
		 *	}
		 *	//continue processing
		 *	```
		 *
		 * @param string $fqdn hostname, a fully qualified domain name
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainWebFwdDelete API http://support.netim.com/en/wiki/DomainWebFwdDelete
		 */
		public function domainWebFwdDelete(string $fqdn):stdClass
		{
			$params[] = $fqdn;
			return $this->_launchCommand('domainWebFwdDelete', $params);
		}

		/**
		 * Return all web forwarding of a domain name 
		 * 
		 * @param string $domain Domain name
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return array An array of StructDomainWebFwdList
		 *
		 */
		public function domainWebFwdList(string $domain):array
		{
			$params[] = $domain;
			return $this->_launchCommand('domainWebFwdList', $params);
		}

		/**
		 * Creates a SSL redirection 
		 *		
		 * @param string $prod certificate type 
		 * @param string $duration period of validity (in years)
		 * @param StructCSR $CSRInfo object containing informations about the CSR 
		 * @param string $validation validation method of the CSR (either by email or file) : 	"file"
		 *																						"email:admin@yourdomain.com"
		 *																						"email:postmaster@yourdomain.com,webmaster@yourdomain.com" 
		 *
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see domainWebFwdCreate API http://support.netim.com/en/wiki/DomainWebFwdCreate
		 * @see StructOptionsFwd http://support.netim.com/en/wiki/StructOptionsFwd
		 */
		public function sslCreate(string $prod, int $duration, array $CSRInfo, string $validation):stdClass
		{
			$params[] = $prod;
			$params[] = $duration;
			$params[] = $CSRInfo;
			$params[] = $validation;
			return $this->_launchCommand('sslCreate', $params);
		}

		/**
		 * Renew a SSL certificate for a new subscription period. 
		 *		
		 * @param string $IDSSL SSL certificate ID
		 * @param int $duration period of validity after the renewal (in years). Only the value 1 is valid
		 * 
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see sslRenew API http://support.netim.com/en/wiki/SslRenew
		 */
		public function sslRenew(string $IDSSL, int $duration): stdClass
		{
			$params[] = $IDSSL;
			$params[] = $duration;
			return $this->_launchCommand('sslRenew', $params);
		}

		/**
		 * Revokes a SSL Certificate. 
		 * 
		 * @param string $IDSSL SSL certificate ID
		 * 
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see sslRevoke API http://support.netim.com/en/wiki/SslRevoke
		 */
		public function sslRevoke(string $IDSSL):stdClass
		{
			$params[] = $IDSSL;
			return $this->_launchCommand('sslRevoke', $params);
		}

		/**
		 * Reissues a SSL Certificate. 
		 * 
		 * @param string $IDSSL SSL certificate ID
		 * @param StructCSR $CSRInfo Object containing informations about the CSR
		 * @param string $validation validation method of the CSR (either by email or file) : 	"file"
		 *																						"email:admin@yourdomain.com"
		 *																						"email:postmaster@yourdomain.com,webmaster@yourdomain.com"
		 * 
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see sslReIssue API http://support.netim.com/en/wiki/SslReIssue
		 * @see StructCSR http://support.netim.com/en/wiki/StructCSR
		 */
		public function sslReIssue(string $IDSSL, array $CSRInfo, string $validation): stdClass
		{
			$params[] = $IDSSL;
			$params[] = $CSRInfo;
			$params[] = $validation;
			return $this->_launchCommand('sslReIssue', $params);
		}

		/**
		 * Updates the settings of a SSL certificate. Currently, only the autorenew setting can be modified. 
		 * 
		 * @param string $IDSSL SSL certificate ID
		 * @param string $codePref Setting to be modified (auto_renew/to_be_renewed)
		 * @param string $value New value of the setting
		 * 
		 * @throws NetimAPIException
		 *
		 * @return StructOperationResponse giving information on the status of the operation
		 *
		 * @see sslSetPreference API http://support.netim.com/en/wiki/SslSetPreference
		 */
		public function sslSetPreference(string $IDSSL, string $codePref, string $value): stdClass
		{
			$params[] = $IDSSL;
			$params[] = $codePref;
			$params[] = $value;

			return $this->_launchCommand('sslSetPreference', $params);
		}

		/**
		 * Returns all the informations about a SSL certificate
		 * 
		 * @param string $IDSSL SSL certificate ID
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return StructSSLInfo containing the SSL certificate informations 
		 * 
		 * @see sslInfo API http://support.netim.com/en/wiki/SslInfo
		 */
		public function sslInfo(string $IDSSL): stdClass
		{
			$params[] = $IDSSL;

			return $this->_launchCommand('sslInfo', $params);
		}

		/**
		 * Returns informations about a DNS zone
		 * 
		 * @param	array	$filter	Filters to apply to the list
		 * 
		 * @throws NetimAPIException
		 * 
		 * @return Array
		 */
		public function sslList(array $filters = [])
		{
			$params = array(
				$filters
			);
			return $this->_launchCommand('sslList', $params);
		}

		/**
		 * Returns the list of all prices for SSL products
		 *
		 * @param	string	$product	SSL product ID
		 *
		 * @return	array
		 *
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/ssl-certificates/get-price-list
		 */
		public function sslPriceList(string $product = null)
		{
			$params = [];
			if ($product) {
				$params[] = $product;
			}

			return $this->_launchCommand('sslPriceList', $params);
		}
		
		/**
		 * Returns informations about a SSL product
		 * 
		 * @param	string	$product	SSL product
		 * 
		 * @throws	NetimAPIException
		 * 
		 * @return	array
		 */
		public function sslProductInfo(string $product)
		{
			$params[] = $product;
			return $this->_launchCommand('sslProductInfo', $params);
		}


		//helpers for domainSetPreference
		public function domainSetRegistrarLock($domain, $value)
		{
			return $this->domainSetPreference($domain, 'registrar_lock', $value);
		}
		public function domainSetWhoisPrivacy($domain, $value)
		{
			return $this->domainSetPreference($domain, 'whois_privacy', $value);
		}
		public function domainSetAutoRenew($domain, $value)
		{
			return $this->domainSetPreference($domain, 'auto_renew', $value);
		}
		public function domainSetTag($domain, $value)
		{
			return $this->domainSetPreference($domain, 'tag', $value);
		}
		public function domainSetNote($domain, $value)
		{
			return $this->domainSetPreference($domain, 'note', $value);
		}

		public function domainWebFwdCreateTypeDirect($fqdn, $target, $header, $protocol)
		{
			$options['title'] = '';
			$options['header'] = $header;
			$options['protocol'] = $protocol;
			$options['parking'] = '';
			return $this->domainWebFwdCreate($fqdn, $target, 'DIRECT', $options);
		}

		public function domainWebFwdCreateTypeMasked($fqdn, $target, $protocol, $title)
		{
			$options['title'] = $title;
			$options['header'] = '';
			$options['protocol'] = $protocol;
			$options['parking'] = '';
			return $this->domainWebFwdCreate($fqdn, $target, 'MASKED', $options);
		}

		public function domainWebFwdCreateTypeIP($fqdn, $target)
		{
			$options['title'] = '';
			$options['header'] = '';
			$options['protocol'] = '';
			$options['parking'] = '';
			return $this->domainWebFwdCreate($fqdn, $target, 'IP', $options);
		}

		public function domainWebFwdCreateTypeParking($fqdn, $parking)
		{
			$options['title'] = '';
			$options['header'] = '';
			$options['protocol'] = '';
			$options['parking'] = $parking;
			return $this->domainWebFwdCreate($fqdn, '', 'PARKING', $options);
		}


		/**
		 * BRAND PROTECTIONS
		 */

		/**
		 * Create a new brand protection
		 *
		 * @param	string	$label		Brand main label
		 * @param	string	$product	Brand protection product ID
		 * @param	integer	$duration	Period of validity in years
		 * @param	string	$idOwner	ID of the owner contact
		 * @param	string	$type		Brand’s type
		 * @param	array	$infos		Array of strings containing brand datas
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/create-protection
		 */
		public function brandProtectionCreate(string $label, string $product, int $duration, string $idOwner, string $type, array $infos = [])
		{
			$params = [
				$label,
				$product,
				$duration,
				$idOwner,
				$type,
				$infos
			];
			return $this->_launchCommand('brandProtectionCreate', $params);
		}

		/**
		 * Return all information about a brand protection
		 *
		 * @param	string	$IDBP	Brand protection ID
		 *
		 * @return	StructBrandProtectionInfo
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/get-protection-information
		 */
		public function brandProtectionInfo(string $IDBP)
		{
			$params = [
				$IDBP,
			];
			return $this->_launchCommand('brandProtectionInfo', $params);
		}

		/**
		 * Return all information about a brand protection product
		 *
		 * @param	string	$product	Brand protection product ID
		 *
		 * @return	array
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/get-product-information
		 */
		public function brandProtectionProductInfo(string $product)
		{
			$params = [
				$product,
			];
			return $this->_launchCommand('brandProtectionProductInfo', $params);
		}

		
		/**
		 * Returns the list of all prices for brand protection products
		 *
		 * @param	string	$product	Brand protection product ID
		 *
		 * @return	array
		 *
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/get-price-list
		 */
		public function brandProtectionPriceList(string $product = null)
		{
			$params = [];
			if ($product) {
				$params[] = $product;
			}

			return $this->_launchCommand('brandProtectionPriceList', $params);
		}

		/**
		 * Request the transfer of a brand protection to Netim
		 *
		 * @param	string	$reg_id 	Brand protection ID at the actual registry
		 * @param	string	$label		Brand main label
		 * @param	string	$product	Brand protection product ID
		 * @param	string	$authID		Brand protection authorization code
		 * @param	string	$idOwner	ID of the owner contact
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/transfer-protection
		 */
		public function brandProtectionTransfer(string $reg_id, string $label, string $product, string $authID, string $idOwner)
		{
			$params = [
				$reg_id,
				$label,
				$product,
				$authID,
				$idOwner,
			];
			return $this->_launchCommand('brandProtectionTransfer', $params);
		}

		/**
		 * List brand protections matching filters
		 *
		 * @param	array	$filters	Search filters
		 *
		 * @return	array
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/get-protection-list
		 */
		public function brandProtectionList(array $filters = [])
		{
			$params = [
				$filters,
			];
			return $this->_launchCommand('brandProtectionList', $params);
		}

		/**
		 * Request the transfer of the ownership to another party
		 *
		 * @param	string	$id 		Brand protection ID
		 * @param	string	$idOwner	ID of the owner contact
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/change-owner-of-protection
		 */
		public function brandProtectionTransferOwner(string $id, string $idOwner)
		{
			$params = [
				$id,
				$idOwner,
			];
			return $this->_launchCommand('brandProtectionTransferOwner', $params);
		}

		/**
		 * Renew a brand protection for a new period
		 *
		 * @param	string	$id 		Brand protection ID
		 * @param	int		$duration	Duration in years.
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/change-owner-of-protection
		 */
		public function brandProtectionRenew(string $id, int $duration)
		{
			$params = [
				$id,
				$duration,
			];
			return $this->_launchCommand('brandProtectionRenew', $params);
		}

		/**
		 * Delete a brand protection
		 *
		 * @param	string	$id		Brand protection ID
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/delete-protection
		 */
		public function brandProtectionDelete(string $id)
		{
			$params = [
				$id,
			];
			return $this->_launchCommand('brandProtectionDelete', $params);
		}

		/**
		 * Set brand protection preference
		 *
		 * @param	string	$IDBP 		Brand protection ID
		 * @param	string	$codePref	Preference to update ("auto_renew", "to_be_renewed")
		 * @param	string	$enable		"0" to disable, "1" to enable.
		 *
		 * @return	StructOperationResponse
		 * 
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/set-auto-renew
		 * @link	https://support.netim.com/en/docs/api-soap-3-0/brand-protections/set-to-be-renewed
		 */
		public function brandProtectionSetPreference(string $IDBP, string $codePref, string $enable)
		{
			$params = [
				$IDBP,
				$codePref,
				$enable,
			];
			return $this->_launchCommand('brandProtectionSetPreference', $params);
		}

		/**
		 * SECONDARY MARKET
		 */

		/**
		 * Returns information about a domain listed on a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 * @param	string	$domain		Domain name
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketInfo(string $plateform, string $domain):stdClass
		{
			$params = [
				$plateform,
				strtolower($domain),
			];

			return $this->_launchCommand('SecMarketInfo', $params);
		}

		/**
		 * Lists a domain on a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 * @param	string	$domain		Domain name
		 * @param	array	$params		Listing parameters (e.g. price)
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketAdd(string $plateform, string $domain, array $params):stdClass
		{
			$commandParams = [
				$plateform,
				strtolower($domain),
				$params,
			];

			return $this->_launchCommand('SecMarketAdd', $commandParams);
		}

		/**
		 * Unlinks a domain from a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 * @param	string	$domain		Domain name
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketUnlink(string $plateform, string $domain):stdClass
		{
			$params = [
				$plateform,
				strtolower($domain),
			];

			return $this->_launchCommand('SecMarketUnlink', $params);
		}

		/**
		 * Updates the price of a domain listed on a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 * @param	string	$domain		Domain name
		 * @param	array	$params		Pricing parameters
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketSetPrice(string $plateform, string $domain, array $params):stdClass
		{
			$commandParams = [
				$plateform,
				strtolower($domain),
				$params,
			];

			return $this->_launchCommand('SecMarketSetPrice', $commandParams);
		}

		/**
		 * Synchronizes the account with a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketSynchro(string $plateform):stdClass
		{
			$params = [
				$plateform,
			];

			return $this->_launchCommand('SecMarketSynchro', $params);
		}

		/**
		 * Removes a domain listed on a secondary market platform
		 *
		 * @param	string	$plateform	Platform name
		 * @param	string	$domain		Domain name
		 *
		 * @throws	NetimAPIException
		 *
		 * @return	StructOperationResponse
		 */
		public function SecMarketRemove(string $plateform, string $domain):stdClass
		{
			$params = [
				$plateform,
				strtolower($domain),
			];

			return $this->_launchCommand('SecMarketRemove', $params);
		}

	}
}
